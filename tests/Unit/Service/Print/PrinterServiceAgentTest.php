<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Print\EscPosTicketBuilder;
use VenPosTPV\Service\Print\PrinterConfig;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\SystemPrintQueue;
use VenPosTPV\Service\Print\TicketData;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketLine;
use VenPosTPV\Service\Print\TicketPdfGenerator;
use VenPosTPV\Service\PrintAgent\DrawerAudit;
use VenPosTPV\Service\PrintAgent\PrintJobQueue;

/**
 * docs/prd/agente-impresion.md T0, T1, T8, T10, T11: with printer type `agent`
 * every print path creates a print_job with the exact ESC/POS bytes of today's
 * ticket, and nothing is sent from the server; the drawer is a job without data.
 */
final class PrinterServiceAgentTest extends TestCase
{
    private const AGENT = '{"enabled":true,"type":"agent"}';
    private const SYSTEM = '{"enabled":true,"type":"system","queue":"Epson_TM-T20II"}';

    /** @var list<array<int, mixed>> */
    private array $enqueued = [];

    private function ticket(): TicketData
    {
        return new TicketData(
            orderId: 42, reference: 'A26000042', storeName: 'Tienda', storeAddress: 'Calle 1', ticketHeader: '', ticketFooter: '',
            ticketLogo: '', ticketWidthPx: 576, date: new \DateTimeImmutable('2026-11-03 10:00:00'), cashierName: 'Ana',
            lines: [new TicketLine('Camiseta', 1, 23.14, 23.14)], subtotal: 19.12, tax: 4.02, total: 23.14,
            paymentMethod: 'cash', amountTendered: 30.0, changeDue: 6.86, verificationUrl: '',
        );
    }

    private function queue(): PrintJobQueue
    {
        $queue = $this->createMock(PrintJobQueue::class);
        $queue->method('enqueue')->willReturnCallback(function (...$args): int {
            $this->enqueued[] = $args;

            return 100 + \count($this->enqueued);
        });

        return $queue;
    }

    private function service(string $config, ?PrintJobQueue $queue = null, ?DrawerAudit $audit = null, ?SystemPrintQueue $system = null): PrinterService
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturnCallback(static fn (string $sql): mixed => str_contains($sql, 'printer_config') ? $config : 'Caja 1');
        $conn->expects($this->never())->method('executeStatement');
        $resolver = $this->createMock(TicketDataResolver::class);
        $resolver->method('resolve')->willReturn($this->ticket());
        $resolver->method('resolveRefund')->willReturn($this->ticket());
        $system ??= $this->createMock(SystemPrintQueue::class);

