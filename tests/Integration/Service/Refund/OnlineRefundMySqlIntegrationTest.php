<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Refund;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\TerminalService;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketVerificationService;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\OrderSearchService;
use VenPosTPV\Service\Refund\PsOrderLookupClient;
use VenPosTPV\Service\Refund\PsRefundClient;
use VenPosTPV\Service\Refund\RefundService;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * R3, R10, R18: an online order (a PrestaShop order that is not a TPV sale) is
 * refunded at the shop only once delivered and within the window counted from the
 * delivery; the TPV pays nothing, creates the credit slip and leaves the order in
 * "Devuelto en tienda – reembolso pendiente" for the office to refund from the web.
 */
final class OnlineRefundMySqlIntegrationTest extends TestCase
{
    private const SESSION_ID = 9201;
    private const TERMINAL_ID = 9201;
    private const ORDER_ID = 880501;
    private const SHIRT = 880503;
    private const SOCKS = 880504;

    private ?Connection $connection = null;

    /** @var array<int, array<string, mixed>> what the shop knows, by id_order */
    private array $psOrders = [];

    /** @var array<int, array<string, mixed>> */
    private array $lookups = [];

    /** @var array<int, array<string, mixed>> */
    private array $psPayloads = [];

    /** @var array<int, \Throwable|null> */
    private array $psFailures = [];

    private ?\Throwable $lookupFailure = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();
        $c = $this->connection;
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->insert('session', ['id' => self::SESSION_ID, 'terminal_id' => self::TERMINAL_ID, 'user_id' => 1, 'opened_at' => '2026-11-03 09:00:00', 'opening_cash' => 100]);
        $c->insert('terminal', ['id' => self::TERMINAL_ID, 'ps_shop_id' => 1, 'name' => 'Test online', 'refund_window_days' => 30]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $c->insert('catalog_product', ['id' => self::SHIRT, 'name' => 'Camisa', 'price' => 24.79, 'price_ttc' => 30.0, 'price_net' => 24.79]);
        $c->insert('catalog_product', ['id' => self::SOCKS, 'name' => 'Calcetines', 'price' => 3.31, 'price_ttc' => 4.0, 'price_net' => 3.31]);
        $c->insert('catalog_stock', ['id_product' => self::SHIRT, 'id_product_attribute' => 7, 'quantity' => 5]);
        $c->insert('catalog_stock', ['id_product' => self::SOCKS, 'id_product_attribute' => 0, 'quantity' => 5]);
        $this->psOrders[self::ORDER_ID] = $this->psOrder();
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
        $c->executeStatement('DELETE FROM refund WHERE ps_order_id BETWEEN 880500 AND 880599');
        $c->executeStatement('DELETE FROM credit_note_sequence');
        $c->executeStatement('DELETE FROM movement WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM order_meta WHERE ps_order_id BETWEEN 880500 AND 880599');
        $c->executeStatement('DELETE FROM session WHERE id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM terminal WHERE id = ?', [self::TERMINAL_ID]);
        $c->executeStatement('DELETE FROM catalog_stock WHERE id_product BETWEEN 880500 AND 880599');
        $c->executeStatement('DELETE FROM catalog_product WHERE id BETWEEN 880500 AND 880599');
    }

