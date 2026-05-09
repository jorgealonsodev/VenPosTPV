<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\MessageHandler;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use VenPosTPV\Message\PrintTicketMessage;
use VenPosTPV\MessageHandler\PrintTicketMessageHandler;
use VenPosTPV\Service\Print\PrinterService;

final class PrintTicketMessageHandlerTest extends TestCase
{
    public function testInvokeCallsPrinterService(): void
    {
        $printerService = $this->createMock(PrinterService::class);
        $printerService->expects($this->once())
            ->method('printTicket')
            ->with(42, 3);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');

        $handler = new PrintTicketMessageHandler($printerService, $logger);
        $handler(new PrintTicketMessage(42, 3));
    }

    public function testInvokeLogsAndRethrowsOnFailure(): void
    {
        $printerService = $this->createMock(PrinterService::class);
        $printerService->expects($this->once())
            ->method('printTicket')
            ->willThrowException(new \RuntimeException('Printer offline'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $handler = new PrintTicketMessageHandler($printerService, $logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Printer offline');

        $handler(new PrintTicketMessage(42, 3));
    }
}
