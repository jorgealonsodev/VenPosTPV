<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\RefundController;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketPdfGenerator;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\OrderSearchService;
use VenPosTPV\Service\Refund\RefundService;
use VenPosTPV\Tests\Support\SessionRequest;

final class RefundControllerTest extends TestCase
{
    private function createController(?RefundService $refundService = null, ?OrderSearchService $orderSearch = null, ?CashSessionService $cashService = null, ?PrinterService $printer = null): RefundController
    {
        return new RefundController(
            $refundService ?? $this->createMock(RefundService::class),
            $orderSearch ?? $this->createMock(OrderSearchService::class),
            $cashService ?? $this->createMock(CashSessionService::class),
            $printer ?? $this->createMock(PrinterService::class),
            $this->createMock(TicketDataResolver::class),
            $this->createMock(TicketPdfGenerator::class),
        );
    }

    public function testNoTicketRefundRequiresASession(): void
    {
        $response = $this->createController()->refundWithoutTicket(SessionRequest::json(['method' => 'cash']));

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testNoTicketRefundPassesTheBodyAndShopAndPrintsTheCreditNote(): void
    {
        $lines = [['product_id' => 3, 'id_product_attribute' => 7, 'quantity' => 1, 'restock' => false]];
        $refundService = $this->createMock(RefundService::class);
        $refundService->expects($this->once())->method('processNoTicketRefund')
            ->with(1, 2, $lines, 'voucher', 'other', 'Sin caja', false)
            ->willReturn(['refund_id' => 8, 'credit_note_number' => 'R2026-000008', 'refunded_amount' => 30.25, 'voucher_code' => 'VALE2345', 'saga_error' => false]);
        $printer = $this->createMock(PrinterService::class);
        $printer->expects($this->once())->method('printRefundTicket')->with(8)->willReturn('printed');

        $response = $this->createController($refundService, printer: $printer)->refundWithoutTicket(SessionRequest::json(
            ['lines' => $lines, 'method' => 'voucher', 'reason_code' => 'other', 'reason_text' => 'Sin caja', 'price' => 0.01],
            ['user_id' => 1, 'shop_id' => 2]
        ));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true)['data'];
        $this->assertSame('VALE2345', $data['voucher_code']);
        $this->assertSame('printed', $data['print_status']);
    }

    public function testNoTicketRefundMapsErrorsLikeRefundsWithTicket(): void
    {
        foreach ([[new \VenPosTPV\Service\Cash\Exception\NoOpenCashSessionException(), 409, false], [RefundException::validationFailed('Choose cash, card or voucher'), 422, false], [RefundException::conflict('voucher unknown'), 409, false], [RefundException::psUnavailable('down'), 502, true]] as [$error, $status, $retry]) {
            $refundService = $this->createMock(RefundService::class);
            $refundService->method('processNoTicketRefund')->willThrowException($error);

            $response = $this->createController($refundService)->refundWithoutTicket(SessionRequest::json(['lines' => [], 'method' => 'bizum', 'card_confirmed' => 'yes'], ['user_id' => 1]));

            $this->assertSame($status, $response->getStatusCode());
            $this->assertSame($retry, json_decode((string) $response->getContent(), true)['retry']);
        }
    }