    /**
     * An order as the module's lookup describes it: a shirt that cost 27 after the
     * coupon and three pairs of socks that cost 10 together.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function psOrder(array $overrides = []): array
    {
        return $overrides + [
            'id_order' => self::ORDER_ID,
            'reference' => 'XKBKNABJK',
            'date_add' => (new \DateTimeImmutable('-20 days'))->format('Y-m-d H:i:s'),
            'customer_name' => 'Ana Pérez',
            'created_by_tpv' => false,
            'current_state' => ['id' => 5, 'name' => 'Entregado'],
            'delivered_at' => (new \DateTimeImmutable('-5 days'))->format('Y-m-d H:i:s'),
            'lines' => [
                ['id_order_detail' => 300, 'product_id' => self::SHIRT, 'product_attribute_id' => 7, 'name' => 'Camisa - Talla: M', 'quantity' => 1, 'refundable_quantity' => 1, 'paid_total' => 27.0, 'unit_price_tax_incl' => 30.0],
                ['id_order_detail' => 301, 'product_id' => self::SOCKS, 'product_attribute_id' => 0, 'name' => 'Calcetines', 'quantity' => 3, 'refundable_quantity' => 3, 'paid_total' => 10.0, 'unit_price_tax_incl' => 4.0],
            ],
        ];
    }

    private function searchService(): OrderSearchService
    {
        $lookup = $this->createMock(PsOrderLookupClient::class);
        $lookup->method('lookup')->willReturnCallback(function (array $query): array {
            $this->lookups[] = $query;
            if ($this->lookupFailure !== null) {
                throw $this->lookupFailure;
            }
            if (isset($query['id_order'])) {
                return isset($this->psOrders[$query['id_order']]) ? [$this->psOrders[$query['id_order']]] : [];
            }

            return array_values(array_filter($this->psOrders, static fn (array $o): bool => $o['reference'] === strtoupper((string) $query['reference'])));
        });

        return new OrderSearchService($this->connection, $lookup);
    }

    private function service(): RefundService
    {
        $client = $this->createMock(PsRefundClient::class);
        $client->method('createRefund')->willReturnCallback(function (array $payload): array {
            $this->psPayloads[] = $payload;
            $failure = array_shift($this->psFailures);
            if ($failure !== null) {
                throw $failure;
            }

            return [
                'order_slip_id' => 900 + \count($this->psPayloads),
                'refunded_amount' => round(array_sum(array_column($payload['lines'], 'amount')), 2),
                'refunded_lines' => array_map(static fn (array $l): array => $l + ['restocked' => $l['restock']], $payload['lines']),
                'restock_failed' => [],
                'replayed' => false,
            ];
        });

        return new RefundService($this->searchService(), $client, $this->connection, $this->cash());
    }

    private function cash(): CashSessionService
    {
        $cash = $this->createMock(CashSessionService::class);
        $cash->method('requireOpenSession')->willReturn(['session_id' => self::SESSION_ID, 'terminal_id' => self::TERMINAL_ID, 'user_id' => 1]);

        return $cash;
    }

    private function stock(int $product, int $attribute): int
    {
        return (int) $this->connection->fetchOne('SELECT quantity FROM catalog_stock WHERE id_product = ? AND id_product_attribute = ?', [$product, $attribute]);
    }

    private function movements(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM movement WHERE session_id = ?', [self::SESSION_ID]);
    }

    private function refunds(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM refund WHERE ps_order_id = ?', [self::ORDER_ID]);
    }

    private function expectRefundError(int $code, callable $call, string $messagePart = ''): void
    {
        try {
            $call();
            $this->fail('Expected RefundException ' . $code);
        } catch (RefundException $e) {
            $this->assertSame($code, $e->getCode(), $e->getMessage());
            if ($messagePart !== '') {
                $this->assertStringContainsString($messagePart, $e->getMessage());
            }
        }
    }

    public function testOnlineRefundPaysNothingAtTheTillAndLeavesTheWebRefundPendingInPrestaShop(): void
    {
        $result = $this->service()->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size');

        $this->assertSame(27.0, $result['refunded_amount'], 'what the customer paid after the coupon');
        $this->assertSame(0.0, $result['cash_amount']);
        $this->assertSame(0.0, $result['card_amount']);
        $this->assertTrue($result['online']);
        $this->assertSame(27.0, $result['web_refund_amount']);
        $this->assertNotNull($result['credit_note_number']);

        $payload = $this->psPayloads[0];
        $this->assertSame(self::ORDER_ID, $payload['ps_order_id']);
        $this->assertSame('web_refund_pending', $payload['target_state']);
        $this->assertSame(27.0, $payload['web_refund_amount']);
        $this->assertSame([['id_product' => self::SHIRT, 'id_product_attribute' => 7, 'quantity' => 1, 'amount' => 27.0, 'restock' => true]], $payload['lines']);

        $this->assertSame(0, $this->movements(), 'no cash leaves the drawer');
        $this->assertSame(6, $this->stock(self::SHIRT, 7), 'restocked combination mirrored locally');
        $row = $this->connection->fetchAssociative('SELECT * FROM refund WHERE id = ?', [$result['refund_id']]);
        $this->assertSame('done', $row['status']);
        $this->assertSame(1, (int) $row['online']);
        $this->assertSame('27.00', (string) $row['web_refund_amount']);
        $this->assertSame('0.00', (string) $row['cash_amount']);
        $this->assertSame('0.00', (string) $row['card_amount']);
        $this->assertSame(0, (int) $row['card_confirmed']);
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM order_meta WHERE ps_order_id = ?', [self::ORDER_ID]), 'an online order never becomes a TPV sale');
    }

    public function testTpvSaleRefundStillSendsNoTargetStateAndPaysCash(): void
    {
        $this->connection->insert('order_meta', [
            'ps_order_id' => 880502, 'terminal_id' => self::TERMINAL_ID, 'session_id' => self::SESSION_ID, 'user_id' => 1,
            'payment_breakdown' => json_encode(['reference' => 'TPVSALE01', 'total' => 30.0, 'payment_method' => 'cash', 'amount_tendered' => 30.0, 'change_due' => 0,
                'lines' => [['name' => 'Camisa', 'product_id' => self::SHIRT, 'id_product_attribute' => 7, 'qty' => 1, 'price' => 30.0, 'line_total' => 30.0, 'paid_total' => 30.0]],
                'tender' => ['cash' => 30.0, 'card' => 0.0]], JSON_THROW_ON_ERROR),
            'created_at' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'),
        ]);

        $result = $this->service()->processRefund(1, 880502, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size');

        $this->assertSame(30.0, $result['cash_amount']);
        $this->assertFalse($result['online']);
        $this->assertNull($result['web_refund_amount']);
        $this->assertArrayNotHasKey('target_state', $this->psPayloads[0]);
        $this->assertArrayNotHasKey('web_refund_amount', $this->psPayloads[0]);
        $this->assertSame([], $this->lookups, 'a TPV sale never asks the shop for the order');
        $this->assertSame(1, $this->movements());
    }

    public function testWindowCountsFromTheDeliveryDateNotTheOrderDate(): void
    {
        $this->psOrders[self::ORDER_ID] = $this->psOrder([
            'date_add' => (new \DateTimeImmutable('-60 days'))->format('Y-m-d H:i:s'),
            'delivered_at' => (new \DateTimeImmutable('-29 days'))->format('Y-m-d H:i:s'),
        ]);
        $result = $this->service()->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size');
        $this->assertSame(27.0, $result['web_refund_amount']);

        $delivered = new \DateTimeImmutable('-31 days');
        $this->psOrders[self::ORDER_ID] = $this->psOrder(['delivered_at' => $delivered->format('Y-m-d H:i:s')]);
        $this->expectRefundError(
            422,
            fn () => $this->service()->processRefund(1, self::ORDER_ID, [['line_index' => 1, 'quantity' => 1, 'restock' => true]], 'size'),
            'outside the 30-day refund window (delivered on ' . $delivered->format('d/m/Y') . ')'
        );
        $this->assertCount(1, $this->psPayloads);
    }

    public function testUndeliveredOnlineOrderIsRejectedWithoutTouchingAnything(): void
    {
        $this->psOrders[self::ORDER_ID] = $this->psOrder(['delivered_at' => null, 'current_state' => ['id' => 4, 'name' => 'Enviado']]);

        $this->expectRefundError(
            422,
            fn () => $this->service()->processRefund(1, self::ORDER_ID, null, 'size'),
            'Only delivered online orders can be refunded at the shop (current state: Enviado)'
        );
        $this->assertSame([], $this->psPayloads);
        $this->assertSame(0, $this->refunds());
    }

    public function testOrderCreatedByATpvThatThisTpvDoesNotKnowIsRejected(): void
    {
        $this->psOrders[self::ORDER_ID] = $this->psOrder(['created_by_tpv' => true]);

        $this->expectRefundError(422, fn () => $this->service()->processRefund(1, self::ORDER_ID, null, 'size'), 'back office');
        $this->assertSame(0, $this->refunds());
    }

    public function testUnknownOrderIs404AndShopDownBlocksWithNothingRecorded(): void
    {
        $this->expectRefundError(404, fn () => $this->service()->processRefund(1, 880599, null, 'size'));

        $this->lookupFailure = RefundException::psUnavailable('Connection refused');
        $this->expectRefundError(0, fn () => $this->service()->processRefund(1, self::ORDER_ID, null, 'size'));
        $this->assertSame([], $this->psPayloads);
        $this->assertSame(0, $this->refunds());
    }

    public function testRefundableQuantityComesFromTheShopAndTheLastUnitsTakeTheRemainder(): void
    {
        $service = $this->service();
        $first = $service->processRefund(1, self::ORDER_ID, [['line_index' => 1, 'quantity' => 1, 'restock' => false]], 'dislike');
        $this->assertSame(3.33, $first['web_refund_amount']);
        $this->assertSame(5, $this->stock(self::SOCKS, 0), 'not restocked when the cashier says so');

        // The shop now counts the slip: 2 left.
        $this->psOrders[self::ORDER_ID]['lines'][1]['refundable_quantity'] = 2;
        $this->expectRefundError(422, fn () => $service->processRefund(1, self::ORDER_ID, [['line_index' => 1, 'quantity' => 3, 'restock' => true]], 'dislike'), 'Only 2 unit(s)');

        $last = $service->processRefund(1, self::ORDER_ID, [['line_index' => 1, 'quantity' => 2, 'restock' => true]], 'dislike');
        $this->assertSame(6.67, $last['web_refund_amount']);
        $this->assertSame(10.0, round($first['refunded_amount'] + $last['refunded_amount'], 2));
    }

    public function testBrowserAmountsAreIgnored(): void
    {
        $result = $this->service()->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true, 'amount' => 999, 'paid_total' => 999]], 'size');

        $this->assertSame(27.0, $result['refunded_amount']);
    }

    public function testUnknownShopOutcomeStaysPendingAndTheRetryResendsTheSameRequest(): void
    {
        $service = $this->service();
        $this->psFailures = [RefundException::psUnavailable('timeout')];

        $this->expectRefundError(0, fn () => $service->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size'));
        $pending = $this->connection->fetchAssociative('SELECT id, status FROM refund WHERE ps_order_id = ?', [self::ORDER_ID]);
        $this->assertSame('pending', $pending['status']);
        $this->assertSame(['id' => (int) $pending['id'], 'total' => 27.0], $this->searchService()->getOnlineOrder(self::ORDER_ID)['pending_refund']);

        $this->expectRefundError(409, fn () => $service->processRefund(1, self::ORDER_ID, [['line_index' => 1, 'quantity' => 1, 'restock' => true]], 'size'));

        $retried = $service->retryPending(1, (int) $pending['id']);

        $this->assertCount(2, $this->psPayloads);
        // JSON round trip of the stored payload: 27.0 comes back as 27, same as TPV refunds.
        $this->assertEquals($this->psPayloads[0], $this->psPayloads[1], 'same request_id, target state and amounts');
        $this->assertSame('web_refund_pending', $this->psPayloads[1]['target_state']);
        $this->assertSame(27.0, $retried['web_refund_amount']);
        $this->assertSame(0, $this->movements());
        $this->assertSame(6, $this->stock(self::SHIRT, 7), 'restocked once');
    }

    public function testCreditNoteShowsTheOnlineOrderTheLineNamesAndThePendingWebRefund(): void
    {
        $refund = $this->service()->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size');
        $verification = $this->createMock(TicketVerificationService::class);
        $verification->method('generateToken')->willReturn('tok');
        $verification->method('buildVerificationUrl')->willReturn('http://tpv/verify?token=tok');

        $ticket = (new TicketDataResolver($this->connection, $verification, 'Tienda Test', 'Calle 1'))->resolveRefund($refund['refund_id']);

        $this->assertNotNull($ticket);
        $note = $ticket->creditNote;
        $this->assertNotNull($note);
        $this->assertSame('Pedido online XKBKNABJK', $note->rectifiesReference);
        $this->assertSame($this->psOrders[self::ORDER_ID]['date_add'], $note->rectifiesDate?->format('Y-m-d H:i:s'));
        $this->assertSame(27.0, $note->webRefundAmount);
        $this->assertSame(0.0, $note->cashAmount);
        $this->assertSame(0.0, $note->cardAmount);
        $this->assertSame('Camisa - Talla: M', $ticket->lines[0]->name);
        $this->assertSame(27.0, $ticket->total);
        $this->assertSame(4.69, $ticket->tax, 'VAT share of the catalog price (21 %)');
    }

    public function testCashReportCountsTheOnlineRefundApartWithoutMovingMoney(): void
    {
        $_SESSION = ['cash_session_id' => self::SESSION_ID];
        $report = fn (): array => (new CashSessionService($this->connection, $this->createMock(TerminalService::class), new RequestStack()))->getXReport(1)['totals'];
        $before = $report();

        $this->service()->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size');
        $after = $report();

        foreach (['expected_cash', 'refunds_cash', 'refunds_card', 'refunds_voucher', 'refunds_total', 'net_total'] as $key) {
            $this->assertSame($before[$key], $after[$key], $key . ' does not change');
        }
        $this->assertSame(0.0, $before['refunds_web_pending']);
        $this->assertSame(27.0, $after['refunds_web_pending']);
        $this->assertSame($before['counts']['refunds'] + 1, $after['counts']['refunds']);
    }

    public function testSearchFallsBackToTheShopForOnlineOrdersByReferenceOrNumber(): void
    {
        $search = $this->searchService();

        $byReference = $search->search(['reference' => 'xkbknabjk']);
        $this->assertCount(1, $byReference);
        $order = $byReference[0];
        $this->assertTrue($order['online']);
        $this->assertSame(self::ORDER_ID, $order['ps_order_id']);
        $this->assertSame('XKBKNABJK', $order['reference']);
        $this->assertSame('Ana Pérez', $order['customer_name']);
        $this->assertSame('Entregado', $order['state']);
        $this->assertSame($this->psOrders[self::ORDER_ID]['delivered_at'], $order['delivered_at']);
        $this->assertSame(37.0, $order['total']);
        $this->assertNull($order['tender']);
        $this->assertSame(['name' => 'Calcetines', 'product_id' => self::SOCKS, 'id_product_attribute' => 0, 'id_order_detail' => 301, 'qty' => 3, 'price' => 4.0, 'paid_total' => 10.0, 'refunded_qty' => 0, 'refunded_line_amount' => 0.0, 'refundable_qty' => 3], $order['lines'][1]);

        $this->assertSame(self::ORDER_ID, $search->search(['ps_order_id' => self::ORDER_ID])[0]['ps_order_id']);
        $this->assertSame([], $search->search(['ps_order_id' => 880599]));
        $this->assertSame([], $search->search(['reference' => 'no']), 'not a web reference: the shop is not asked');
        $this->assertSame([['id_order' => self::ORDER_ID]], array_slice($this->lookups, 1, 1));
        $this->assertCount(3, $this->lookups);
    }
}
