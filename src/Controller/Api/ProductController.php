<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Catalog\ProductSearchService;

#[AsController]
class ProductController
{
    public function __construct(private readonly ProductSearchService $productSearch)
    {
    }

    #[Route('/catalog/search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $q = $request->query->get('q');
        $category = $request->query->get('category');
        $limit = (int) $request->query->get('limit', '50');

        $results = $this->productSearch->search(
            is_string($q) ? $q : null,
            is_numeric($category) ? (int) $category : null,
            max(1, min(100, $limit))
        );

        return new JsonResponse(['data' => $results]);
    }

    #[Route('/catalog/categories', methods: ['GET'])]
    public function categories(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $results = $this->productSearch->getActiveCategories();

        return new JsonResponse(['data' => $results]);
    }

    #[Route('/catalog/product/{id}', methods: ['GET'])]
    public function product(Request $request, int $id): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $product = $this->productSearch->findById($id);

        if ($product === null) {
            return new JsonResponse(['error' => 'Product not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['data' => $product]);
    }
}
