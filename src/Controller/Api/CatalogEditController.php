<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Catalog\CatalogEditService;
use VenPosTPV\Service\Catalog\Exception\CatalogEditException;

/**
 * «Modo edición» of the sale screen, for administrators only: the stock (written
 * to PrestaShop, then locally) and the local EAN and reference (never sent to
 * PrestaShop) of a product (attribute 0) or of one of its combinations.
 */
#[AsController]
class CatalogEditController
{
    private const ITEM = '/catalog/edit/{productId}/{attributeId}';
    private const IDS = ['productId' => '\d+', 'attributeId' => '\d+'];

    public function __construct(private readonly CatalogEditService $edits)
    {
    }

    #[Route(self::ITEM, methods: ['GET'], requirements: self::IDS)]
    public function item(Request $request, int $productId, int $attributeId): JsonResponse
    {
        return $this->run($request, false, fn (): array => $this->edits->item($productId, $attributeId));
    }

    #[Route(self::ITEM . '/override', methods: ['PUT'], requirements: self::IDS)]
    public function saveOverride(Request $request, int $productId, int $attributeId): JsonResponse
    {
        return $this->run($request, true, function (array $body, ?int $userId, ?int $terminalId) use ($request, $productId, $attributeId): array {
            return $this->edits->saveOverride($productId, $attributeId, $body['ean13'] ?? null, $body['reference'] ?? null, $userId, $terminalId, $request->getClientIp());
        });
    }

    /** «Restablecer»: the synced values apply again. */
    #[Route(self::ITEM . '/override', methods: ['DELETE'], requirements: self::IDS)]
    public function resetOverride(Request $request, int $productId, int $attributeId): JsonResponse
    {
        return $this->run($request, false, function (array $body, ?int $userId, ?int $terminalId) use ($request, $productId, $attributeId): array {
            return $this->edits->resetOverride($productId, $attributeId, $userId, $terminalId, $request->getClientIp());
        });
    }

    #[Route(self::ITEM . '/stock', methods: ['PUT'], requirements: self::IDS)]
    public function setStock(Request $request, int $productId, int $attributeId): JsonResponse
    {
        return $this->run($request, true, function (array $body, ?int $userId, ?int $terminalId) use ($request, $productId, $attributeId): array {
            return $this->edits->setStock($productId, $attributeId, $body['quantity'] ?? null, $userId, $terminalId, $request->getClientIp());
        });
    }

    /** Body {price (with VAT), vat_rate}: saved in PrestaShop, then in the local catalog. */
    #[Route(self::ITEM . '/price', methods: ['PUT'], requirements: self::IDS)]
    public function setPrice(Request $request, int $productId, int $attributeId): JsonResponse
    {
        return $this->run($request, true, function (array $body, ?int $userId, ?int $terminalId) use ($request, $productId, $attributeId): array {
            return $this->edits->setPrice($productId, $attributeId, $body['price'] ?? null, $body['vat_rate'] ?? null, $userId, $terminalId, $request->getClientIp());
        });
    }

    /** @param \Closure(array<mixed>, ?int, ?int): array<string, mixed> $action */
    private function run(Request $request, bool $jsonBody, \Closure $action): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return self::noStore(new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED));
        }
        if ($session->get('role') !== 'admin') {
            return self::noStore(new JsonResponse(['error' => 'Acceso denegado — solo administradores'], Response::HTTP_FORBIDDEN));
        }
        if ($jsonBody && $request->getContentTypeFormat() !== 'json') {
            return self::noStore(new JsonResponse(['error' => 'Se espera un cuerpo JSON'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE));
        }
        $body = $jsonBody ? json_decode((string) $request->getContent(), true) : [];

        try {
            $data = $action(\is_array($body) ? $body : [], self::positiveInt($session->get('user_id')), self::positiveInt($session->get('terminal_id')));
        } catch (CatalogEditException $e) {
            return self::noStore(new JsonResponse(['error' => $e->getMessage(), 'code' => $e->errorCode], $e->status));
        }

        return self::noStore(new JsonResponse(['data' => $data]));
    }

    private static function positiveInt(mixed $value): ?int
    {
        $int = filter_var($value, \FILTER_VALIDATE_INT);

        return \is_int($int) && $int > 0 ? $int : null;
    }

    private static function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
