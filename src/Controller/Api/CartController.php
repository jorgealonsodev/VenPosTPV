<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Cart\CartService;
use VenPosTPV\Service\Catalog\ProductSearchService;

#[AsController]
class CartController
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly ProductSearchService $productSearch,
    ) {
    }

    #[Route('/cart', methods: ['GET'])]
    public function get(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse(['data' => $this->cartService->getCart()]);
    }

    #[Route('/cart/items', methods: ['POST'])]
    public function addItem(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body) || !isset($body['product_id'])) {
            return new JsonResponse(['error' => 'Missing product_id'], Response::HTTP_BAD_REQUEST);
        }

        $productId = (int) $body['product_id'];
        $qty = isset($body['quantity']) ? (int) $body['quantity'] : 1;

        $product = $this->productSearch->findById($productId);
        if ($product === null) {
            return new JsonResponse(['error' => 'Product not found'], Response::HTTP_NOT_FOUND);
        }

        if ($product['stock'] <= 0) {
            return new JsonResponse(['error' => 'Out of stock'], Response::HTTP_BAD_REQUEST);
        }

        $cart = $this->cartService->addLine($product, $qty);

        return new JsonResponse(['data' => $cart]);
    }

    #[Route('/cart/items/{lineId}', methods: ['DELETE'])]
    public function removeItem(Request $request, string $lineId): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse(['data' => $this->cartService->removeLine($lineId)]);
    }

    #[Route('/cart/clear', methods: ['POST'])]
    public function clear(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse(['data' => $this->cartService->clear()]);
    }

    #[Route('/cart/apply-discount', methods: ['POST'])]
    public function applyDiscount(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body) || !isset($body['type'], $body['value'])) {
            return new JsonResponse(['error' => 'Missing type or value'], Response::HTTP_BAD_REQUEST);
        }

        $type = in_array($body['type'], ['pct', 'amt'], true) ? $body['type'] : 'pct';
        $value = (float) $body['value'];

        return new JsonResponse(['data' => $this->cartService->applyDiscount($type, $value)]);
    }
}
