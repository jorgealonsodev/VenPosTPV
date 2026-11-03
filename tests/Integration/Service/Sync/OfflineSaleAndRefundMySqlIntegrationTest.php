<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Sync;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use VenPosTPV\Service\Cart\CartService;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\TerminalService;
use VenPosTPV\Service\Checkout\CheckoutService;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\OrderSearchService;
use VenPosTPV\Service\Refund\PsRefundClient;
use VenPosTPV\Service\Refund\RefundService;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Service\Sync\Outbox;
use VenPosTPV\Service\Sync\OutboxSync;
use VenPosTPV\Service\Sync\OutboxSyncTrigger;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * PRD venta-local-primero A3: "Con PrestaShop parado, se vende y se devuelve esa misma
 * venta: al volver la conexión se crean el pedido y después el abono, y el stock final
 * es correcto." The fake PrestaShop keeps its own stock: creating the order lowers it,
 * a slip with restock raises it, and nothing works while it is down.
 */
final class OfflineSaleAndRefundMySqlIntegrationTest extends TestCase
{
    private const TERMINAL_ID = 9404;
    private const LETTER = 'P';
    private const SESSION_ID = 9404;
    private const PRODUCT = 940401;
    private const PRICE = 12.10;

    private ?Connection $connection = null;

    private bool $shopDown = true;

    private int $shopStock = 5;

    /** @var list<string> what reached PrestaShop, in order */
    private array $calls = [];

    /** @var list<array<string, mixed>> */
    private array $slips = [];

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();
        $c = $this->connection;
        $c->insert('terminal', ['id' => self::TERMINAL_ID, 'ps_shop_id' => 1, 'name' => 'A3', 'ticket_letter' => self::LETTER]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->insert('session', ['id' => self::SESSION_ID, 'terminal_id' => self::TERMINAL_ID, 'user_id' => 1, 'opened_at' => '2026-11-03 09:00:00', 'opening_cash' => 100]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $c->insert('catalog_product', ['id' => self::PRODUCT, 'name' => 'Sudadera', 'price' => 10.0, 'price_ttc' => self::PRICE, 'price_net' => 10.0]);
        $c->insert('catalog_stock', ['id_product' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 5]);
        $_SESSION = ['shop_id' => 1, 'cash_session_id' => self::SESSION_ID, 'user_id' => 1, 'terminal_id' => self::TERMINAL_ID];
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
        foreach ($c->fetchFirstColumn('SELECT id FROM order_meta WHERE session_id = ?', [self::SESSION_ID]) as $id) {
            $c->executeStatement('DELETE FROM outbox WHERE order_id = ?', [(int) $id]);
            $c->executeStatement('DELETE FROM refund_line WHERE refund_id IN (SELECT id FROM refund WHERE order_id = ?)', [(int) $id]);
            $c->executeStatement('DELETE FROM refund WHERE order_id = ?', [(int) $id]);
        }
        $c->executeStatement('DELETE FROM credit_note_sequence');
        $c->executeStatement('DELETE FROM movement WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM order_meta WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM session WHERE id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM ticket_sequence WHERE letter = ?', [self::LETTER]);
        $c->executeStatement('DELETE FROM terminal WHERE id = ?', [self::TERMINAL_ID]);
        $c->executeStatement('DELETE FROM catalog_stock WHERE id_product = ?', [self::PRODUCT]);
        $c->executeStatement('DELETE FROM catalog_product WHERE id = ?', [self::PRODUCT]);
    }

    private function orderClient(): PsOrderClient
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createOrder');
        $client->method('createReferencedOrder')->willReturnCallback(function (array $payload): array {
            if ($this->shopDown) {
                throw CheckoutException::psUnavailable('Connection refused');
            }
            $this->calls[] = 'order ' . $payload['reference'];
            foreach ($payload['products'] as $product) {
                $this->shopStock -= (int) $product['quantity'];
            }

            return ['order_id' => 7404, 'reference' => (string) $payload['reference'], 'total_paid' => 24.2, 'replayed' => false];
        });

        return $client;
    }

    private function refundClient(): PsRefundClient
    {
        $client = $this->createMock(PsRefundClient::class);
        $client->method('createRefund')->willReturnCallback(function (array $payload): array {
            if ($this->shopDown) {
                throw RefundException::psUnavailable('Connection refused');
            }
            $this->calls[] = 'slip ' . $payload['ps_order_id'];
            $this->slips[] = $payload;
            foreach ($payload['lines'] as $line) {
                if ($line['restock']) {
                    $this->shopStock += (int) $line['quantity'];
                }
            }

            return [
                'order_slip_id' => 8404,
                'refunded_amount' => round(array_sum(array_column($payload['lines'], 'amount')), 2),
                'refunded_lines' => array_map(static fn (array $l): array => $l + ['restocked' => $l['restock']], $payload['lines']),
                'restock_failed' => [],
                'replayed' => false,
            ];
        });

        return $client;
    }

