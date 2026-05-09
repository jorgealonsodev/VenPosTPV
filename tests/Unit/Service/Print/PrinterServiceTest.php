<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Print\EscPosTicketBuilder;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\TicketData;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketLine;
use VenPosTPV\Service\Print\TicketPdfGenerator;

final class PrinterServiceTest extends TestCase
{
    private function createTicketData(): TicketData
    {
        return new TicketData(
            orderId: 42,
            reference: 'ORD-042',
            storeName: 'Mi Tienda',
            storeAddress: 'Calle Falsa 123',
            date: new \DateTimeImmutable('2024-01-15 14:30:00'),
            cashierName: 'Juan',
            lines: [new TicketLine('Café', 1, 1.5, 1.5)],
            subtotal: 1.5,
            tax: 0.1,
            total: 1.6,
            paymentMethod: 'cash',
            amountTendered: 2.0,
            changeDue: 0.4,
            verificationUrl: 'https://example.com/verify?token=abc',
        );
    }

    private function createService(
        ?Connection $conn = null,
        ?TicketDataResolver $resolver = null,
        ?EscPosTicketBuilder $builder = null,
        ?TicketPdfGenerator $pdfGenerator = null,
    ): PrinterService {
        return new PrinterService(
            $conn ?? $this->createMock(Connection::class),
            $resolver ?? $this->createMock(TicketDataResolver::class),
            $builder ?? $this->createMock(EscPosTicketBuilder::class),
            $pdfGenerator ?? $this->createMock(TicketPdfGenerator::class),
        );
    }

