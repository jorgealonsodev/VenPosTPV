<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\ProductController;
use VenPosTPV\Service\Catalog\ProductSearchService;

class ProductControllerTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        session_start();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function testSearchReturns401WithoutSession(): void
    {
        $searchService = $this->createMock(ProductSearchService::class);
        $controller = new ProductController($searchService);
        $request = new Request(['q' => 'test']);
        $response = $controller->search($request);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Unauthorized', $data['error']);
    }

    public function testSearchReturnsResultsWithSession(): void
    {
        $_SESSION['user_id'] = 1;

        $searchService = $this->createMock(ProductSearchService::class);
        $searchService->expects($this->once())
            ->method('search')
            ->with('hummingbird', null, 50)
            ->willReturn([
                ['id' => 1, 'name' => 'Hummingbird T-Shirt', 'price' => 29.99],
            ]);

        $controller = new ProductController($searchService);
        $request = new Request(['q' => 'hummingbird']);
        $response = $controller->search($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $data['data']);
        $this->assertSame('Hummingbird T-Shirt', $data['data'][0]['name']);
    }

    public function testCategoriesReturns401WithoutSession(): void
    {
        $searchService = $this->createMock(ProductSearchService::class);
        $controller = new ProductController($searchService);
        $response = $controller->categories();

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testCategoriesReturnsActiveCategories(): void
    {
        $_SESSION['user_id'] = 1;

        $searchService = $this->createMock(ProductSearchService::class);
        $searchService->expects($this->once())
            ->method('getActiveCategories')
            ->willReturn([
                ['id' => 1, 'name' => 'Ropa', 'id_parent' => 0, 'active' => true],
            ]);

        $controller = new ProductController($searchService);
        $response = $controller->categories();

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $data['data']);
    }

    public function testProductReturns401WithoutSession(): void
    {
        $searchService = $this->createMock(ProductSearchService::class);
        $controller = new ProductController($searchService);
        $response = $controller->product(1);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testProductReturnsProductDetail(): void
    {
        $_SESSION['user_id'] = 1;

        $searchService = $this->createMock(ProductSearchService::class);
        $searchService->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn([
                'id' => 1,
                'name' => 'Test Product',
                'price' => 19.99,
                'stock' => 10,
                'combinations' => [],
            ]);

        $controller = new ProductController($searchService);
        $response = $controller->product(1);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Test Product', $data['data']['name']);
    }

    public function testProductReturns404ForMissingProduct(): void
    {
        $_SESSION['user_id'] = 1;

        $searchService = $this->createMock(ProductSearchService::class);
        $searchService->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $controller = new ProductController($searchService);
        $response = $controller->product(999);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }
}
