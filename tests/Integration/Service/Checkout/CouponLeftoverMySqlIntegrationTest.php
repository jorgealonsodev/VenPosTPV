<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Checkout;

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
use VenPosTPV\Service\Coupon\IssuedCouponStore;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Service\Sync\Outbox;
use VenPosTPV\Service\Sync\OutboxSyncTrigger;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * «Y si se le genera otro vale igual que el anterior con el importe que falta, y se
 * le deja usar solo 1 vez?»: a 50 € voucher on a 36 € sale leaves «<code>-2» worth
 * 14 €. The till keeps it as an issued coupon (its slip can be printed, sent by
 * email or WhatsApp) and in the sale's payment breakdown (reprints show it).
 */
final class CouponLeftoverMySqlIntegrationTest extends TestCase
{
    private const TERMINAL_ID = 9421;
    private const LETTER = 'L';
    private const SESSION_ID = 9421;
    private const PRODUCT = 942101;
    private const PRICE = 36.0;
    private const PS_ORDER = 942177;
    private const LEFTOVER_CODE = 'LFTV9421-2';

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();
        $c = $this->connection;
        $c->insert('terminal', ['id' => self::TERMINAL_ID, 'ps_shop_id' => 1, 'name' => 'Leftover', 'ticket_letter' => self::LETTER]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->insert('session', ['id' => self::SESSION_ID, 'terminal_id' => self::TERMINAL_ID, 'user_id' => 1, 'opened_at' => '2026-11-09 09:00:00', 'opening_cash' => 100]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $c->insert('catalog_product', ['id' => self::PRODUCT, 'name' => 'Chaqueta', 'price' => 29.75, 'price_ttc' => self::PRICE, 'price_net' => 29.75]);
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
        $c->executeStatement('DELETE FROM issued_coupon WHERE code = ?', [self::LEFTOVER_CODE]);
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

    private function service(?array $leftover, int &$orders): CheckoutService
    {
        $c = $this->connection;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));
        $cash = new CashSessionService($c, $this->createMock(TerminalService::class), new RequestStack());
        $client = $this->createMock(PsOrderClient::class);
        $client->method('verifyCouponQuote')->willReturn([
            'cart_id' => 17, 'customer_id' => 1, 'shop_id' => 1, 'currency_id' => 1, 'coupon_id' => 40, 'coupon_code' => 'LFTV9421',
            'coupon_expires_at' => '2027-06-30 23:59:59', 'products' => [['id_product' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 1]],
            'before_discount_total' => self::PRICE, 'discount_amount' => self::PRICE, 'total_paid' => 0.0, 'expires_at' => time() + 600,
        ]);
        $client->method('createCouponOrder')->willReturnCallback(function () use (&$orders, $leftover): array {
            ++$orders;

            return ['order_id' => self::PS_ORDER, 'reference' => 'XLFTVABJK', 'total_paid' => 0.0] + ($leftover !== null ? ['leftover_voucher' => $leftover] : []);
        });

        return new CheckoutService($this->createMock(CartService::class), $client, $c, $cash, $bus, $this->createMock(PrinterService::class), true, null, new TicketNumberer($c), new Outbox($c), new OutboxSyncTrigger(), issuedCoupons: new IssuedCouponStore($c));
    }

    /** @return array<string, mixed> */
    private function cart(): array
    {
        return [
            'lines' => [['product_id' => self::PRODUCT, 'id_product_attribute' => 0, 'qty' => 1, 'price' => self::PRICE, 'name' => 'Chaqueta']],
            'total' => self::PRICE,
            'coupon' => ['code' => 'LFTV9421', 'preview_cart_id' => 17, 'quote' => 'signed.quote'],
            'cash_amount' => 0.0,
        ];
    }

    private const LEFTOVER = ['id' => 41, 'code' => self::LEFTOVER_CODE, 'amount' => 14.0, 'expires_at' => '2027-06-30 23:59:59'];