    public function testPrintWithDisabledPrinterFallsBackToPdf(): void
    {
        $ticketData = $this->createTicketData();

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $params) {
                if (str_contains($sql, 'printer_config')) {
                    return json_encode(['enabled' => false]);
                }

                return false;
            });
        $conn->expects($this->once())->method('insert')
            ->with('audit_log', $this->anything());

        $resolver = $this->createMock(TicketDataResolver::class);
        $resolver->method('resolve')->willReturn($ticketData);

        $pdfGenerator = $this->createMock(TicketPdfGenerator::class);
        $pdfGenerator->expects($this->once())->method('generate')
            ->with($ticketData)
            ->willReturn('pdf_bytes');

        $service = $this->createService(conn: $conn, resolver: $resolver, pdfGenerator: $pdfGenerator);
        $service->printTicket(42, 1);
    }

    public function testPrintWithNetworkPrinterFailureFallsBackToPdf(): void
    {
        $ticketData = $this->createTicketData();

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'printer_config')) {
                    return json_encode(['type' => 'network', 'host' => '192.168.1.100', 'port' => 9100, 'enabled' => true]);
                }

                return false;
            });
        $conn->expects($this->exactly(2))->method('insert')
            ->with('audit_log', $this->anything());

        $resolver = $this->createMock(TicketDataResolver::class);
        $resolver->method('resolve')->willReturn($ticketData);

        $builder = $this->createMock(EscPosTicketBuilder::class);
        $builder->method('build')->willReturn('escpos_bytes');

        $pdfGenerator = $this->createMock(TicketPdfGenerator::class);
        $pdfGenerator->expects($this->once())->method('generate')
            ->willReturn('pdf_bytes');

        $service = $this->createService(conn: $conn, resolver: $resolver, builder: $builder, pdfGenerator: $pdfGenerator);
        $service->printTicket(42, 1);
    }

    public function testPrintEscPosSuccessMarksAsPrinted(): void
    {
        $ticketData = $this->createTicketData();

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'printer_config')) {
                    return json_encode(['type' => 'network', 'host' => '127.0.0.1', 'port' => 9100, 'enabled' => true]);
                }

                return false;
            });
        $conn->expects($this->once())->method('executeStatement')
            ->with(
                'UPDATE order_meta SET printed = 1, printed_at = NOW(), print_attempts = print_attempts + 1 WHERE ps_order_id = ?',
                [42]
            );

        $resolver = $this->createMock(TicketDataResolver::class);
        $resolver->method('resolve')->willReturn($ticketData);

        $builder = $this->createMock(EscPosTicketBuilder::class);
        $builder->method('build')->willReturn('escpos_bytes');

        $pdfGenerator = $this->createMock(TicketPdfGenerator::class);
        $pdfGenerator->expects($this->never())->method('generate');

        // Create a testable subclass that bypasses real network call
        $service = new class ($conn, $resolver, $builder, $pdfGenerator) extends PrinterService {
            protected function sendEscPos(\VenPosTPV\Service\Print\TicketData $ticketData, \VenPosTPV\Service\Print\PrinterConfig $config, bool $giftMode = false): void
            {
                // Simulate success without real network
            }
        };

        $service->printTicket(42, 1);
    }

    public function testReprintWithNoPrinterReturnsPdf(): void
    {
        $ticketData = $this->createTicketData();

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'printer_config')) {
                    return null;
                }

                return false;
            });

        $resolver = $this->createMock(TicketDataResolver::class);
        $resolver->method('resolve')->willReturn($ticketData);

        $pdfGenerator = $this->createMock(TicketPdfGenerator::class);
        $pdfGenerator->expects($this->once())->method('generate')
            ->willReturn('pdf_bytes');

        $service = $this->createService(conn: $conn, resolver: $resolver, pdfGenerator: $pdfGenerator);
        $result = $service->reprintTicket(42, 1);

        self::assertFalse($result['printed']);
        self::assertSame('pdf', $result['fallback']);
    }

    public function testReprintWithGiftModePassesGiftModeToPdfGenerator(): void
    {
        $ticketData = $this->createTicketData();

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'printer_config')) {
                    return null;
                }

                return false;
            });

        $resolver = $this->createMock(TicketDataResolver::class);
        $resolver->method('resolve')->willReturn($ticketData);

        $pdfGenerator = $this->createMock(TicketPdfGenerator::class);
        $pdfGenerator->expects($this->once())
            ->method('generate')
            ->with($ticketData, true)
            ->willReturn('pdf_bytes');

        $service = $this->createService(conn: $conn, resolver: $resolver, pdfGenerator: $pdfGenerator);
        $result = $service->reprintTicket(42, 1, true);

        self::assertFalse($result['printed']);
    }

    public function testReprintWithoutGiftModeDefaultsToFalse(): void
    {
        $ticketData = $this->createTicketData();

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'printer_config')) {
                    return null;
                }

                return false;
            });

        $resolver = $this->createMock(TicketDataResolver::class);
        $resolver->method('resolve')->willReturn($ticketData);

        $pdfGenerator = $this->createMock(TicketPdfGenerator::class);
        $pdfGenerator->expects($this->once())
            ->method('generate')
            ->with($ticketData, false)
            ->willReturn('pdf_bytes');

        $service = $this->createService(conn: $conn, resolver: $resolver, pdfGenerator: $pdfGenerator);
        $service->reprintTicket(42, 1);
    }

    public function testPrintTicketWithGiftModeAndDisabledPrinterPassesGiftModeToPdfGenerator(): void
    {
        $ticketData = $this->createTicketData();

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'printer_config')) {
                    return json_encode(['enabled' => false]);
                }

                return false;
            });
        $conn->expects($this->once())->method('insert')
            ->with('audit_log', $this->anything());

        $resolver = $this->createMock(TicketDataResolver::class);
        $resolver->method('resolve')->willReturn($ticketData);

        $pdfGenerator = $this->createMock(TicketPdfGenerator::class);
        $pdfGenerator->expects($this->once())
            ->method('generate')
            ->with($ticketData, true)
            ->willReturn('pdf_bytes');

        $service = $this->createService(conn: $conn, resolver: $resolver, pdfGenerator: $pdfGenerator);
        $service->printTicket(42, 1, true);
    }

    public function testOpenDrawerWithEnabledNetworkPrinterSendsKickCommand(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'printer_config')) {
                    return json_encode(['type' => 'network', 'host' => '127.0.0.1', 'port' => 9100, 'enabled' => true]);
                }

                return false;
            });
        $conn->expects($this->once())->method('insert')
            ->with('audit_log', $this->anything());

        // Create a testable subclass that bypasses real network call
        $service = new class ($conn, $this->createMock(TicketDataResolver::class), $this->createMock(EscPosTicketBuilder::class), $this->createMock(TicketPdfGenerator::class)) extends PrinterService {
            private bool $drawerKickSent = false;

            protected function sendDrawerKick(\VenPosTPV\Service\Print\PrinterConfig $config): void
            {
                $this->drawerKickSent = true;
            }

            public function wasDrawerKickSent(): bool
            {
                return $this->drawerKickSent;
            }
        };

        $service->openDrawer(1);
        self::assertTrue($service->wasDrawerKickSent());
    }

    public function testOpenDrawerWithDisabledPrinterSkipsAndLogs(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'printer_config')) {
                    return json_encode(['enabled' => false]);
                }

                return false;
            });
        $conn->expects($this->once())->method('insert')
            ->with('audit_log', $this->callback(function (array $data): bool {
                return $data['action'] === 'drawer_open_skipped';
            }));

        $service = $this->createService(conn: $conn);
        $service->openDrawer(1);
    }
}
