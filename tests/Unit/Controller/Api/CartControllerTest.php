<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\CartController;
use VenPosTPV\Service\Cart\CartService;
use VenPosTPV\Service\Catalog\ProductSearchService;

class CartControllerTest extends TestCase
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

    public function testGetReturns401WithoutSession(): void
    {
        $cartService = $this->createMock(CartService::class);
        $productSearch = $this->createMock(ProductSearchService::class);
        $controller = new CartController($cartService, $productSearch);
        $response = $controller->get();

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testGetReturnsCartWithSession(): void
    {
        $_SESSION['user_id'] = 1;

        $cartService = $this->createMock(CartService::class);
        $cartService->expects($this->once())
            ->method('getCart')
            ->willReturn(['lines' => [], 'total' => 0]);

        $productSearch = $this->createMock(ProductSearchService::class);
        $controller = new CartController($cartService, $productSearch);
        $response = $controller->get();

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('lines', $data['data']);
    }

    public function testAddItemReturns401WithoutSession(): void
    {
        $cartService = $this->createMock(CartService::class);
        $productSearch = $this->createMock(ProductSearchService::class);
        $controller = new CartController($cartService, $productSearch);
        $request = new Request([], [], [], [], [], [], json_encode(['product_id' => 1]));
        $response = $controller->addItem($request);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testAddItemReturns400OnMissingProductId(): void
    {
        $_SESSION['user_id'] = 1;

        $cartService = $this->createMock(CartService::class);
        $productSearch = $this->createMock(ProductSearchService::class);
        $controller = new CartController($cartService, $productSearch);
        $request = new Request([], [], [], [], [], [], json_encode([]));
        $response = $controller->addItem($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testAddItemReturns404ForMissingProduct(): void
    {
        $_SESSION['user_id'] = 1;

        $cartService = $this->createMock(CartService::class);
        $productSearch = $this->createMock(ProductSearchService::class);
        $productSearch->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $controller = new CartController($cartService, $productSearch);
        $request = new Request([], [], [], [], [], [], json_encode(['product_id' => 999]));
        $response = $controller->addItem($request);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testAddItemReturns400ForOutOfStock(): void
    {
        $_SESSION['user_id'] = 1;

        $cartService = $this->createMock(CartService::class);
        $productSearch = $this->createMock(ProductSearchService::class);
        $productSearch->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn(['id' => 1, 'name' => 'Test', 'price' => 10, 'stock' => 0]);

        $controller = new CartController($cartService, $productSearch);
        $request = new Request([], [], [], [], [], [], json_encode(['product_id' => 1]));
        $response = $controller->addItem($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testAddItemAddsProductToCart(): void
    {
        $_SESSION['user_id'] = 1;

        $cartService = $this->createMock(CartService::class);
        $cartService->expects($this->once())
            ->method('addLine')
            ->willReturn(['lines' => [['product_id' => 1, 'qty' => 1]], 'total' => 10]);

        $productSearch = $this->createMock(ProductSearchService::class);
        $productSearch->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn(['id' => 1, 'name' => 'Test', 'price' => 10, 'stock' => 5]);

        $controller = new CartController($cartService, $productSearch);
        $request = new Request([], [], [], [], [], [], json_encode(['product_id' => 1]));
        $response = $controller->addItem($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $data['data']['lines']);
    }

    public function testRemoveItemReturns401WithoutSession(): void
    {
        $cartService = $this->createMock(CartService::class);
        $productSearch = $this->createMock(ProductSearchService::class);
        $controller = new CartController($cartService, $productSearch);
        $response = $controller->removeItem('line_123');

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testClearReturns401WithoutSession(): void
    {
        $cartService = $this->createMock(CartService::class);
        $productSearch = $this->createMock(ProductSearchService::class);
        $controller = new CartController($cartService, $productSearch);
        $response = $controller->clear();

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testApplyDiscountReturns401WithoutSession(): void
    {
        $cartService = $this->createMock(CartService::class);
        $productSearch = $this->createMock(ProductSearchService::class);
        $controller = new CartController($cartService, $productSearch);
        $request = new Request([], [], [], [], [], [], json_encode(['type' => 'pct', 'value' => 10]));
        $response = $controller->applyDiscount($request);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testApplyDiscountAppliesDiscount(): void
    {
        $_SESSION['user_id'] = 1;

        $cartService = $this->createMock(CartService::class);
        $cartService->expects($this->once())
            ->method('applyDiscount')
            ->with('pct', 10.0)
            ->willReturn(['lines' => [], 'discount' => ['type' => 'pct', 'value' => 10], 'total' => 90]);

        $productSearch = $this->createMock(ProductSearchService::class);
        $controller = new CartController($cartService, $productSearch);
        $request = new Request([], [], [], [], [], [], json_encode(['type' => 'pct', 'value' => 10]));
        $response = $controller->applyDiscount($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }
}