    public function testTheLeftoverVoucherIsStoredAsAnIssuedCouponAndInTheSale(): void
    {
        $orders = 0;
        $result = $this->service(self::LEFTOVER, $orders)->createOrder(1, $this->cart(), 'cash', 0.0, 0.0, 'leftover-attempt-1');

        $issued = $this->connection->fetchAssociative('SELECT * FROM issued_coupon WHERE code = ?', [self::LEFTOVER_CODE]);
        $this->assertNotFalse($issued);
        $this->assertSame(
            ['ps_cart_rule_id' => 41, 'discount_type' => 'amount', 'value' => '14.00', 'expires_at' => '2027-06-30', 'quantity' => 1, 'quantity_per_user' => 1, 'terminal_id' => self::TERMINAL_ID, 'user_id' => 1],
            ['ps_cart_rule_id' => (int) $issued['ps_cart_rule_id'], 'discount_type' => $issued['discount_type'], 'value' => $issued['value'], 'expires_at' => substr((string) $issued['expires_at'], 0, 10), 'quantity' => (int) $issued['quantity'], 'quantity_per_user' => (int) $issued['quantity_per_user'], 'terminal_id' => (int) $issued['terminal_id'], 'user_id' => (int) $issued['user_id']],
        );
        $expected = ['id' => (int) $issued['id'], 'code' => self::LEFTOVER_CODE, 'amount' => 14.0, 'expires_at' => '2027-06-30 23:59:59'];
        $this->assertSame($expected, $result['leftover_voucher']);

        $breakdown = json_decode((string) $this->connection->fetchOne('SELECT payment_breakdown FROM order_meta WHERE id = ?', [$result['order_id']]), true);
        // The JSON column reorders keys and stores 14.0 as 14.
        $this->assertEquals(['issued_coupon_id' => (int) $issued['id'], 'code' => self::LEFTOVER_CODE, 'amount' => 14.0, 'expires_at' => '2027-06-30 23:59:59'], $breakdown['leftover_voucher']);
    }

    public function testARepeatedAttemptReturnsTheSameLeftoverWithoutASecondCoupon(): void
    {
        $orders = 0;
        $service = $this->service(self::LEFTOVER, $orders);
        $first = $service->createOrder(1, $this->cart(), 'cash', 0.0, 0.0, 'leftover-attempt-2');
        $again = $service->createOrder(1, $this->cart(), 'cash', 0.0, 0.0, 'leftover-attempt-2');

        $this->assertSame(1, $orders);
        $this->assertTrue($again['replayed']);
        $this->assertSame($first['leftover_voucher'], $again['leftover_voucher']);
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM issued_coupon WHERE code = ?', [self::LEFTOVER_CODE]));
    }

    public function testACouponSalePaidByTransferIsFiledAsTransferNotCard(): void
    {
        $orders = 0;
        $result = $this->service(null, $orders)->createOrder(1, $this->cart(), 'transfer', 0.0, 0.0, 'leftover-attempt-4');

        $this->assertSame('transfer', $result['payment_method']);
        $breakdown = json_decode((string) $this->connection->fetchOne('SELECT payment_breakdown FROM order_meta WHERE id = ?', [$result['order_id']]), true);
        $this->assertSame('transfer', $breakdown['payment_method']);
        $this->assertEquals(['cash' => 0, 'card' => 0, 'transfer' => 0], $breakdown['tender']);
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM movement WHERE session_id = ?', [self::SESSION_ID]));
    }

    public function testASaleWithoutLeftoverHasNone(): void
    {
        $orders = 0;
        $result = $this->service(null, $orders)->createOrder(1, $this->cart(), 'cash', 0.0, 0.0, 'leftover-attempt-3');

        $this->assertArrayNotHasKey('leftover_voucher', $result);
        $breakdown = json_decode((string) $this->connection->fetchOne('SELECT payment_breakdown FROM order_meta WHERE id = ?', [$result['order_id']]), true);
        $this->assertArrayNotHasKey('leftover_voucher', $breakdown);
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM issued_coupon WHERE code = ?', [self::LEFTOVER_CODE]));
    }
}
