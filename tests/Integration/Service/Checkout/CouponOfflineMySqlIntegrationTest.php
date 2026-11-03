<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Checkout;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
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
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Service\Sync\Outbox;
use VenPosTPV\Service\Sync\OutboxSyncTrigger;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * PRD V5 / A5: "Con PrestaShop parado, un cupón se rechaza con aviso y la venta sin
 * cupón se cobra." With PrestaShop answering, a coupon sale stays PrestaShop-first,
 * and a repeated payment attempt (request_id) never creates a second order.
 */
final class CouponOfflineMySqlIntegrationTest extends TestCase
{
    private const TERMINAL_ID = 9411;
    private const LETTER = 'K';
    private const SESSION_ID = 9411;
    private const PRODUCT = 941101;
    private const PRICE = 12.10;
    private const PS_ORDER = 941177;

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();
        $c = $this->connection;
        $c->insert('terminal', ['id' => self::TERMINAL_ID, 'ps_shop_id' => 1, 'name' => 'Coupons', 'ticket_letter' => self::LETTER]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->insert('session', ['id' => self::SESSION_ID, 'terminal_id' => self::TERMINAL_ID, 'user_id' => 1, 'opened_at' => '2026-11-03 09:00:00', 'opening_cash' => 100]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $c->insert('catalog_product', ['id' => self::PRODUCT, 'name' => 'Gorra', 'price' => 10.0, 'price_ttc' => self::PRICE, 'price_net' => 10.0]);
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
        }
        $c->executeStatement('DELETE FROM movement WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM order_meta WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM session WHERE id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM ticket_sequence WHERE letter = ?', [self::LETTER]);
        $c->executeStatement('DELETE FROM terminal WHERE id = ?', [self::TERMINAL_ID]);
        $c->executeStatement('DELETE FROM catalog_stock WHERE id_product = ?', [self::PRODUCT]);
        $c->executeStatement('DELETE FROM catalog_product WHERE id = ?', [self::PRODUCT]);
    }

