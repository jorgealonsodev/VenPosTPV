<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Exchange;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use VenPosTPV\Service\Cart\CartService;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\TerminalService;
use VenPosTPV\Service\Checkout\CheckoutService;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Exchange\ExchangeService;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketVerificationService;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\OrderSearchService;
use VenPosTPV\Service\Refund\PsOrderLookupClient;
use VenPosTPV\Service\Refund\PsRefundClient;
use VenPosTPV\Service\Refund\RefundService;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * R14 second half: an online order can be exchanged at the shop. Its returned lines
 * become credit for a new TPV sale; a difference in the shop's favour is collected
 * normally, one in the customer's favour is not paid at the till but left as a
 * pending web refund (R10) on the original order and on the credit note.
 */
final class OnlineExchangeMySqlIntegrationTest extends TestCase
{
    private const SESSION_ID = 9401;
    private const ORDER_ID = 940001;

    /** product id => tax-included price */
    private const PRICES = [940005 => 40.0, 940006 => 55.0, 940007 => 30.0];

    private ?Connection $connection = null;

    /** @var array<int, array<string, mixed>> */
    private array $refundPayloads = [];

    /** @var array<int, array<string, mixed>> */
    private array $orderPayloads = [];

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();
        $c = $this->connection;
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->insert('session', ['id' => self::SESSION_ID, 'terminal_id' => 1, 'user_id' => 1, 'opened_at' => '2026-11-03 09:00:00', 'opening_cash' => 100]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        foreach (self::PRICES as $id => $price) {
            $c->insert('catalog_product', ['id' => $id, 'name' => 'Producto ' . $id, 'price' => round($price / 1.21, 6), 'price_ttc' => $price, 'price_net' => round($price / 1.21, 6)]);
            $c->insert('catalog_stock', ['id_product' => $id, 'id_product_attribute' => 0, 'quantity' => 5]);
        }
        $_SESSION = ['shop_id' => 1, 'cash_session_id' => self::SESSION_ID, 'user_id' => 1];
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->clean();
            $this->connection->close();
        }
        $this->connection = null;
        $_SESSION = [];
    }

    private function clean(): void
    {
        $c = $this->connection;
        $c->executeStatement('DELETE FROM exchange WHERE ps_order_id BETWEEN 940000 AND 949999');
        $c->executeStatement('DELETE FROM refund WHERE ps_order_id BETWEEN 940000 AND 949999');
        $c->executeStatement('DELETE FROM credit_note_sequence');
        $c->executeStatement('DELETE FROM movement WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM order_meta WHERE ps_order_id BETWEEN 940000 AND 949999 OR ps_order_id BETWEEN 990000 AND 999999');
        $c->executeStatement('DELETE FROM session WHERE id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM catalog_stock WHERE id_product BETWEEN 940000 AND 949999');
        $c->executeStatement('DELETE FROM catalog_product WHERE id BETWEEN 940000 AND 949999');
    }

    /** A delivered web order: one item of 40 € paid in full. */
    private function onlineOrder(): array
    {
        return [
            'id_order' => self::ORDER_ID, 'reference' => 'WEBORDERA', 'date_add' => (new \DateTimeImmutable('-10 days'))->format('Y-m-d H:i:s'),
            'customer_name' => 'Ana Pérez', 'created_by_tpv' => false, 'current_state' => ['id' => 5, 'name' => 'Entregado'],
            'delivered_at' => (new \DateTimeImmutable('-3 days'))->format('Y-m-d H:i:s'),
            'lines' => [['id_order_detail' => 500, 'product_id' => 940005, 'product_attribute_id' => 0, 'name' => 'Producto 940005', 'quantity' => 1, 'refundable_quantity' => 1, 'paid_total' => 40.0, 'unit_price_tax_incl' => 40.0]],
        ];
    }

    /** @return array<string, mixed> */
    private function cart(int $productId): array
    {
        return ['lines' => [['product_id' => $productId, 'id_product_attribute' => 0, 'qty' => 1, 'price' => self::PRICES[$productId], 'name' => 'Producto ' . $productId]], 'total' => self::PRICES[$productId]];
    }

    private function service(): ExchangeService
    {
        $c = $this->connection;
        $cash = new CashSessionService($c, $this->createMock(TerminalService::class), new RequestStack());

        $lookup = $this->createMock(PsOrderLookupClient::class);
        $lookup->method('lookup')->willReturnCallback(fn (array $q): array => ($q['id_order'] ?? 0) === self::ORDER_ID ? [$this->onlineOrder()] : []);

        $refundClient = $this->createMock(PsRefundClient::class);
        $refundClient->method('createRefund')->willReturnCallback(function (array $payload): array {
            $this->refundPayloads[] = $payload;

            return [
                'order_slip_id' => 840 + \count($this->refundPayloads),
                'refunded_amount' => round(array_sum(array_column($payload['lines'], 'amount')), 2),
                'refunded_lines' => array_map(static fn (array $l): array => $l + ['restocked' => $l['restock']], $payload['lines']),
                'restock_failed' => [],
                'replayed' => false,
            ];
        });

        $orderClient = $this->createMock(PsOrderClient::class);
        $orderClient->method('createOrder')->willReturnCallback(function (array $payload): array {
            $this->orderPayloads[] = $payload;

            return ['order_id' => 990100 + \count($this->orderPayloads), 'reference' => 'NEWWEB' . \count($this->orderPayloads), 'total_paid' => 0.0];
        });

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $refunds = new RefundService(new OrderSearchService($c, $lookup), $refundClient, $c, $cash);
        $checkout = new CheckoutService($this->createMock(CartService::class), $orderClient, $c, $cash, $bus, $this->createMock(PrinterService::class));

        return new ExchangeService($refunds, $checkout, $c, null, $cash);
    }

    /** @return array<int, array{type: string, amount: string}> */
    private function movements(): array
    {
        return $this->connection->fetchAllAssociative('SELECT type, amount FROM movement WHERE session_id = ? ORDER BY id', [self::SESSION_ID]);
    }

    public function testOnlineOrderExchangedForADearerItemCollectsTheBalanceAndOwesNothingOnTheWeb(): void
    {
        $result = $this->service()->processExchange(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size', '', $this->cart(940006), 'cash', 20.0, false);

        $this->assertSame('done', $result['status']);
        $this->assertSame(40.0, $result['credit']);
        $this->assertSame(15.0, $result['balance']);
        $this->assertSame(0.0, $result['refund']['web_refund_amount']);
        $this->assertTrue($result['refund']['online']);
        $this->assertSame('refund', $this->refundPayloads[0]['target_state'], 'nothing owed: no pending web refund');
        $this->assertSame(15.0, $result['sale']['payable']);
        $this->assertEquals([['type' => 'sale', 'amount' => '15.00']], $this->movements(), 'only the balance enters the drawer');
    }

    public function testOnlineOrderExchangedForACheaperItemLeavesTheDifferenceAsAPendingWebRefund(): void
    {
        // No card confirmation: nothing is refunded at the payment terminal.
        $result = $this->service()->processExchange(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size', '', $this->cart(940007), '', 0.0, false);

        $this->assertSame('done', $result['status']);
        $this->assertSame(-10.0, $result['balance']);
        $this->assertSame(0.0, $result['refund']['cash_amount']);
        $this->assertSame(0.0, $result['refund']['card_amount']);
        $this->assertSame(10.0, $result['refund']['web_refund_amount']);
        $this->assertSame('web_refund_pending', $this->refundPayloads[0]['target_state']);
        $this->assertEquals(10.0, $this->refundPayloads[0]['web_refund_amount']);
        $this->assertSame(40.0, $this->refundPayloads[0]['lines'][0]['amount'], 'the slip credits the goods returned');
        $this->assertSame([], $this->movements(), 'no money in or out of the drawer');

        $verification = $this->createMock(TicketVerificationService::class);
        $verification->method('generateToken')->willReturn('tok');
        $verification->method('buildVerificationUrl')->willReturn('http://tpv/verify?token=tok');
        $note = (new TicketDataResolver($this->connection, $verification, 'Tienda', ''))->resolveRefund($result['refund']['refund_id'])?->creditNote;
        $this->assertNotNull($note);
        $this->assertSame(30.0, $note->exchangeCredit);
        $this->assertSame(10.0, $note->webRefundAmount);
        $this->assertSame('Pedido online WEBORDERA', $note->rectifiesReference);
    }

    public function testUndeliveredOnlineOrderCannotBeExchanged(): void
    {
        $order = $this->onlineOrder();
        $order['delivered_at'] = null;
        $lookup = $this->createMock(PsOrderLookupClient::class);
        $lookup->method('lookup')->willReturn([$order]);
        $c = $this->connection;
        $cash = new CashSessionService($c, $this->createMock(TerminalService::class), new RequestStack());
        $refunds = new RefundService(new OrderSearchService($c, $lookup), $this->createMock(PsRefundClient::class), $c, $cash);
        $checkout = $this->createMock(CheckoutService::class);
        $checkout->method('quoteCart')->willReturn(30.0);
        $checkout->expects($this->never())->method('createOrder');
        $service = new ExchangeService($refunds, $checkout, $c, null, $cash);

        try {
            $service->processExchange(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size', '', $this->cart(940007), '', 0.0, false);
            $this->fail('Expected rejection');
        } catch (RefundException $e) {
            $this->assertSame(422, $e->getCode());
        }
        $this->assertSame(0, (int) $c->fetchOne('SELECT COUNT(*) FROM exchange WHERE ps_order_id = ?', [self::ORDER_ID]));
    }
}
