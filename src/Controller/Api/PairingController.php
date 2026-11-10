<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Install\EnvFileUpdater;
use VenPosTPV\Service\Install\InstallException;
use VenPosTPV\Service\PrestaShop\PairingClient;
use VenPosTPV\Service\PrestaShop\PairingException;

/**
 * Ajustes → Acerca de → «Volver a emparejar con la tienda» (admins only): the
 * same pairing as the web installer, for an installed TPV. The shop's answer
 * replaces PS_SHOP_URL, PS_API_KEY, SSO_SHARED_SECRET, PS_DEFAULT_SHOP,
 * PS_DEFAULT_CURRENCY and PS_SHOP_TIMEZONE in .env (EnvFileUpdater: atomic, with
 * a 0600 backup of the previous file). No answer carries a key or a secret.
 */
#[AsController]
final class PairingController
{
    public function __construct(
        private readonly PairingClient $client,
        private readonly EnvFileUpdater $envUpdater,
        #[Autowire('%env(default::TPV_URL)%')] private readonly ?string $tpvUrl,
        #[Autowire('%env(default::PS_SHOP_URL)%')] private readonly ?string $shopUrl,
        #[Autowire('%kernel.environment%')] private readonly string $appEnv = 'prod',
        /** @var (\Closure(): array<string, string>)|null the process environment; tests pass their own */
        private readonly ?\Closure $environment = null,
    ) {
    }

    #[Route('/settings/prestashop', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        if ($request->getSession()->get('role') !== 'admin') {
            return self::denied();
        }

        return self::json(['data' => ['shop_url' => (string) $this->shopUrl, 'tpv_url' => (string) $this->tpvUrl]], Response::HTTP_OK);
    }

    #[Route('/settings/prestashop/pair', methods: ['POST'])]
    public function pair(Request $request): JsonResponse
    {
        if ($request->getSession()->get('role') !== 'admin') {
            return self::denied();
        }
        $tpvUrl = rtrim(trim((string) $this->tpvUrl), '/');
        if ($tpvUrl === '') {
            return self::json(['error' => 'Falta la dirección del TPV (TPV_URL) en el archivo .env: escríbela ahí antes de emparejar.'], Response::HTTP_CONFLICT);
        }

        $body = json_decode($request->getContent(), true);
        $body = \is_array($body) ? $body : [];
        $code = \is_string($body['code'] ?? null) ? $body['code'] : '';
        $shopUrl = \is_string($body['shop_url'] ?? null) && trim($body['shop_url']) !== '' ? $body['shop_url'] : (string) $this->shopUrl;

        try {
            $result = $this->client->pair($shopUrl, $code, $tpvUrl);
            $backup = $this->envUpdater->update($result->envValues(), ($this->environment ?? EnvFileUpdater::processEnvironment(...))(), $this->appEnv);
        } catch (PairingException $e) {
            return self::json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (InstallException $e) {
            return self::json(['error' => 'La tienda ha dado las claves, pero no se pudieron guardar: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return self::json(['data' => [
            'shop_url' => $result->shopUrl,
            'shop_id' => $result->shopId,
            'module_version' => $result->moduleVersion,
            'backup' => $backup,
        ]], Response::HTTP_OK);
    }

    private static function denied(): JsonResponse
    {
        return self::json(['error' => 'Acceso denegado — solo administradores'], Response::HTTP_FORBIDDEN);
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data, int $status): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
