<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\ExchangeController;
use VenPosTPV\Service\Cash\Exception\NoOpenCashSessionException;
use VenPosTPV\Service\Exchange\ExchangeService;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Refund\CreditNoteHandOver;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\OrderSearchService;
use VenPosTPV\Tests\Support\SessionRequest;

final class ExchangeControllerTest extends TestCase
{
    private function controller(ExchangeService $service, ?PrinterService $printer = null, ?OrderSearchService $orderSearch = null): ExchangeController
    {
        return new ExchangeController($service, new CreditNoteHandOver($printer ?? $this->createMock(PrinterService::class)), $orderSearch ?? $this->createMock(OrderSearchService::class));
    }

    public function testExchangeOfASaleNotUploadedYetIsNamedByItsLocalIdOrReference(): void
    {
        foreach ([['order_id' => 12], ['reference' => 'A26000012']] as $body) {
            $orderSearch = $this->createMock(OrderSearchService::class);
            $orderSearch->expects($this->once())->method('findSaleId')->with($body['order_id'] ?? 0, $body['reference'] ?? '')->willReturn(12);
            $service = $this->createMock(ExchangeService::class);
            $service->expects($this->once())->method('processExchange')
                ->with(1, 0, [], '', '', [], '', 0.0, false, 0, 12, 'card')
                ->willReturn($this->exchangeResult(['refund_id' => 8, 'cash_amount' => 0.0, 'saga_error' => false], ['order_id' => 13, 'ps_order_id' => null, 'reference' => 'A26000013']));

            $response = $this->controller($service, null, $orderSearch)->exchange(SessionRequest::json($body + ['voucher_fallback' => 'card'], ['user_id' => 1]));

            $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        }
    }

    public function testVoucherPrestaShopCannotCreateIsA503ForExchangesToo(): void
    {
        $service = $this->createMock(ExchangeService::class);
        $service->method('closeWithVoucher')->willThrowException(RefundException::voucherUnavailable());

        $response = $this->controller($service)->closeWithVoucher(SessionRequest::json([], ['user_id' => 1, 'shop_id' => 1]), 3);

        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $this->assertSame('voucher_unavailable', json_decode((string) $response->getContent(), true)['code']);
    }

    /** @return array<string, mixed> */
    private function exchangeResult(array $refund, ?array $sale, string $status = 'done'): array
    {
        return ['exchange_id' => 3, 'status' => $status, 'credit' => 55.0, 'new_total' => 40.0, 'balance' => -15.0,
            'refund' => $refund, 'sale' => $sale, 'sale_error' => $sale === null ? 'timeout' : null, 'sale_outcome_unknown' => $sale === null];
    }