    private function service(PsOrderClient $client): CheckoutService
    {
        $c = $this->connection;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));
        $cash = new CashSessionService($c, $this->createMock(TerminalService::class), new RequestStack());

        return new CheckoutService($this->createMock(CartService::class), $client, $c, $cash, $bus, $this->createMock(PrinterService::class), true, null, new TicketNumberer($c), new Outbox($c), new OutboxSyncTrigger());
    }

    /** @return array<string, mixed> */
    private function quote(): array
    {
        return [
            'cart_id' => 17, 'customer_id' => 1, 'shop_id' => 1, 'currency_id' => 1, 'coupon_id' => 3, 'coupon_code' => 'SAVE2',
            'coupon_expires_at' => '2030-01-01 00:00:00', 'products' => [['id_product' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 1]],
            'before_discount_total' => self::PRICE, 'discount_amount' => 2.10, 'total_paid' => 10.0, 'expires_at' => time() + 600,
        ];
    }

    /** @return array<string, mixed> */
    private function cart(bool $coupon = true): array
    {
        $cart = ['lines' => [['product_id' => self::PRODUCT, 'id_product_attribute' => 0, 'qty' => 1, 'price' => self::PRICE, 'name' => 'Gorra']], 'total' => self::PRICE];

        return $coupon ? $cart + ['coupon' => ['code' => 'SAVE2', 'preview_cart_id' => 17, 'quote' => 'signed.quote']] : $cart;
    }

    private function onlineClient(int &$orders): PsOrderClient&MockObject
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->method('verifyCouponQuote')->willReturn($this->quote());
        $client->method('createCouponOrder')->willReturnCallback(function () use (&$orders): array {
            ++$orders;

            return ['order_id' => self::PS_ORDER, 'reference' => 'XKBKNABJK', 'total_paid' => 10.0];
        });

        return $client;
    }

    /** @return array{sales: int, movements: int, outbox: int, stock: int, numbers: int} */
    private function ledger(): array
    {
        $c = $this->connection;

        return [
            'sales' => (int) $c->fetchOne('SELECT COUNT(*) FROM order_meta WHERE session_id = ?', [self::SESSION_ID]),
            'movements' => (int) $c->fetchOne('SELECT COUNT(*) FROM movement WHERE session_id = ?', [self::SESSION_ID]),
            'outbox' => (int) $c->fetchOne('SELECT COUNT(*) FROM outbox o JOIN order_meta m ON m.id = o.order_id WHERE m.session_id = ?', [self::SESSION_ID]),
            'stock' => (int) $c->fetchOne('SELECT quantity FROM catalog_stock WHERE id_product = ?', [self::PRODUCT]),
            'numbers' => (int) $c->fetchOne('SELECT COALESCE(MAX(last_number), 0) FROM ticket_sequence WHERE letter = ?', [self::LETTER]),
        ];
    }

    public function testCouponWithPrestaShopAnsweringIsStillAPrestaShopFirstSale(): void
    {
        $orders = 0;
        $result = $this->service($this->onlineClient($orders))->createOrder(1, $this->cart() + ['cash_amount' => 10.0], 'cash', 10.0);

        $this->assertSame(1, $orders);
        $this->assertSame(self::PS_ORDER, $result['ps_order_id']);
        $this->assertSame('XKBKNABJK', $result['reference']);
        $this->assertTrue($result['synced']);
        $this->assertSame(10.0, $result['total']);
        $this->assertFalse($result['saga_error']);
        $this->assertSame(['sales' => 1, 'movements' => 1, 'outbox' => 0, 'stock' => 4, 'numbers' => 0], $this->ledger(), 'no outbox row and no ticket number: PrestaShop already has it');
    }

    public function testCouponOfflineIsRefusedWithNothingBookedAndTheSaleWithoutItIsChargedLocally(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->method('previewCoupon')->willThrowException(CheckoutException::couponOffline());
        $client->method('verifyCouponQuote')->willReturn($this->quote());
        $client->method('createCouponOrder')->willThrowException(CheckoutException::couponOffline());
        $client->expects($this->never())->method('createReferencedOrder');
        $service = $this->service($client);
        $before = $this->ledger();

        foreach ([
            fn () => $service->previewCoupon(1, $this->cart(false), 'SAVE2'),
            fn () => $service->createOrder(1, $this->cart() + ['cash_amount' => 10.0], 'cash', 10.0, 0.0, 'coupon-attempt-1'),
        ] as $call) {
            try {
                $call();
                $this->fail('A coupon must be refused while PrestaShop cannot be reached');
            } catch (CheckoutException $e) {
                $this->assertTrue($e->isCouponOffline());
                $this->assertSame('Sin conexión con PrestaShop: no se puede aplicar el cupón. Puedes cobrar sin él.', $e->getMessage());
            }
        }
        $this->assertSame($before, $this->ledger(), 'nothing booked');

        $sale = $service->createOrder(1, $this->cart(false) + ['cash_amount' => self::PRICE], 'cash', 20.0, 0.0, 'no-coupon-attempt-1');

        $this->assertSame(sprintf('%s%s%06d', self::LETTER, date('y'), 1), $sale['reference']);
        $this->assertNull($sale['ps_order_id']);
        $this->assertSame(self::PRICE, $sale['total']);
        $this->assertSame(7.9, $sale['change_due']);
        $this->assertSame(['sales' => 1, 'movements' => 1, 'outbox' => 1, 'stock' => 4, 'numbers' => 1], $this->ledger());
    }

    public function testARepeatedCouponPaymentAttemptReturnsTheSameSaleWithoutASecondOrder(): void
    {
        $orders = 0;
        $service = $this->service($this->onlineClient($orders));

        $first = $service->createOrder(1, $this->cart() + ['cash_amount' => 10.0], 'cash', 10.0, 0.0, 'coupon-attempt-7');
        $again = $service->createOrder(1, $this->cart() + ['cash_amount' => 10.0], 'cash', 10.0, 0.0, 'coupon-attempt-7');

        $this->assertSame(1, $orders, 'PrestaShop is asked once');
        $this->assertSame($first['order_id'], $again['order_id']);
        $this->assertSame(self::PS_ORDER, $again['ps_order_id']);
        $this->assertTrue($again['replayed']);
        $this->assertFalse($first['replayed']);
        $this->assertSame('coupon-attempt-7', $this->connection->fetchOne('SELECT request_id FROM order_meta WHERE id = ?', [$first['order_id']]));
        $this->assertSame(['sales' => 1, 'movements' => 1, 'outbox' => 0, 'stock' => 4, 'numbers' => 0], $this->ledger());
    }
}
