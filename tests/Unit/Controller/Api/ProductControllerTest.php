<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\ProductController;
use VenPosTPV\Service\Catalog\ProductSearchService;
use VenPosTPV\Tests\Support\SessionRequest;

class ProductControllerTest extends TestCase
{
    public function testSearchReturns401WithoutSession(): void
    {
        $response = (new ProductController($this->createMock(ProductSearchService::class)))->search(SessionRequest::create(['q' => 'test']));

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Unauthorized', $data['error']);
    }

    public function testSearchReturnsResultsWithSession(): void
    {
        $searchService = $this->createMock(ProductSearchService::class);
        $searchService->expects($this->once())->method('search')->with('hummingbird', null, 50)->willReturn([
            ['id' => 1, 'name' => 'Hummingbird T-Shirt', 'price' => 29.99],
        ]);

        $response = (new ProductController($searchService))->search(SessionRequest::create(['q' => 'hummingbird'], ['user_id' => 1]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $data['data']);
        $this->assertSame('Hummingbird T-Shirt', $data['data'][0]['name']);
    }

    public function testCategoriesReturns401WithoutSession(): void
    {
        $response = (new ProductController($this->createMock(ProductSearchService::class)))->categories(SessionRequest::create());
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testCategoriesReturnsActiveCategories(): void
    {
        $searchService = $this->createMock(ProductSearchService::class);
        $searchService->expects($this->once())->method('getActiveCategories')->willReturn([
            ['id' => 1, 'name' => 'Ropa', 'id_parent' => 0, 'active' => true],
        ]);

        $response = (new ProductController($searchService))->categories(SessionRequest::create([], ['user_id' => 1]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $data['data']);
    }

    public function testProductReturns401WithoutSession(): void
    {
        $response = (new ProductController($this->createMock(ProductSearchService::class)))->product(SessionRequest::create(), 1);
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testProductReturnsProductDetail(): void
    {
        $searchService = $this->createMock(ProductSearchService::class);
        $searchService->expects($this->once())->method('findById')->with(1)->willReturn([
            'id' => 1, 'name' => 'Test Product', 'price' => 19.99, 'stock' => 10, 'combinations' => [],
        ]);

        $response = (new ProductController($searchService))->product(SessionRequest::create([], ['user_id' => 1]), 1);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Test Product', $data['data']['name']);
    }

    public function testProductReturns404ForMissingProduct(): void
    {
        $searchService = $this->createMock(ProductSearchService::class);
        $searchService->expects($this->once())->method('findById')->with(999)->willReturn(null);

        $response = (new ProductController($searchService))->product(SessionRequest::create([], ['user_id' => 1]), 999);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }
}