    public function testExchangeRequiresASession(): void
    {
        $service = $this->createMock(ExchangeService::class);
        $service->expects($this->never())->method('processExchange');

        $response = $this->controller($service)->exchange(SessionRequest::json(['ps_order_id' => 1]));

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testExchangePassesTheBodyPrintsTheCreditNoteAndOpensTheDrawerForCashBack(): void
    {
        $lines = [['line_index' => 0, 'quantity' => 1, 'restock' => true]];
        $cart = ['lines' => [['product_id' => 5, 'qty' => 1, 'price' => 40.0]], 'total' => 40.0];
        $service = $this->createMock(ExchangeService::class);
        $service->expects($this->once())->method('processExchange')
            ->with(1, 42, $lines, 'other', 'Talla', $cart + ['cash_amount' => 2.5], 'mixed', 10.0, true)
            ->willReturn($this->exchangeResult(['refund_id' => 8, 'credit_note_number' => 'R2026-000008', 'cash_amount' => 15.0, 'saga_error' => false], ['ps_order_id' => 99, 'reference' => 'NEW99']));
        $printer = $this->createMock(PrinterService::class);
        $printer->expects($this->once())->method('printRefundTicket')->with(8, 4)->willReturn('printed');
        $printer->expects($this->once())->method('openDrawer')->with(4);

        $response = $this->controller($service, $printer)->exchange(SessionRequest::json([
            'ps_order_id' => 42, 'return_lines' => $lines, 'reason_code' => 'other', 'reason_text' => 'Talla',
            'new_cart_data' => $cart, 'payment_method' => 'mixed', 'amount_tendered' => 10, 'cash_amount' => 2.5, 'card_confirmed' => true,
        ], ['user_id' => 1, 'terminal_id' => 4]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true)['data'];
        $this->assertSame('printed', $data['refund']['print_status']);
        $this->assertSame('dispatched', $data['sale']['print_status']);
        $this->assertSame(-15, $data['balance']);
    }

    public function testFailedSaleStillHandsOverTheCreditNoteAndReportsTheOpenCredit(): void
    {
        $service = $this->createMock(ExchangeService::class);
        $service->method('processExchange')->willReturn($this->exchangeResult(['refund_id' => 8, 'cash_amount' => 0.0, 'saga_error' => false], null, 'credit_open'));
        $printer = $this->createMock(PrinterService::class);
        $printer->expects($this->once())->method('printRefundTicket')->willReturn('pdf');
        $printer->expects($this->never())->method('openDrawer');

        $response = $this->controller($service, $printer)->exchange(SessionRequest::json(['ps_order_id' => 42, 'card_confirmed' => 'yes'], ['user_id' => 1]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true)['data'];
        $this->assertSame('credit_open', $data['status']);
        $this->assertSame('pdf', $data['refund']['print_status']);
        $this->assertNull($data['sale']);
    }

    public function testRetryNeverReprintsAReturnCompletedEarlier(): void
    {
        $service = $this->createMock(ExchangeService::class);
        $service->expects($this->once())->method('retry')->with(1, 3)
            ->willReturn($this->exchangeResult(['refund_id' => 8, 'already_completed' => true, 'resumed' => true], ['ps_order_id' => 99, 'reference' => 'NEW99']));
        $printer = $this->createMock(PrinterService::class);
        $printer->expects($this->never())->method('printRefundTicket');
        $printer->expects($this->never())->method('openDrawer');

        $response = $this->controller($service, $printer)->retry(SessionRequest::json([], ['user_id' => 1]), 3);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('none', json_decode((string) $response->getContent(), true)['data']['refund']['print_status']);
    }

    public function testErrorsMapLikeRefunds(): void
    {
        foreach ([[new NoOpenCashSessionException(), 409, false], [RefundException::orderNotFound(1), 404, false], [RefundException::validationFailed('x'), 422, false], [RefundException::conflict('x'), 409, false], [RefundException::psUnavailable('down'), 502, true], [new \RuntimeException('boom'), 500, true]] as [$error, $status, $retry]) {
            $service = $this->createMock(ExchangeService::class);
            $service->method('processExchange')->willThrowException($error);
            $service->method('retry')->willThrowException($error);

            foreach ([$this->controller($service)->exchange(SessionRequest::json(['ps_order_id' => 1], ['user_id' => 1])), $this->controller($service)->retry(SessionRequest::json([], ['user_id' => 1]), 3)] as $response) {
                $this->assertSame($status, $response->getStatusCode(), $error->getMessage());
                $this->assertSame($retry, json_decode((string) $response->getContent(), true)['retry']);
            }
        }
    }

    public function testCloseWithVoucherRequiresASession(): void
    {
        $service = $this->createMock(ExchangeService::class);
        $service->expects($this->never())->method('closeWithVoucher');

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $this->controller($service)->closeWithVoucher(SessionRequest::json([]), 3)->getStatusCode());
    }

    public function testCloseWithVoucherPassesTheShopAndReturnsTheCodeWithoutPrintingOrOpeningTheDrawer(): void
    {
        $service = $this->createMock(ExchangeService::class);
        $service->expects($this->once())->method('closeWithVoucher')->with(1, 3, 5)
            ->willReturn(['exchange_id' => 3, 'status' => 'closed_voucher', 'voucher_code' => 'CAMB1XYZ', 'voucher_amount' => 40.0]);
        $printer = $this->createMock(PrinterService::class);
        $printer->expects($this->never())->method('printRefundTicket');
        $printer->expects($this->never())->method('openDrawer');

        $response = $this->controller($service, $printer)->closeWithVoucher(SessionRequest::json([], ['user_id' => 1, 'shop_id' => 5, 'terminal_id' => 4]), 3);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(['exchange_id' => 3, 'status' => 'closed_voucher', 'voucher_code' => 'CAMB1XYZ', 'voucher_amount' => 40], json_decode((string) $response->getContent(), true)['data']);
    }

    public function testCloseWithVoucherErrorsMapLikeRefunds(): void
    {
        foreach ([[new NoOpenCashSessionException(), 409, false], [RefundException::validationFailed('x'), 422, false], [RefundException::conflict('never created twice automatically'), 409, false], [RefundException::psUnavailable('down'), 502, true], [new \RuntimeException('boom'), 500, true]] as [$error, $status, $retry]) {
            $service = $this->createMock(ExchangeService::class);
            $service->method('closeWithVoucher')->willThrowException($error);

            $response = $this->controller($service)->closeWithVoucher(SessionRequest::json([], ['user_id' => 1]), 3);

            $this->assertSame($status, $response->getStatusCode(), $error->getMessage());
            $this->assertSame($retry, json_decode((string) $response->getContent(), true)['retry']);
        }
    }

    public function testExchangePassesTheShop(): void
    {
        $service = $this->createMock(ExchangeService::class);
        $service->expects($this->once())->method('processExchange')
            ->with(1, 42, [], '', '', [], '', 0.0, false, 6)
            ->willReturn($this->exchangeResult(['refund_id' => 8, 'cash_amount' => 0.0, 'saga_error' => false], ['ps_order_id' => 99, 'reference' => 'NEW99']));

        $response = $this->controller($service)->exchange(SessionRequest::json(['ps_order_id' => 42], ['user_id' => 1, 'shop_id' => 6]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testMissingOrderIs422WithoutCallingTheService(): void
    {
        $service = $this->createMock(ExchangeService::class);
        $service->expects($this->never())->method('processExchange');

        $response = $this->controller($service)->exchange(SessionRequest::json(['return_lines' => []], ['user_id' => 1]));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }
}