    private function cash(): CashSessionService
    {
        return new CashSessionService($this->connection, $this->createMock(TerminalService::class), new RequestStack());
    }

    private function checkout(): CheckoutService
    {
        $c = $this->connection;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        return new CheckoutService($this->createMock(CartService::class), $this->orderClient(), $c, $this->cash(), $bus, $this->createMock(PrinterService::class), false, null, new TicketNumberer($c), new Outbox($c), new OutboxSyncTrigger());
    }

    private function refunds(): RefundService
    {
        return new RefundService(new OrderSearchService($this->connection), $this->refundClient(), $this->connection, $this->cash());
    }

    /** @return array{uploaded: int, retried: int, incidents: int, recovered: int} */
    private function sync(int $minutesLater): array
    {
        $at = new \DateTimeImmutable(sprintf('+%d minutes', $minutesLater));

        return (new OutboxSync($this->connection, $this->orderClient(), null, fn (): \DateTimeImmutable => $at, $this->refundClient()))->run();
    }

    private function localStock(): int
    {
        return (int) $this->connection->fetchOne('SELECT quantity FROM catalog_stock WHERE id_product = ? AND id_product_attribute = 0', [self::PRODUCT]);
    }

    public function testSaleAndItsRefundWithPrestaShopDownUploadInOrderAndLeaveTheStockRight(): void
    {
        $sale = $this->checkout()->createOrder(1, [
            'lines' => [['product_id' => self::PRODUCT, 'id_product_attribute' => 0, 'qty' => 2, 'price' => self::PRICE, 'name' => 'Sudadera']],
            'total' => 24.2,
            'cash_amount' => 24.2,
        ], 'cash', 30.0);
        $this->assertNull($sale['ps_order_id']);

        // Refunded by its local id: it has no PrestaShop order yet (PRD D1).
        $refund = $this->refunds()->processSaleRefund(1, $sale['order_id'], [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size');

        $this->assertNotNull($refund['credit_note_number'], 'numbered and printable at once (D2)');
        $this->assertTrue($refund['queued']);
        $this->assertSame($sale['order_id'], $refund['order_id']);
        $this->assertNull($refund['ps_order_id']);
        $this->assertSame(4, $this->localStock(), '5 - 2 sold + 1 back');
        $this->assertEquals(
            [['type' => 'sale', 'amount' => '24.20'], ['type' => 'refund', 'amount' => '12.10']],
            $this->connection->fetchAllAssociative('SELECT type, amount FROM movement WHERE session_id = ? ORDER BY id', [self::SESSION_ID])
        );
        $this->assertSame([1, 2], array_map('intval', $this->connection->fetchFirstColumn('SELECT sequence FROM outbox WHERE order_id = ? ORDER BY sequence', [$sale['order_id']])), 'the refund is queued behind its sale');

        // PrestaShop still down: nothing reaches it and the refund is not even tried.
        $this->assertSame(1, $this->sync(1)['retried']);
        $this->assertSame([], $this->calls);
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT attempts FROM outbox WHERE order_id = ? AND sequence = 2', [$sale['order_id']]));

        $this->shopDown = false;
        $this->assertSame(2, $this->sync(10)['uploaded']);

        $this->assertSame(['order ' . $sale['reference'], 'slip 7404'], $this->calls, 'the order first, then its credit slip');
        $this->assertSame(4, $this->shopStock, 'PrestaShop: 5 - 2 + 1');
        $this->assertSame($this->shopStock, $this->localStock(), 'both stocks agree');
        $this->assertSame((string) $this->connection->fetchOne('SELECT request_id FROM refund WHERE id = ?', [$refund['refund_id']]), $this->slips[0]['request_id']);
        $row = $this->connection->fetchAssociative('SELECT ps_order_id, ps_order_slip_id, status FROM refund WHERE id = ?', [$refund['refund_id']]);
        $this->assertEquals(['ps_order_id' => 7404, 'ps_order_slip_id' => 8404, 'status' => 'done'], $row);
        $this->assertEquals(
            [['type' => 'sale', 'ps_order_id' => 7404, 'ps_order_slip_id' => null], ['type' => 'refund', 'ps_order_id' => 7404, 'ps_order_slip_id' => 8404]],
            $this->connection->fetchAllAssociative('SELECT type, ps_order_id, ps_order_slip_id FROM movement WHERE session_id = ? ORDER BY id', [self::SESSION_ID])
        );
        $this->assertSame(1, (new OrderSearchService($this->connection))->getOrderById($sale['order_id'])['lines'][0]['refundable_qty']);
    }
}
