<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Print\EscPosTicketBuilder;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\SystemPrintQueue;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketPdfGenerator;
use VenPosTPV\Service\PrintAgent\PrintJobQueue;

/**
 * printRaw(): ready ESC/POS bytes (the cash reports) go to the till's printer with
 * the same resolution as a reprint: the print agent queue, a direct printer, or
 * the PDF when the till has none.
 */
final class PrinterServiceRawTest extends TestCase
{
    /** @var list<array<int, mixed>> */
    private array $enqueued = [];

    private function service(string $config, ?SystemPrintQueue $system = null, ?PrintJobQueue $queue = null): PrinterService
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturnCallback(static fn (string $sql): mixed => str_contains($sql, 'printer_config') ? $config : false);
        if ($queue === null) {
            $queue = $this->createMock(PrintJobQueue::class);
            $queue->method('enqueue')->willReturnCallback(function (...$args): int {
                $this->enqueued[] = $args;

                return 70;
            });
        }

        return new PrinterService(
            $conn,
            $this->createMock(TicketDataResolver::class),
            $this->createMock(EscPosTicketBuilder::class),
            $this->createMock(TicketPdfGenerator::class),
            $system ?? $this->createMock(SystemPrintQueue::class),
            $queue,
        );
    }

    public function testAnAgentTillGetsAJobWithTheExactBytes(): void
    {
        $result = $this->service('{"enabled":true,"type":"agent"}')->printRaw(3, "\x1B@INFORME Z", 'cash_report', 'Z:41', 9);

        $this->assertSame(['print_status' => 'queued', 'print_job_id' => 70], $result);
        $this->assertSame([[3, 'ticket', "\x1B@INFORME Z", 'cash_report', 'Z:41', 9]], $this->enqueued);
    }

    public function testADirectPrinterPrintsAtOnce(): void
    {
        $system = $this->createMock(SystemPrintQueue::class);
        $system->expects($this->once())->method('send')->with('Epson_TM-T20II', "\x1B@INFORME X");
        $queue = $this->createMock(PrintJobQueue::class);
        $queue->expects($this->never())->method('enqueue');

        $result = $this->service('{"enabled":true,"type":"system","queue":"Epson_TM-T20II"}', $system, $queue)->printRaw(3, "\x1B@INFORME X", 'cash_report');

        $this->assertSame(['print_status' => 'printed'], $result);
    }

    public function testAFailingDirectPrinterFallsBackToThePdf(): void
    {
        $system = $this->createMock(SystemPrintQueue::class);
        $system->method('send')->willThrowException(new \RuntimeException('lp failed'));

        $result = $this->service('{"enabled":true,"type":"system","queue":"Epson_TM-T20II"}', $system)->printRaw(3, 'x', 'cash_report');

        $this->assertSame(['fallback' => 'pdf'], $result);
    }

    public function testATillWithoutPrinterGetsThePdf(): void
    {
        $queue = $this->createMock(PrintJobQueue::class);
        $queue->expects($this->never())->method('enqueue');

        $this->assertSame(['fallback' => 'pdf'], $this->service('{"enabled":false,"type":"agent"}', null, $queue)->printRaw(3, 'x', 'cash_report'));
        $this->assertSame(['fallback' => 'pdf'], $this->service('', null, $queue)->printRaw(3, 'x', 'cash_report'));
    }

    public function testThePaperWidthComesFromTheTill(): void
    {
        $this->assertSame(58, $this->service('{"enabled":true,"type":"agent","paper_width":58}')->paperWidth(3));
        $this->assertSame(80, $this->service('{"enabled":true,"type":"agent"}')->paperWidth(3));
    }
}
