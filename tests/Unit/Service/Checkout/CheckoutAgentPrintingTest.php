<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Checkout;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use VenPosTPV\Message\PrintTicketMessage;
use VenPosTPV\Service\Cart\CartService;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Checkout\CheckoutService;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Service\Sync\Outbox;

/**
 * docs/prd/agente-impresion.md T0, T9, T11: a till that prints through its agent
 * gets its ticket and drawer jobs in the checkout request itself, never through
 * Messenger and never on till 1 by default; other printers keep the Messenger path.
 */
final class CheckoutAgentPrintingTest extends TestCase
{
    private const ORDER_ID = 15;

    /** @var list<object> */
    private array $dispatched = [];

    /** @param array<string, mixed> $cashContext */
    private function service(PrinterService $printer, array $cashContext): CheckoutService
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation($conn));
        $conn->method('isTransactionActive')->willReturn(true);
        $conn->method('lastInsertId')->willReturn((string) self::ORDER_ID);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('fetchAssociative')->willReturn(['price' => 10.0, 'price_ttc' => 12.1]);
        $conn->method('insert')->willReturn(1);
        $conn->method('executeStatement')->willReturn(1);
        $cash = $this->createMock(CashSessionService::class);
        $cash->method('requireOpenSession')->willReturn($cashContext);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });
        $numberer = $this->createMock(TicketNumberer::class);
        $numberer->method('next')->willReturn('A26000001');

        return new CheckoutService($this->createMock(CartService::class), $this->createMock(PsOrderClient::class), $conn, $cash, $bus, $printer, false, null, $numberer, $this->createMock(Outbox::class));
    }

    /** @return array<string, mixed> */
    private static function cart(): array
    {
        return ['lines' => [['product_id' => 1, 'qty' => 1, 'price' => 12.1]], 'total' => 12.1];
    }

    public function testAnAgentTillQueuesTicketAndDrawerInTheRequestWithoutMessenger(): void
    {
        $printer = $this->createMock(PrinterService::class);
        $printer->method('usesAgent')->with(3)->willReturn(true);
        $printer->expects($this->once())->method('printTicket')->with(TicketDataResolver::saleKey(self::ORDER_ID), 3);
        $printer->expects($this->once())->method('openDrawer')->with(3, 'sale', 7, self::ORDER_ID, 'A26000001');
        $printer->method('queuedJobId')->willReturnMap([['ticket', 41], ['drawer', 42]]);

        $result = $this->service($printer, ['session_id' => 12, 'terminal_id' => 3])->createOrder(7, self::cart(), 'cash', 20.0);

        $this->assertSame([], $this->dispatched, 'T0: never through Messenger');
        $this->assertSame('queued', $result['print_status']);
        $this->assertSame(41, $result['print_job_id']);
        $this->assertSame('queued', $result['drawer_status']);
        $this->assertSame(42, $result['drawer_job_id']);
        $this->assertSame(self::ORDER_ID, $result['order_id'], 'existing fields are kept');
    }

    public function testACardSaleOnAnAgentTillQueuesOnlyTheTicket(): void
    {
        $printer = $this->createMock(PrinterService::class);
        $printer->method('usesAgent')->willReturn(true);
        $printer->expects($this->once())->method('printTicket');
        $printer->expects($this->never())->method('openDrawer');
        $printer->method('queuedJobId')->willReturnMap([['ticket', 41], ['drawer', null]]);

        $result = $this->service($printer, ['session_id' => 12, 'terminal_id' => 3])->createOrder(7, self::cart(), 'card', 0.0);

        $this->assertSame(['queued', 41], [$result['print_status'], $result['print_job_id']]);
        $this->assertArrayNotHasKey('drawer_status', $result);
    }

    public function testWithoutATillAnAgentJobIsNeverCreatedOnTillOne(): void
    {
        $printer = $this->createMock(PrinterService::class);
        $printer->method('usesAgent')->with(null)->willReturn(true);
        $printer->expects($this->never())->method('printTicket');
        $printer->expects($this->never())->method('openDrawer');

        $result = $this->service($printer, ['session_id' => 12])->createOrder(7, self::cart(), 'cash', 20.0);

        $this->assertSame([], $this->dispatched);
        $this->assertSame('failed', $result['print_status']);
        $this->assertSame('terminal_not_resolved', $result['print_error']);
    }

    public function testAQueueFailureNeverBlocksTheSale(): void
    {
        $printer = $this->createMock(PrinterService::class);
        $printer->method('usesAgent')->willReturn(true);
        $printer->method('printTicket')->willThrowException(new \RuntimeException('db down'));
        $printer->method('openDrawer')->willThrowException(new \RuntimeException('db down'));

        $result = $this->service($printer, ['session_id' => 12, 'terminal_id' => 3])->createOrder(7, self::cart(), 'cash', 20.0);

        $this->assertSame(self::ORDER_ID, $result['order_id']);
        $this->assertSame(['failed', 'print_job_failed', 'failed'], [$result['print_status'], $result['print_error'], $result['drawer_status']]);
    }

    public function testOtherPrintersKeepTheMessengerTicketAndAddNoPrintFields(): void
    {
        $printer = $this->createMock(PrinterService::class);
        $printer->method('usesAgent')->willReturn(false);
        $printer->expects($this->never())->method('printTicket');
        $printer->expects($this->once())->method('openDrawer')->with(3);

        $result = $this->service($printer, ['session_id' => 12, 'terminal_id' => 3])->createOrder(7, self::cart(), 'cash', 20.0);

        $this->assertEquals([new PrintTicketMessage(TicketDataResolver::saleKey(self::ORDER_ID), 3)], $this->dispatched);
        $this->assertArrayNotHasKey('print_status', $result);
        $this->assertArrayNotHasKey('print_job_id', $result);
    }
}
