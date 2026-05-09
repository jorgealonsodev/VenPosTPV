<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\CheckoutController;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Checkout\CheckoutService;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;

final class CheckoutControllerTest extends TestCase
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

    public function testCheckoutReturns401WithoutSession(): void
    {
        $controller = new CheckoutController(
            $this->createMock(CheckoutService::class),
            $this->createMock(CashSessionService::class)
        );

        $response = $controller->checkout(new Request([], [], [], [], [], [], json_encode(['payment_method' => 'cash'])));

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testCheckoutReturns500WithoutCashSession(): void
    {
        $_SESSION['user_id'] = 1;

        $cashService = $this->createMock(CashSessionService::class);
        $cashService->expects($this->once())
            ->method('requireOpenSession')
            ->willThrowException(new \RuntimeException('No open cash session'));

        $checkoutService = $this->createMock(CheckoutService::class);
        $checkoutService->expects($this->never())->method('createOrder');

        $controller = new CheckoutController($checkoutService, $cashService);
        $request = new Request([], [], [], [], [], [], json_encode([
            'payment_method' => 'cash',
            'amount_tendered' => 50.0,
            'cart_data' => ['lines' => [['product_id' => 1, 'qty' => 1, 'price' => 10.0]], 'total' => 10.0],
        ]));

        $response = $controller->checkout($request);
        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    public function testCheckoutReturns200OnSuccess(): void
    {
        $_SESSION['user_id'] = 1;

        $cashService = $this->createMock(CashSessionService::class);
        $cashService->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 1, 'shop_id' => 1, 'user_id' => 1]);

        $checkoutService = $this->createMock(CheckoutService::class);
        $checkoutService->method('createOrder')->willReturn([
            'ps_order_id' => 42,
            'reference' => 'ORD-042',
            'total' => 42.5,
            'change_due' => 7.5,
            'payment_method' => 'cash',
            'saga_error' => false,
        ]);

        $controller = new CheckoutController($checkoutService, $cashService);
        $response = $controller->checkout(new Request([], [], [], [], [], [], json_encode([
            'payment_method' => 'cash',
            'amount_tendered' => 50.0,
            'cart_data' => ['lines' => [['product_id' => 1, 'qty' => 1, 'price' => 42.5]], 'total' => 42.5],
        ])));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testCheckoutReturns422OnValidationError(): void
    {
        $_SESSION['user_id'] = 1;

        $cashService = $this->createMock(CashSessionService::class);
        $cashService->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 1, 'shop_id' => 1, 'user_id' => 1]);

        $checkoutService = $this->createMock(CheckoutService::class);
        $checkoutService->method('createOrder')->willThrowException(CheckoutException::psValidationFailed('Cart is empty'));

        $controller = new CheckoutController($checkoutService, $cashService);
        $response = $controller->checkout(new Request([], [], [], [], [], [], json_encode([
            'payment_method' => 'cash',
            'amount_tendered' => 10,
            'cart_data' => ['lines' => []],
        ])));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }
}
