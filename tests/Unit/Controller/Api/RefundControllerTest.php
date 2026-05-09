<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\RefundController;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\OrderSearchService;
use VenPosTPV\Service\Refund\RefundService;

final class RefundControllerTest extends TestCase
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

    public function testRefundReturns401WithoutSession(): void
    {
        $controller = new RefundController(
            $this->createMock(RefundService::class),
            $this->createMock(OrderSearchService::class),
            $this->createMock(CashSessionService::class)
        );

        $response = $controller->refund(new Request([], [], [], [], [], [], json_encode(['ps_order_id' => 42])));

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testRefundReturns404OnMissingOrder(): void
    {
        $_SESSION['user_id'] = 1;

        $cashService = $this->createMock(CashSessionService::class);
        $cashService->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 1]);

        $refundService = $this->createMock(RefundService::class);
        $refundService->method('processRefund')->willThrowException(RefundException::orderNotFound(42));

        $controller = new RefundController($refundService, $this->createMock(OrderSearchService::class), $cashService);
        $response = $controller->refund(new Request([], [], [], [], [], [], json_encode(['ps_order_id' => 42])));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testRefundReturns409OnDoubleRefund(): void
    {
        $_SESSION['user_id'] = 1;

        $cashService = $this->createMock(CashSessionService::class);
        $cashService->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 1]);

        $refundService = $this->createMock(RefundService::class);
        $refundService->method('processRefund')->willThrowException(RefundException::doubleRefundAttempt(42));

        $controller = new RefundController($refundService, $this->createMock(OrderSearchService::class), $cashService);
        $response = $controller->refund(new Request([], [], [], [], [], [], json_encode(['ps_order_id' => 42])));

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function testRefundReturns200OnSuccess(): void
    {
        $_SESSION['user_id'] = 1;

        $cashService = $this->createMock(CashSessionService::class);
        $cashService->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 1]);

        $refundService = $this->createMock(RefundService::class);
        $refundService->method('processRefund')->willReturn([
            'order_slip_id' => 7,
            'refunded_amount' => 29.90,
            'movement_id' => 312,
            'saga_error' => false,
        ]);

        $controller = new RefundController($refundService, $this->createMock(OrderSearchService::class), $cashService);
        $response = $controller->refund(new Request([], [], [], [], [], [], json_encode(['ps_order_id' => 42])));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testSearchOrdersReturns401WithoutSession(): void
    {
        $controller = new RefundController(
            $this->createMock(RefundService::class),
            $this->createMock(OrderSearchService::class),
            $this->createMock(CashSessionService::class)
        );

        $response = $controller->searchOrders(new Request(['q' => 'ABC']));

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testSearchOrdersReturns200WithResults(): void
    {
        $_SESSION['user_id'] = 1;

        $orderSearch = $this->createMock(OrderSearchService::class);
        $orderSearch->method('search')->willReturn([
            ['ps_order_id' => 42, 'reference' => 'ABC-123', 'total' => 29.90],
        ]);

        $controller = new RefundController(
            $this->createMock(RefundService::class),
            $orderSearch,
            $this->createMock(CashSessionService::class)
        );

        $response = $controller->searchOrders(new Request(['q' => 'ABC']));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testSearchOrdersPassesQueryToService(): void
    {
        $_SESSION['user_id'] = 1;

        $orderSearch = $this->createMock(OrderSearchService::class);
        $orderSearch->expects($this->once())
            ->method('search')
            ->with($this->callback(function ($query) {
                return $query['reference'] === 'ABC-123';
            }))
            ->willReturn([]);

        $controller = new RefundController(
            $this->createMock(RefundService::class),
            $orderSearch,
            $this->createMock(CashSessionService::class)
        );

        $controller->searchOrders(new Request(['q' => 'ABC-123']));
    }

    public function testSearchOrdersPassesDateRange(): void
    {
        $_SESSION['user_id'] = 1;

        $orderSearch = $this->createMock(OrderSearchService::class);
        $orderSearch->expects($this->once())
            ->method('search')
            ->with($this->callback(function ($query) {
                return $query['date_from'] === '2026-01-01' && $query['date_to'] === '2026-01-31';
            }))
            ->willReturn([]);

        $controller = new RefundController(
            $this->createMock(RefundService::class),
            $orderSearch,
            $this->createMock(CashSessionService::class)
        );

        $controller->searchOrders(new Request(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']));
    }

    public function testSearchOrdersPassesRefundedParam(): void
    {
        $_SESSION['user_id'] = 1;

        $orderSearch = $this->createMock(OrderSearchService::class);
        $orderSearch->expects($this->once())
            ->method('search')
            ->with($this->callback(function ($query) {
                return $query['has_refund'] === true;
            }))
            ->willReturn([
                ['ps_order_id' => 10, 'reference' => 'REF-010', 'total' => 20.00, 'refunded_amount' => 20.00],
            ]);

        $controller = new RefundController(
            $this->createMock(RefundService::class),
            $orderSearch,
            $this->createMock(CashSessionService::class)
        );

        $response = $controller->searchOrders(new Request(['refunded' => '1']));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertCount(1, $data['data']);
        $this->assertSame(10, $data['data'][0]['ps_order_id']);
    }

    public function testSearchOrdersOmitsRefundedParamWhenNotSet(): void
    {
        $_SESSION['user_id'] = 1;

        $orderSearch = $this->createMock(OrderSearchService::class);
        $orderSearch->expects($this->once())
            ->method('search')
            ->with($this->callback(function ($query) {
                return !array_key_exists('has_refund', $query);
            }))
            ->willReturn([]);

        $controller = new RefundController(
            $this->createMock(RefundService::class),
            $orderSearch,
            $this->createMock(CashSessionService::class)
        );

        $controller->searchOrders(new Request(['q' => 'ABC']));
    }
}
