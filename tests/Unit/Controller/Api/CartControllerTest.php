<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\CartController;
use VenPosTPV\Service\Cart\CartService;
use VenPosTPV\Service\Catalog\ProductSearchService;
use VenPosTPV\Tests\Support\SessionRequest;

class CartControllerTest extends TestCase
{
    public function testGetReturns401WithoutSession(): void
    {
        $controller = new CartController($this->createMock(CartService::class), $this->createMock(ProductSearchService::class));
        $response = $controller->get(SessionRequest::create());

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testGetReturnsCartWithSession(): void
    {
        $cartService = $this->createMock(CartService::class);
        $cartService->expects($this->once())->method('getCart')->willReturn(['lines' => [], 'total' => 0]);

        $controller = new CartController($cartService, $this->createMock(ProductSearchService::class));
        $response = $controller->get(SessionRequest::create([], ['user_id' => 1]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('lines', $data['data']);
    }

    public function testAddItemReturns401WithoutSession(): void
    {
        $controller = new CartController($this->createMock(CartService::class), $this->createMock(ProductSearchService::class));
        $response = $controller->addItem(SessionRequest::json(['product_id' => 1]));

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testAddItemReturns400OnMissingProductId(): void
    {
        $controller = new CartController($this->createMock(CartService::class), $this->createMock(ProductSearchService::class));
        $response = $controller->addItem(SessionRequest::json([], ['user_id' => 1]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testAddItemReturns404ForMissingProduct(): void
    {
        $productSearch = $this->createMock(ProductSearchService::class);
        $productSearch->expects($this->once())->method('findById')->with(999)->willReturn(null);

        $controller = new CartController($this->createMock(CartService::class), $productSearch);
        $response = $controller->addItem(SessionRequest::json(['product_id' => 999], ['user_id' => 1]));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testAddItemReturns400ForOutOfStock(): void
    {
        $productSearch = $this->createMock(ProductSearchService::class);
        $productSearch->expects($this->once())->method('findById')->with(1)->willReturn(['id' => 1, 'name' => 'Test', 'price' => 10, 'stock' => 0]);

        $controller = new CartController($this->createMock(CartService::class), $productSearch);
        $response = $controller->addItem(SessionRequest::json(['product_id' => 1], ['user_id' => 1]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testAddItemAddsProductToCart(): void
    {
        $cartService = $this->createMock(CartService::class);
        $cartService->expects($this->once())->method('addLine')->willReturn(['lines' => [['product_id' => 1, 'qty' => 1]], 'total' => 10]);
        $productSearch = $this->createMock(ProductSearchService::class);
        $productSearch->expects($this->once())->method('findById')->with(1)->willReturn(['id' => 1, 'name' => 'Test', 'price' => 10, 'stock' => 5]);

        $response = (new CartController($cartService, $productSearch))->addItem(SessionRequest::json(['product_id' => 1], ['user_id' => 1]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $data['data']['lines']);
    }

    public function testRemoveItemReturns401WithoutSession(): void
    {
        $controller = new CartController($this->createMock(CartService::class), $this->createMock(ProductSearchService::class));
        $response = $controller->removeItem(SessionRequest::create(), 'line_123');

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testClearReturns401WithoutSession(): void
    {
        $controller = new CartController($this->createMock(CartService::class), $this->createMock(ProductSearchService::class));
        $response = $controller->clear(SessionRequest::create());

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testApplyDiscountReturns401WithoutSession(): void
    {
        $controller = new CartController($this->createMock(CartService::class), $this->createMock(ProductSearchService::class));
        $response = $controller->applyDiscount(SessionRequest::json(['type' => 'pct', 'value' => 10]));

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testApplyDiscountAppliesDiscount(): void
    {
        $cartService = $this->createMock(CartService::class);
        $cartService->expects($this->once())->method('applyDiscount')->with('pct', 10.0)->willReturn(['lines' => [], 'discount' => ['type' => 'pct', 'value' => 10], 'total' => 90]);

        $response = (new CartController($cartService, $this->createMock(ProductSearchService::class)))->applyDiscount(
            SessionRequest::json(['type' => 'pct', 'value' => 10], ['user_id' => 1])
        );

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }
}
