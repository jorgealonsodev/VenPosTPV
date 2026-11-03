<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Device\DeviceContext;
use VenPosTPV\Service\Device\DeviceCookie;
use VenPosTPV\Service\Device\DeviceRegistry;
use VenPosTPV\Service\Device\TillHasDeviceException;
use VenPosTPV\Service\Device\UnknownTillException;

/**
 * This computer and its till (S10): link screen data, linking by an administrator,
 * and putting the cookie back from the token mirrored in localStorage.
 *
 * The POSTs only accept a JSON body: with the SameSite=Lax session cookie this is
 * what keeps another site from triggering them (a cross-site form cannot send
 * application/json without a CORS preflight).
 */
#[AsController]
final class DeviceController
{
    public function __construct(
        private readonly DeviceRegistry $devices,
        private readonly DeviceCookie $cookie,
    ) {
    }

    #[Route('/device', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return $this->unauthorized();
        }

        $token = DeviceCookie::read($request);
        $terminalId = $this->devices->terminalForToken($token);
        $data = ['linked' => $terminalId !== null, 'terminal_id' => $terminalId, 'role' => (string) $session->get('role', 'cashier')];
        if ($this->isAdmin($request)) {
            $data['tills'] = $this->devices->listTills($this->shopId($request), $token);
        }

        return new JsonResponse(['data' => $data]);
    }

    #[Route('/device/link', methods: ['POST'])]
    public function link(Request $request): JsonResponse
    {
        if ($request->getSession()->get('user_id') === null) {
            return $this->unauthorized();
        }
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['error' => 'Solo un administrador puede asignar la caja a este equipo'], Response::HTTP_FORBIDDEN);
        }
        $body = $this->jsonBody($request);
        if ($body === null) {
            return $this->notJson();
        }

        $terminalId = (int) ($body['terminal_id'] ?? 0);
        if ($terminalId <= 0) {
            return new JsonResponse(['error' => 'Elige una caja'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $token = $this->devices->link(
                $terminalId,
                (int) $request->getSession()->get('user_id'),
                ($body['move'] ?? false) === true,
                DeviceCookie::read($request)
            );
        } catch (TillHasDeviceException $e) {
            return new JsonResponse(['code' => 'till_has_device', 'error' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (UnknownTillException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        // The token goes back once so the page can mirror it in localStorage.
        $response = new JsonResponse(['data' => ['terminal_id' => $terminalId, 'token' => $token]]);
        $response->headers->setCookie($this->cookie->create($token, $request));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/device/restore', methods: ['POST'])]
    public function restore(Request $request): JsonResponse
    {
        if ($request->getSession()->get('user_id') === null) {
            return $this->unauthorized();
        }
        $body = $this->jsonBody($request);
        if ($body === null) {
            return $this->notJson();
        }

        $token = \is_string($body['token'] ?? null) ? $body['token'] : null;
        $terminalId = $this->devices->terminalForToken($token);
        if ($token === null || $terminalId === null) {
            return new JsonResponse(['code' => DeviceContext::NOT_LINKED, 'error' => 'Este equipo no tiene caja asignada'], Response::HTTP_NOT_FOUND);
        }

        $response = new JsonResponse(['data' => ['terminal_id' => $terminalId]]);
        $response->headers->setCookie($this->cookie->create($token, $request));

        return $response;
    }

    /** @return array<string, mixed>|null null when the request is not a JSON object */
    private function jsonBody(Request $request): ?array
    {
        if ($request->getContentTypeFormat() !== 'json') {
            return null;
        }
        $body = json_decode((string) $request->getContent(), true);

        return \is_array($body) ? $body : null;
    }

    private function isAdmin(Request $request): bool
    {
        return $request->getSession()->get('role') === 'admin';
    }

    private function shopId(Request $request): int
    {
        $shopId = (int) ($request->getSession()->get('shop_id') ?? 0);

        return $shopId > 0 ? $shopId : 1;
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
    }

    private function notJson(): JsonResponse
    {
        return new JsonResponse(['error' => 'Se espera un cuerpo JSON'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }
}
