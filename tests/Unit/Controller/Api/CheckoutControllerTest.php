<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use VenPosTPV\Controller\Api\CheckoutController;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\Exception\NoOpenCashSessionException;
use VenPosTPV\Service\Checkout\CheckoutService;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;

final class CheckoutControllerTest extends TestCase
{
    private const OPEN_SESSION = ['session_id' => 1, 'terminal_id' => 1, 'shop_id' => 1, 'user_id' => 1];

    /** @param array<string, mixed> $body */
    private function makeRequest(array $body, ?int $userId): Request
    {
        $session = new Session(new MockArraySessionStorage());
        if ($userId !== null) {
            $session->set('user_id', $userId);
        }

        $request = new Request([], [], [], [], [], [], (string) json_encode($body));
        $request->setSession($session);

        return $request;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        return (array) json_decode((string) $response->getContent(), true);
    }

    public function testCheckoutReturns401WithoutSession(): void
    {
        $controller = new CheckoutController(
            $this->createMock(CheckoutService::class),
            $this->createMock(CashSessionService::class)
        );

        $response = $controller->checkout($this->makeRequest(['payment_method' => 'cash'], null));

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testCheckoutReturns409WithoutCashSession(): void
    {
        $cashService = $this->createMock(CashSessionService::class);
        $cashService->expects($this->once())
            ->method('requireOpenSession')
            ->with(1)
            ->willThrowException(new NoOpenCashSessionException());

        $checkoutService = $this->createMock(CheckoutService::class);
        $checkoutService->expects($this->never())->method('createOrder');

        $controller = new CheckoutController($checkoutService, $cashService);
        $response = $controller->checkout($this->makeRequest([
            'payment_method' => 'card',
            'amount_tendered' => 10.0,
            'cart_data' => ['lines' => [['product_id' => 1, 'qty' => 1, 'price' => 10.0]], 'total' => 10.0],
        ], 1));

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $this->assertSame(
            [
                'error' => 'No hay caja abierta. Abre la caja antes de cobrar.',
                'code' => 'cash_session_closed',
                'retry' => false,
            ],
            $this->decode($response)
        );
    }

    public function testCheckoutReturns500WhenCashSessionCheckFailsUnexpectedly(): void
    {
        $cashService = $this->createMock(CashSessionService::class);
        $cashService->method('requireOpenSession')->willThrowException(new \RuntimeException('Missing shop context'));

        $checkoutService = $this->createMock(CheckoutService::class);
        $checkoutService->expects($this->never())->method('createOrder');

        $controller = new CheckoutController($checkoutService, $cashService);
        $response = $controller->checkout($this->makeRequest([
            'payment_method' => 'card',
            'amount_tendered' => 10.0,
            'cart_data' => ['lines' => [['product_id' => 1, 'qty' => 1, 'price' => 10.0]], 'total' => 10.0],
        ], 1));

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->assertSame(['error' => 'Internal server error', 'retry' => true], $this->decode($response));
    }

    public function testCheckoutReturns200OnSuccess(): void
    {
        $cashService = $this->createMock(CashSessionService::class);
        $cashService->method('requireOpenSession')->willReturn(self::OPEN_SESSION);

        $checkoutService = $this->createMock(CheckoutService::class);
        $checkoutService->expects($this->once())->method('createOrder')->willReturn([
            'ps_order_id' => 42,
            'reference' => 'ORD-042',
            'total' => 42.5,
            'change_due' => 7.5,
            'payment_method' => 'cash',
            'terminal_id' => 1,
            'saga_error' => false,
        ]);

        $controller = new CheckoutController($checkoutService, $cashService);
        $response = $controller->checkout($this->makeRequest([
            'payment_method' => 'cash',
            'amount_tendered' => 50.0,
            'cart_data' => ['lines' => [['product_id' => 1, 'qty' => 1, 'price' => 42.5]], 'total' => 42.5],
        ], 1));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response)['data'];
        $this->assertSame(42, $data['ps_order_id']);
        $this->assertSame('dispatched', $data['print_status']);
    }

    public function testCheckoutReturns422OnValidationError(): void
    {
        $cashService = $this->createMock(CashSessionService::class);
        $cashService->method('requireOpenSession')->willReturn(self::OPEN_SESSION);

        $checkoutService = $this->createMock(CheckoutService::class);
        $checkoutService->method('createOrder')->willThrowException(CheckoutException::psValidationFailed('Cart is empty'));

        $controller = new CheckoutController($checkoutService, $cashService);
        $response = $controller->checkout($this->makeRequest([
            'payment_method' => 'cash',
            'amount_tendered' => 10,
            'cart_data' => ['lines' => []],
        ], 1));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertFalse($this->decode($response)['retry']);
    }

    public function testCheckoutReturns409WhenSessionClosesDuringOrderCreation(): void
    {
        $cashService = $this->createMock(CashSessionService::class);
        $cashService->method('requireOpenSession')->willReturn(self::OPEN_SESSION);

        $checkoutService = $this->createMock(CheckoutService::class);
        $checkoutService->method('createOrder')->willThrowException(new NoOpenCashSessionException());

        $controller = new CheckoutController($checkoutService, $cashService);
        $response = $controller->checkout($this->makeRequest([
            'payment_method' => 'card',
            'amount_tendered' => 10.0,
            'cart_data' => ['lines' => [['product_id' => 1, 'qty' => 1, 'price' => 10.0]], 'total' => 10.0],
        ], 1));

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $this->assertSame('cash_session_closed', $this->decode($response)['code']);
    }

    public function testCheckoutReturns500OnUnexpectedErrorAfterCashCheck(): void
    {
        $cashService = $this->createMock(CashSessionService::class);
        $cashService->method('requireOpenSession')->willReturn(self::OPEN_SESSION);

        $checkoutService = $this->createMock(CheckoutService::class);
        $checkoutService->method('createOrder')->willThrowException(new \RuntimeException('boom'));

        $controller = new CheckoutController($checkoutService, $cashService);
        $response = $controller->checkout($this->makeRequest([
            'payment_method' => 'card',
            'amount_tendered' => 10.0,
            'cart_data' => ['lines' => [['product_id' => 1, 'qty' => 1, 'price' => 10.0]], 'total' => 10.0],
        ], 1));

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->assertSame(['error' => 'Internal server error', 'retry' => true], $this->decode($response));
    }
}
