<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use VenPosTPV\Controller\Api\CheckoutController;
use VenPosTPV\Controller\Api\ExchangeController;
use VenPosTPV\Controller\Api\RefundController;
use VenPosTPV\Controller\Api\TerminalController;
use VenPosTPV\Controller\Api\TicketController;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Checkout\CheckoutService;
use VenPosTPV\Service\Exchange\ExchangeService;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\SystemPrintQueue;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketPdfGenerator;
use VenPosTPV\Service\Print\TicketVerificationService;
use VenPosTPV\Service\Refund\CreditNoteHandOver;
use VenPosTPV\Service\Refund\OrderSearchService;
use VenPosTPV\Service\Refund\RefundService;
use VenPosTPV\Tests\Support\SessionRequest;

/**
 * docs/prd/agente-impresion.md T0, T9, T10 at the endpoints: the print status of an
 * agent till ('queued' + print_job_id) next to the existing fields, and never a
 * job on till 1 when the session has no till.
 */
final class PrintAgentRoutingControllersTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function data(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true)['data'] ?? json_decode((string) $response->getContent(), true);
    }

    private function ticketController(PrinterService $printer): TicketController
    {
        return new TicketController($printer, $this->createMock(TicketDataResolver::class), $this->createMock(TicketPdfGenerator::class), $this->createMock(TicketVerificationService::class));
    }

    private function refundController(RefundService $refunds, PrinterService $printer): RefundController
    {
        return new RefundController($refunds, $this->createMock(OrderSearchService::class), $this->createMock(CashSessionService::class), $printer, $this->createMock(TicketDataResolver::class), $this->createMock(TicketPdfGenerator::class));
    }

    public function testReprintOnAnAgentTillAnswersTheQueuedJob(): void
    {
        $printer = $this->createMock(PrinterService::class);
        $printer->expects($this->once())->method('reprintTicket')->with(42, 5, false)->willReturn(['printed' => false, 'print_status' => 'queued', 'print_job_id' => 9]);

        $response = $this->ticketController($printer)->reprint(SessionRequest::json(['ps_order_id' => 42], ['user_id' => 1, 'terminal_id' => 5]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['printed' => false, 'print_status' => 'queued', 'print_job_id' => 9], self::data($response));
    }

    public function testReprintWithoutATillIsRefusedForAnAgentAndKeepsTillOneOtherwise(): void
    {
        $agent = $this->createMock(PrinterService::class);
        $agent->method('usesAgent')->with(null)->willReturn(true);
        $agent->expects($this->never())->method('reprintTicket');

        $refused = $this->ticketController($agent)->reprint(SessionRequest::json(['ps_order_id' => 42], ['user_id' => 1]));

        $this->assertSame(400, $refused->getStatusCode());
        $this->assertSame('terminal_not_resolved', json_decode((string) $refused->getContent(), true)['code']);

        $network = $this->createMock(PrinterService::class);
        $network->method('usesAgent')->willReturn(false);
        $network->expects($this->once())->method('reprintTicket')->with(42, 1, false)->willReturn(['printed' => true]);
        $this->assertSame(200, $this->ticketController($network)->reprint(SessionRequest::json(['ps_order_id' => 42], ['user_id' => 1]))->getStatusCode(), 'previous behavior');
    }

    public function testACreditNoteAndDrawerOnAnAgentTillReportTheirJobs(): void
    {
        $refunds = $this->createMock(RefundService::class);
        $refunds->method('processRefund')->willReturn(['refund_id' => 7, 'cash_amount' => 10.0]);
        $printer = $this->createMock(PrinterService::class);
        $jobs = ['ticket' => null, 'drawer' => null];
        $printer->method('printRefundTicket')->with(7, 4)->willReturnCallback(function () use (&$jobs): string {
            $jobs['ticket'] = 31;

            return 'queued';
        });
        $printer->expects($this->once())->method('openDrawer')->with(4)->willReturnCallback(function () use (&$jobs): void {
            $jobs['drawer'] = 32;
        });
        $printer->method('queuedJobId')->willReturnCallback(static function (string $type) use (&$jobs): ?int {
            return $jobs[$type];
        });

        $response = $this->refundController($refunds, $printer)->refund(SessionRequest::json(['ps_order_id' => 42], ['user_id' => 1, 'terminal_id' => 4]));

        $data = self::data($response);
        $this->assertSame(['queued', 31, 32], [$data['print_status'], $data['print_job_id'], $data['drawer_job_id']]);
        $this->assertSame(7, $data['refund_id']);
    }

    public function testARefundWithoutATillCreatesNoAgentJob(): void
    {
        $refunds = $this->createMock(RefundService::class);
        $refunds->method('processRefund')->willReturn(['refund_id' => 7, 'cash_amount' => 10.0]);
        $printer = $this->createMock(PrinterService::class);
        $printer->method('usesAgent')->with(null)->willReturn(true);
        $printer->expects($this->never())->method('printRefundTicket');
        $printer->expects($this->never())->method('openDrawer');

        $data = self::data($this->refundController($refunds, $printer)->refund(SessionRequest::json(['ps_order_id' => 42], ['user_id' => 1])));

        $this->assertSame(['pdf', 'terminal_not_resolved'], [$data['print_status'], $data['print_error']], 'the credit note is still handed over as PDF');
    }

    public function testExchangeKeepsTheSalesQueuedStatusAndOtherwiseDispatched(): void
    {
        foreach ([[['ps_order_id' => null, 'print_status' => 'queued', 'print_job_id' => 5], 'queued'], [['ps_order_id' => 99], 'dispatched']] as [$sale, $expected]) {
            $service = $this->createMock(ExchangeService::class);
            $service->method('processExchange')->willReturn(['exchange_id' => 3, 'status' => 'done', 'refund' => ['refund_id' => 8, 'cash_amount' => 0.0], 'sale' => $sale]);
            $printer = $this->createMock(PrinterService::class);
            $printer->method('printRefundTicket')->willReturn('printed');

            $response = (new ExchangeController($service, new CreditNoteHandOver($printer), $this->createMock(OrderSearchService::class), $printer))
                ->exchange(SessionRequest::json(['ps_order_id' => 42], ['user_id' => 1, 'terminal_id' => 4]));

            $this->assertSame($expected, self::data($response)['sale']['print_status']);
            $this->assertSame('printed', self::data($response)['refund']['print_status']);
        }
    }

    public function testCheckoutKeepsAQueuedStatusFromTheService(): void
    {
        foreach ([[['print_status' => 'queued', 'print_job_id' => 5], 'queued'], [[], 'dispatched'], [['replayed' => true, 'print_status' => 'queued'], 'none']] as [$extra, $expected]) {
            $checkout = $this->createMock(CheckoutService::class);
            $checkout->method('createOrder')->willReturn(['order_id' => 1, 'reference' => 'A26000001'] + $extra);
            $cash = $this->createMock(CashSessionService::class);
            $cash->method('getCurrentSession')->willReturn(['session_id' => 1]);

            $response = (new CheckoutController($checkout, $cash))->checkout(SessionRequest::json(['cart_data' => ['lines' => [['product_id' => 1, 'qty' => 1]]], 'payment_method' => 'card'], ['user_id' => 1, 'terminal_id' => 1]));

            $this->assertSame($expected, self::data($response)['print_status'] ?? null, json_encode($extra));
        }
    }

    public function testTheTillPrinterCanBeThePrintAgent(): void
    {
        $updates = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(['id' => 1, 'ps_shop_id' => 1]);
        $conn->method('update')->willReturnCallback(static function (string $table, array $fields) use (&$updates): int {
            $updates[] = $fields;

            return 1;
        });

        $response = (new TerminalController($conn, $this->createMock(SystemPrintQueue::class)))->update(1, SessionRequest::json(['printer_type' => 'agent'], ['user_id' => 1, 'role' => 'admin']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('agent', $updates[0]['printer_type']);
        $this->assertSame(['enabled' => true, 'type' => 'agent'], json_decode($updates[0]['printer_config'], true));
    }
}