        return new PrinterService($conn, $resolver, $this->builder(), $this->createMock(TicketPdfGenerator::class), $system, $queue ?? $this->queue(), $audit);
    }

    /** The bytes the direct printers get today, marked by gift mode. */
    private function builder(): EscPosTicketBuilder
    {
        $builder = $this->createMock(EscPosTicketBuilder::class);
        $builder->method('build')->willReturnCallback(static fn (TicketData $data, bool $gift = false): string => "\x1B@ESC/POS " . $data->reference . ($gift ? ' gift' : '') . "\x80");

        return $builder;
    }

    public function testTheAgentTypeIsItsOwnPrinter(): void
    {
        $this->assertTrue(PrinterConfig::fromJson(self::AGENT)->usesAgent());
        $this->assertFalse(PrinterConfig::fromJson(self::AGENT)->printsDirectly(), 'never sent from the server');
        $this->assertFalse(PrinterConfig::fromJson('{"enabled":false,"type":"agent"}')->usesAgent());
        $this->assertFalse(PrinterConfig::fromJson(self::SYSTEM)->usesAgent());
    }

    public function testASaleTicketBecomesAJobWithTodaysExactBytes(): void
    {
        $system = $this->createMock(SystemPrintQueue::class);
        $system->expects($this->never())->method('send');
        $service = $this->service(self::AGENT, system: $system);

        $service->printTicket(-15, 3);

        $this->assertSame([[3, 'ticket', $this->builder()->build($this->ticket(), false), 'sale', '-15', null]], $this->enqueued);
        $this->assertSame(101, $service->queuedJobId('ticket'));
        $this->assertTrue($service->usesAgent(3));
    }

    public function testGiftReprintAndCreditNoteAreJobsOfTheAskingTill(): void
    {
        $service = $this->service(self::AGENT);

        $service->printTicket(-15, 3, true);
        $reprint = $service->reprintTicket(-15, 5, false);
        $creditNote = $service->printRefundTicket(8, 5);

        $builder = $this->builder();
        $this->assertSame([3, 'ticket', $builder->build($this->ticket(), true), 'gift', '-15', null], $this->enqueued[0]);
        $this->assertSame([5, 'ticket', $builder->build($this->ticket(), false), 'reprint', '-15', null], $this->enqueued[1], 'T10');
        $this->assertSame(['printed' => false, 'print_status' => 'queued', 'print_job_id' => 102], $reprint);
        $this->assertSame([5, 'ticket', $builder->build($this->ticket()), 'credit_note', '8', null], $this->enqueued[2]);
        $this->assertSame('queued', $creditNote);
        $this->assertSame(103, $service->queuedJobId('ticket'));
    }

    public function testTheDrawerIsAJobWithoutDataAndIsAudited(): void
    {
        $audit = $this->createMock(DrawerAudit::class);
        $audit->expects($this->once())->method('record')->with(3, 'agent', 'queued', 'sale', 7, 15, 'A26000015', 101, true);
        $service = $this->service(self::AGENT, audit: $audit);

        $service->openDrawer(3, 'sale', 7, 15, 'A26000015');

        $this->assertSame([[3, 'drawer', null, 'drawer', '15', 7]], $this->enqueued, 'T8: the agent makes the pulse');
        $this->assertSame(101, $service->queuedJobId('drawer'));
    }

    public function testATestTicketIsAJob(): void
    {
        $id = $this->service(self::AGENT)->printTestTicket(3, 1);

        $this->assertSame(101, $id);
        $this->assertSame([3, 'ticket'], \array_slice($this->enqueued[0], 0, 2));
        $this->assertStringContainsString("Prueba de impresi\xF3n", $this->enqueued[0][2], 'WPC1252 like the ticket');
        $this->assertSame('test', $this->enqueued[0][3]);
    }

    public function testOtherPrintersNeverQueueAndTheirDrawerIsAuditedToo(): void
    {
        $queue = $this->createMock(PrintJobQueue::class);
        $queue->expects($this->never())->method('enqueue');
        $audit = $this->createMock(DrawerAudit::class);
        $audit->expects($this->once())->method('record')->with(1, 'system', 'sent', 'button', null, null, null);
        $system = $this->createMock(SystemPrintQueue::class);
        $system->expects($this->once())->method('send')->with('Epson_TM-T20II', "\x1B\x70\x00\x19\xFA");

        $service = $this->service(self::SYSTEM, $queue, $audit, $system);
        $service->openDrawer(1, 'button');

        $this->assertFalse($service->usesAgent(1));
        $this->assertNull($service->queuedJobId('drawer'));
    }

    public function testATillWithoutPrinterRecordsTheSkippedOpening(): void
    {
        $audit = $this->createMock(DrawerAudit::class);
        $audit->expects($this->once())->method('record')->with(1, 'pdf', 'skipped');

        $this->service('{"enabled":false,"type":"pdf"}', audit: $audit)->openDrawer(1);
    }

    public function testUsesAgentWithoutATillLooksAtTheOldFallbackTill(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())->method('fetchOne')->with($this->stringContains('printer_config'), [1])->willReturn(self::AGENT);
        $service = new PrinterService($conn, $this->createMock(TicketDataResolver::class), $this->builder(), $this->createMock(TicketPdfGenerator::class));

        $this->assertTrue($service->usesAgent(null));
    }
}