    public function testNoTicketRefundIgnoresNonArrayLinesAndNonBooleanConfirmation(): void
    {
        $refundService = $this->createMock(RefundService::class);
        $refundService->expects($this->once())->method('processNoTicketRefund')
            ->with(1, 0, [], 'card', '', '', false)
            ->willThrowException(RefundException::validationFailed('Select at least one product'));

        $response = $this->createController($refundService)->refundWithoutTicket(SessionRequest::json(['lines' => 'x', 'method' => 'card', 'card_confirmed' => 'true'], ['user_id' => 1]));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testPendingNoTicketRefundsAreListedForRetry(): void
    {
        $refundService = $this->createMock(RefundService::class);
        $refundService->method('pendingNoTicketRefunds')->willReturn([['id' => 5, 'total' => 12.5, 'method' => 'voucher', 'created_at' => '2026-11-03 10:00:00', 'error' => null, 'voucher_unknown' => true]]);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $this->createController($refundService)->pendingWithoutTicket(SessionRequest::json([]))->getStatusCode());
        $response = $this->createController($refundService)->pendingWithoutTicket(SessionRequest::json([], ['user_id' => 1]));

        $this->assertSame(5, json_decode((string) $response->getContent(), true)['data'][0]['id']);
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

    public function testRefundPassesReasonAndCardConfirmationAndMaps422(): void
    {
        $cashService = $this->createMock(CashSessionService::class);
        $cashService->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 1]);
        $refundService = $this->createMock(RefundService::class);
        $refundService->expects($this->once())->method('processRefund')
            ->with(1, 42, [['line_index' => 0, 'quantity' => 1, 'restock' => false]], 'other', 'Llegó roto', true)
            ->willThrowException(RefundException::validationFailed('Only 0 unit(s) of line 0 are left to refund'));

        $response = $this->createController($refundService, cashService: $cashService)->refund(SessionRequest::json([
            'ps_order_id' => 42,
            'lines' => [['line_index' => 0, 'quantity' => 1, 'restock' => false]],
            'reason_code' => 'other',
            'reason_text' => 'Llegó roto',
            'card_confirmed' => true,
        ], ['user_id' => 1]));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testRetryRequiresSessionAndMapsUnknownOutcomeTo502(): void
    {
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $this->createController()->retry(SessionRequest::json([]), 5)->getStatusCode());

        $refundService = $this->createMock(RefundService::class);
        $refundService->expects($this->once())->method('retryPending')->with(1, 5)
            ->willThrowException(RefundException::psUnavailable('timeout'));

        $response = $this->createController($refundService)->retry(SessionRequest::json([], ['user_id' => 1]), 5);

        $this->assertSame(Response::HTTP_BAD_GATEWAY, $response->getStatusCode());
    }

    public function testSuccessfulRefundPrintsTheCreditNoteAndReportsHow(): void
    {
        $refundService = $this->createMock(RefundService::class);
        $refundService->method('processRefund')->willReturn(['refund_id' => 7, 'credit_note_number' => 'R2026-000007', 'refunded_amount' => 10.0]);
        $printer = $this->createMock(PrinterService::class);
        $printer->expects($this->once())->method('printRefundTicket')->with(7)->willReturn('pdf');

        $response = $this->createController($refundService, printer: $printer)->refund(SessionRequest::json(['ps_order_id' => 42, 'reason_code' => 'size'], ['user_id' => 1]));

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertSame('pdf', $data['print_status']);
        $this->assertSame('R2026-000007', $data['credit_note_number']);
    }

    public function testNoCreditNoteIsOfferedWhileTheRefundIsIncomplete(): void
    {
        $refundService = $this->createMock(RefundService::class);
        $refundService->method('processRefund')->willReturn(['refund_id' => 7, 'saga_error' => true]);
        $printer = $this->createMock(PrinterService::class);
        $printer->expects($this->never())->method('printRefundTicket');

        $response = $this->createController($refundService, printer: $printer)->refund(SessionRequest::json(['ps_order_id' => 42, 'reason_code' => 'size'], ['user_id' => 1]));

        $this->assertSame('none', json_decode((string) $response->getContent(), true)['data']['print_status']);
    }

    public function testPrinterFailureNeverFailsTheRefund(): void
    {
        $refundService = $this->createMock(RefundService::class);
        $refundService->method('processRefund')->willReturn(['refund_id' => 7]);
        $printer = $this->createMock(PrinterService::class);
        $printer->method('printRefundTicket')->willThrowException(new \RuntimeException('paper out'));

        $response = $this->createController($refundService, printer: $printer)->refund(SessionRequest::json(['ps_order_id' => 42, 'reason_code' => 'size'], ['user_id' => 1]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('pdf', json_decode((string) $response->getContent(), true)['data']['print_status']);
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
