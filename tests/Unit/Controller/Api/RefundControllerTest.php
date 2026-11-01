<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\RefundController;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\OrderSearchService;
use VenPosTPV\Service\Refund\RefundService;
use VenPosTPV\Tests\Support\SessionRequest;

final class RefundControllerTest extends TestCase
{
    private function createController(?RefundService $refundService = null, ?OrderSearchService $orderSearch = null, ?CashSessionService $cashService = null): RefundController
    {
        return new RefundController(
            $refundService ?? $this->createMock(RefundService::class),
            $orderSearch ?? $this->createMock(OrderSearchService::class),
            $cashService ?? $this->createMock(CashSessionService::class),
        );
    }

    public function testRefundReturns401WithoutSession(): void
    {
        $response = $this->createController()->refund(SessionRequest::json(['ps_order_id' => 42]));
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testRefundReturns404OnMissingOrder(): void
    {
        $cashService = $this->createMock(CashSessionService::class);
        $cashService->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 1]);
        $refundService = $this->createMock(RefundService::class);
        $refundService->method('processRefund')->willThrowException(RefundException::orderNotFound(42));

        $response = $this->createController($refundService, cashService: $cashService)->refund(SessionRequest::json(['ps_order_id' => 42], ['user_id' => 1]));
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testRefundReturns409OnDoubleRefund(): void
    {
        $cashService = $this->createMock(CashSessionService::class);
        $cashService->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 1]);
        $refundService = $this->createMock(RefundService::class);
        $refundService->method('processRefund')->willThrowException(RefundException::doubleRefundAttempt(42));

        $response = $this->createController($refundService, cashService: $cashService)->refund(SessionRequest::json(['ps_order_id' => 42], ['user_id' => 1]));
        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function testRefundReturns200OnSuccess(): void
    {
        $cashService = $this->createMock(CashSessionService::class);
        $cashService->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 1]);
        $refundService = $this->createMock(RefundService::class);
        $refundService->method('processRefund')->willReturn([
            'order_slip_id' => 7, 'refunded_amount' => 29.90, 'movement_id' => 312, 'saga_error' => false,
        ]);

        $response = $this->createController($refundService, cashService: $cashService)->refund(SessionRequest::json(['ps_order_id' => 42], ['user_id' => 1]));
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testSearchOrdersReturns401WithoutSession(): void
    {
        $response = $this->createController()->searchOrders(SessionRequest::create(['q' => 'ABC']));
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testSearchOrdersReturns200WithResults(): void
    {
        $orderSearch = $this->createMock(OrderSearchService::class);
        $orderSearch->method('search')->willReturn([['ps_order_id' => 42, 'reference' => 'ABC-123', 'total' => 29.90]]);

        $response = $this->createController(orderSearch: $orderSearch)->searchOrders(SessionRequest::create(['q' => 'ABC'], ['user_id' => 1]));
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testSearchOrdersPassesQueryToService(): void
    {
        $orderSearch = $this->createMock(OrderSearchService::class);
        $orderSearch->expects($this->once())->method('search')->with($this->callback(static fn (array $query): bool => $query['reference'] === 'ABC-123'))->willReturn([]);

        $this->createController(orderSearch: $orderSearch)->searchOrders(SessionRequest::create(['q' => 'ABC-123'], ['user_id' => 1]));
    }

    public function testSearchOrdersPassesDateRange(): void
    {
        $orderSearch = $this->createMock(OrderSearchService::class);
        $orderSearch->expects($this->once())->method('search')->with($this->callback(static fn (array $query): bool => $query['date_from'] === '2026-01-01' && $query['date_to'] === '2026-01-31'))->willReturn([]);

        $this->createController(orderSearch: $orderSearch)->searchOrders(SessionRequest::create(['date_from' => '2026-01-01', 'date_to' => '2026-01-31'], ['user_id' => 1]));
    }

    public function testSearchOrdersPassesRefundedParam(): void
    {
        $orderSearch = $this->createMock(OrderSearchService::class);
        $orderSearch->expects($this->once())->method('search')->with($this->callback(static fn (array $query): bool => $query['has_refund'] === true))->willReturn([
            ['ps_order_id' => 10, 'reference' => 'REF-010', 'total' => 20.00, 'refunded_amount' => 20.00],
        ]);

        $response = $this->createController(orderSearch: $orderSearch)->searchOrders(SessionRequest::create(['refunded' => '1'], ['user_id' => 1]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertCount(1, $data['data']);
        $this->assertSame(10, $data['data'][0]['ps_order_id']);
    }

    public function testSearchOrdersOmitsRefundedParamWhenNotSet(): void
    {
        $orderSearch = $this->createMock(OrderSearchService::class);
        $orderSearch->expects($this->once())->method('search')->with($this->callback(static fn (array $query): bool => !array_key_exists('has_refund', $query)))->willReturn([]);

        $this->createController(orderSearch: $orderSearch)->searchOrders(SessionRequest::create(['q' => 'ABC'], ['user_id' => 1]));
    }
}
