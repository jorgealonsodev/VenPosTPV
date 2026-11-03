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
use VenPosTPV\Service\Checkout\Exception\CheckoutException;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Exchange\ExchangeService;
use VenPosTPV\Service\PrestaShop\CouponCreationService;
use VenPosTPV\Service\PrestaShop\Exception\CouponCreationException;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketVerificationService;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\OrderSearchService;
use VenPosTPV\Service\Refund\PsRefundClient;
use VenPosTPV\Service\Refund\RefundService;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * A9: "Un cambio de 40 € por un artículo de 55 € cobra 15 €. Al revés, devuelve
 * 15 € por el medio original." The returned lines are a normal refund (credit note,
 * PrestaShop slip, restock); their money pays the new sale instead of leaving.
 */
final class ExchangeServiceMySqlIntegrationTest extends TestCase
{
    private const SESSION_ID = 9301;
    private const ORDER_ID = 930001;

    /** product id => tax-included price */
    private const PRICES = [930005 => 40.0, 930006 => 55.0, 930007 => 40.0, 930008 => 60.0];

    private ?Connection $connection = null;

    /** @var array<int, array<string, mixed>> */
    private array $refundPayloads = [];

    /** @var array<int, \Throwable|null> */
    private array $refundFailures = [];

    /** @var array<int, array<string, mixed>> */
    private array $orderPayloads = [];

    /** @var array<int, \Throwable|null> */
    private array $orderFailures = [];

    /** @var array<int, array{input: array<string, mixed>, shop: int}> */
    private array $coupons = [];

    /** @var array<int, \Throwable|null> */
    private array $couponFailures = [];

    private int $drawerOpens = 0;

    private int $cartClears = 0;

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
        $c->executeStatement('DELETE FROM exchange WHERE ps_order_id >= 930000 AND ps_order_id < 940000');
        $c->executeStatement('DELETE FROM refund_line WHERE refund_id IN (SELECT id FROM refund WHERE ps_order_id >= 930000 AND ps_order_id < 940000)');
        $c->executeStatement('DELETE FROM refund WHERE ps_order_id >= 930000 AND ps_order_id < 940000');
        $c->executeStatement('DELETE FROM credit_note_sequence');
        $c->executeStatement('DELETE FROM movement WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM order_meta WHERE ps_order_id >= 930000 AND ps_order_id < 1000000');
        $c->executeStatement("DELETE FROM audit_log WHERE action IN ('checkout_orphan', 'refund_orphan')");
        $c->executeStatement('DELETE FROM session WHERE id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM catalog_stock WHERE id_product BETWEEN 930000 AND 939999');
        $c->executeStatement('DELETE FROM catalog_product WHERE id BETWEEN 930000 AND 939999');
    }

    /**
     * One-line TPV sale of $productId at its full price.
     *
     * @param array{cash: float, card: float} $tender
     */
    private function seedSale(int $productId, array $tender, ?string $createdAt = null, int $orderId = self::ORDER_ID): void
    {
        $price = self::PRICES[$productId];
        $this->connection->insert('order_meta', [
            'ps_order_id' => $orderId, 'terminal_id' => 1, 'session_id' => self::SESSION_ID, 'user_id' => 1,
            'payment_breakdown' => json_encode([
                'reference' => 'ORIG' . $orderId, 'total' => $price,
                'payment_method' => $tender['card'] > 0 && $tender['cash'] > 0 ? 'mixed' : ($tender['card'] > 0 ? 'card' : 'cash'),
                'amount_tendered' => $price, 'change_due' => 0, 'tax' => round($price - $price / 1.21, 2),
                'lines' => [['name' => 'Producto ' . $productId, 'product_id' => $productId, 'id_product_attribute' => 0, 'qty' => 1, 'price' => $price, 'line_total' => $price, 'paid_total' => $price]],
                'tender' => $tender,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $createdAt ?? (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string, mixed> cart_data as the sale screen sends it to /api/checkout */
    private function cart(int ...$productIds): array
    {
        $lines = array_map(fn (int $id): array => ['product_id' => $id, 'id_product_attribute' => 0, 'qty' => 1, 'price' => self::PRICES[$id], 'name' => 'Producto ' . $id], $productIds);

        return ['lines' => $lines, 'total' => round(array_sum(array_column($lines, 'price')), 2)];
    }

    /** @return array<int, array{line_index: int, quantity: int, restock: bool}> */
    private function returnFirstLine(): array
    {
        return [['line_index' => 0, 'quantity' => 1, 'restock' => true]];
    }

    private function service(): ExchangeService
    {
        $c = $this->connection;
        $cash = new CashSessionService($c, $this->createMock(TerminalService::class), new RequestStack());

        $refundClient = $this->createMock(PsRefundClient::class);
        $refundClient->method('createRefund')->willReturnCallback(function (array $payload): array {
            $this->refundPayloads[] = $payload;
            $failure = array_shift($this->refundFailures);
            if ($failure !== null) {
                throw $failure;
            }

            return [
                'order_slip_id' => 800 + \count($this->refundPayloads),
                'refunded_amount' => round(array_sum(array_column($payload['lines'], 'amount')), 2),
                'refunded_lines' => array_map(static fn (array $l): array => $l + ['restocked' => $l['restock']], $payload['lines']),
                'restock_failed' => [],
                'replayed' => false,
            ];
        });

        $orderClient = $this->createMock(PsOrderClient::class);
        $orderClient->method('createOrder')->willReturnCallback(function (array $payload): array {
            $this->orderPayloads[] = $payload;
            $failure = array_shift($this->orderFailures);
            if ($failure !== null) {
                throw $failure;
            }
            $n = \count($this->orderPayloads);

            return ['order_id' => 990000 + $n, 'reference' => 'NEWREF' . $n, 'total_paid' => 0.0];
        });

        $printer = $this->createMock(PrinterService::class);
        $printer->method('openDrawer')->willReturnCallback(function (): bool {
            ++$this->drawerOpens;

            return true;
        });
        $cart = $this->createMock(CartService::class);
        $cart->method('clear')->willReturnCallback(function (): void {
            ++$this->cartClears;
        });
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $coupons = $this->createMock(CouponCreationService::class);
        $coupons->method('create')->willReturnCallback(function (array $input, int $shop): array {
            $this->coupons[] = ['input' => $input, 'shop' => $shop];
            $failure = array_shift($this->couponFailures);
            if ($failure !== null) {
                throw $failure;
            }

            return ['id' => 670 + \count($this->coupons), 'code' => 'CAMB' . \count($this->coupons) . 'XYZ', 'type' => 'amount', 'value' => $input['value'],
                'expires_at' => $input['expires_at'], 'quantity' => 1, 'quantity_per_user' => 1];
        });

        $refunds = new RefundService(new OrderSearchService($c), $refundClient, $c, $cash, null, null, $coupons);
        $checkout = new CheckoutService($cart, $orderClient, $c, $cash, $bus, $printer);

        return new ExchangeService($refunds, $checkout, $c, $coupons, $cash);
    }

    /** @return array<int, array{type: string, amount: string}> */
    private function movements(): array
    {
        return $this->connection->fetchAllAssociative('SELECT type, amount FROM movement WHERE session_id = ? ORDER BY id', [self::SESSION_ID]);
    }

    private function stock(int $productId): int
    {
        return (int) $this->connection->fetchOne('SELECT quantity FROM catalog_stock WHERE id_product = ? AND id_product_attribute = 0', [$productId]);
    }

    /** @return array<string, mixed> */
    private function tender(int $psOrderId): array
    {
        $breakdown = json_decode((string) $this->connection->fetchOne('SELECT payment_breakdown FROM order_meta WHERE ps_order_id = ?', [$psOrderId]), true);

        return $breakdown['tender'];
    }

    private function rows(string $table): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE ps_order_id >= 930000 AND ps_order_id < 940000', $table));
    }

    private function expectRefundError(int $code, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected RefundException ' . $code);
        } catch (RefundException $e) {
            $this->assertSame($code, $e->getCode(), $e->getMessage());
        }
    }

    private function assertNothingHappened(): void
    {
        $this->assertSame([], $this->orderPayloads, 'no new sale');
        $this->assertSame([], $this->movements(), 'no money');
        $this->assertSame(5, $this->stock(930005));
        $this->assertSame(5, $this->stock(930006));
        $this->assertSame('0.00', (string) $this->connection->fetchOne('SELECT refunded_amount FROM order_meta WHERE ps_order_id = ?', [self::ORDER_ID]));
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM refund WHERE credit_note_number IS NOT NULL AND ps_order_id = ?', [self::ORDER_ID]));
    }

    public function testFortyForFiftyFiveChargesFifteenInCash(): void
    {
        $this->seedSale(930005, ['cash' => 40.0, 'card' => 0.0]);

        $result = $this->service()->processExchange(1, self::ORDER_ID, $this->returnFirstLine(), 'size', '', $this->cart(930006), 'cash', 20.0, false);

        $this->assertSame('done', $result['status']);
        $this->assertSame(40.0, $result['credit']);
        $this->assertSame(55.0, $result['new_total']);
        $this->assertSame(15.0, $result['balance']);
        // Returned part: a normal refund through PrestaShop with its credit note, no money out.
        $this->assertSame([['id_product' => 930005, 'id_product_attribute' => 0, 'quantity' => 1, 'amount' => 40.0, 'restock' => true]], $this->refundPayloads[0]['lines']);
        $this->assertMatchesRegularExpression('/^R\d{4}-000001$/', (string) $result['refund']['credit_note_number']);
        $this->assertSame(0.0, $result['refund']['cash_amount']);
        $this->assertSame(0.0, $result['refund']['card_amount']);
        $refund = $this->connection->fetchAssociative('SELECT status, total, exchange_credit FROM refund WHERE id = ?', [$result['refund']['refund_id']]);
        $this->assertSame(['status' => 'done', 'total' => '40.00', 'exchange_credit' => '40.00'], $refund);
        // New items: a normal sale, of which only the balance was collected.
        $this->assertCount(1, $this->orderPayloads);
        $this->assertSame(930006, $this->orderPayloads[0]['products'][0]['id_product']);
        $this->assertSame(990001, $result['sale']['ps_order_id']);
        $this->assertSame('NEWREF1', $result['sale']['reference']);
        $this->assertSame(55.0, $result['sale']['total']);
        $this->assertSame(15.0, $result['sale']['payable']);
        $this->assertSame(5.0, $result['sale']['change_due']);
        $this->assertEquals(['cash' => 15.0, 'card' => 0.0, 'exchange_credit' => 40.0], $this->tender(990001));
        $this->assertEquals([['type' => 'sale', 'amount' => '15.00']], $this->movements(), 'only the balance enters the drawer');
        $this->assertSame(6, $this->stock(930005), 'returned item restocked');
        $this->assertSame(4, $this->stock(930006), 'new item leaves the stock');
        $this->assertSame('40.00', (string) $this->connection->fetchOne('SELECT refunded_amount FROM order_meta WHERE ps_order_id = ?', [self::ORDER_ID]));
        $exchange = $this->connection->fetchAssociative('SELECT status, refund_id, new_ps_order_id, new_reference FROM exchange WHERE id = ?', [$result['exchange_id']]);
        $this->assertEquals(['status' => 'done', 'refund_id' => $result['refund']['refund_id'], 'new_ps_order_id' => '990001', 'new_reference' => 'NEWREF1'], $exchange);
        $this->assertSame(1, $this->drawerOpens, 'cash came in');
        $this->assertSame(0, $this->cartClears, 'the sale screen cart is not touched');
    }

    public function testFiftyFiveForFortyRefundsFifteenToTheOriginalCard(): void
    {
        $this->seedSale(930006, ['cash' => 0.0, 'card' => 55.0]);
        $service = $this->service();

        // The card part is refunded on the payment terminal first, as any card refund.
        $this->expectRefundError(422, fn () => $service->processExchange(1, self::ORDER_ID, $this->returnFirstLine(), 'size', '', $this->cart(930005), 'cash', 0.0, false));
        $this->assertSame([], $this->refundPayloads);
        $this->assertSame(0, $this->rows('refund'));
        $this->assertSame(0, $this->rows('exchange'));

        $result = $service->processExchange(1, self::ORDER_ID, $this->returnFirstLine(), 'size', '', $this->cart(930005), 'cash', 0.0, true);

        $this->assertSame('done', $result['status']);
        $this->assertSame(-15.0, $result['balance']);
        $this->assertSame(0.0, $result['refund']['cash_amount']);
        $this->assertSame(15.0, $result['refund']['card_amount']);
        $this->assertEquals(['exchange_credit' => '40.00', 'card_confirmed' => 1], $this->connection->fetchAssociative('SELECT exchange_credit, card_confirmed FROM refund'));
        $this->assertSame('exchange', $result['sale']['payment_method']);
        $this->assertSame(0.0, $result['sale']['payable']);
        $this->assertEquals(['cash' => 0.0, 'card' => 0.0, 'exchange_credit' => 40.0], $this->tender(990001));
        $this->assertSame([], $this->movements(), 'no cash moves for a card refund');
        $this->assertSame(0, $this->drawerOpens);
    }

    public function testFiftyFiveForFortyRefundsFifteenInCashWhenPaidInCash(): void
    {
        $this->seedSale(930006, ['cash' => 55.0, 'card' => 0.0]);

        $result = $this->service()->processExchange(1, self::ORDER_ID, $this->returnFirstLine(), 'size', '', $this->cart(930005), 'card', 0.0, false);

        $this->assertSame(15.0, $result['refund']['cash_amount']);
        $this->assertEquals([['type' => 'refund', 'amount' => '15.00']], $this->movements(), 'only the difference leaves the drawer');
        // The refund part's drawer opening belongs to the controller, as for any refund.
        $this->assertSame(0, $this->drawerOpens);
    }

    public function testMixedSaleRefundsTheDifferenceInTheOriginalProportion(): void
    {
        $this->seedSale(930008, ['cash' => 20.0, 'card' => 40.0]);

        $result = $this->service()->processExchange(1, self::ORDER_ID, $this->returnFirstLine(), 'size', '', $this->cart(930005), 'cash', 0.0, true);

        // 60 - 40 = 20 back: 20 x 20/60 in cash, the rest to the card.
        $this->assertSame(-20.0, $result['balance']);
        $this->assertSame(6.67, $result['refund']['cash_amount']);
        $this->assertSame(13.33, $result['refund']['card_amount']);
        $this->assertEquals([['type' => 'refund', 'amount' => '6.67']], $this->movements());
    }

    public function testEvenExchangeMovesNoMoney(): void
    {
        $this->seedSale(930005, ['cash' => 40.0, 'card' => 0.0]);

        $result = $this->service()->processExchange(1, self::ORDER_ID, $this->returnFirstLine(), 'size', '', $this->cart(930007), 'cash', 50.0, false);

        $this->assertSame(0.0, $result['balance']);
        $this->assertSame(0.0, $result['refund']['cash_amount']);
        $this->assertSame('exchange', $result['sale']['payment_method']);
        $this->assertSame(0.0, $result['sale']['change_due']);
        $this->assertSame([], $this->movements());
        $this->assertSame(0, $this->drawerOpens);
        $this->assertEquals(['cash' => 0.0, 'card' => 0.0, 'exchange_credit' => 40.0], $this->tender(990001));
    }

    public function testPositiveBalanceByCardOrMixedCollectsOnlyTheCashPartInTheDrawer(): void
    {
        $this->seedSale(930005, ['cash' => 40.0, 'card' => 0.0]);
        $this->seedSale(930005, ['cash' => 40.0, 'card' => 0.0], null, self::ORDER_ID + 1);
        $service = $this->service();

        $card = $service->processExchange(1, self::ORDER_ID, $this->returnFirstLine(), 'size', '', $this->cart(930006), 'card', 0.0, false);
        $this->assertEquals(['cash' => 0.0, 'card' => 15.0, 'exchange_credit' => 40.0], $this->tender($card['sale']['ps_order_id']));
        $this->assertSame([], $this->movements());

        $mixed = $service->processExchange(1, self::ORDER_ID + 1, $this->returnFirstLine(), 'size', '', $this->cart(930006) + ['cash_amount' => 5.0], 'mixed', 5.0, false);
        $this->assertEquals(['cash' => 5.0, 'card' => 10.0, 'exchange_credit' => 40.0], $this->tender($mixed['sale']['ps_order_id']));
        $this->assertEquals([['type' => 'sale', 'amount' => '5.00']], $this->movements());
    }

    public function testBalanceMustBeCoveredBeforeAnythingHappens(): void
    {
        $this->seedSale(930005, ['cash' => 40.0, 'card' => 0.0]);
        $service = $this->service();
        $line = $this->returnFirstLine();

        $this->expectRefundError(422, fn () => $service->processExchange(1, self::ORDER_ID, $line, 'size', '', $this->cart(930006), 'cash', 10.0, false));
        $this->expectRefundError(422, fn () => $service->processExchange(1, self::ORDER_ID, $line, 'size', '', $this->cart(930006) + ['cash_amount' => 15.0], 'mixed', 15.0, false));
        $this->expectRefundError(422, fn () => $service->processExchange(1, self::ORDER_ID, $line, 'size', '', $this->cart(930006), 'bizum', 0.0, false));
        // Browser prices are never trusted for the new items either.
        $tampered = $this->cart(930006);
        $tampered['lines'][0]['price'] = 40.0;
        $tampered['total'] = 40.0;
        $this->expectRefundError(422, fn () => $service->processExchange(1, self::ORDER_ID, $line, 'size', '', $tampered, 'cash', 0.0, false));
        $this->expectRefundError(422, fn () => $service->processExchange(1, self::ORDER_ID, $line, 'size', '', ['lines' => [], 'total' => 0], 'cash', 0.0, false));

        $this->assertSame([], $this->refundPayloads);
        $this->assertSame(0, $this->rows('refund'));
        $this->assertSame(0, $this->rows('exchange'));
        $this->assertNothingHappened();
    }

    public function testRefundRulesApplyToTheReturnedLines(): void
    {
        $service = $this->service();
        $this->seedSale(930005, ['cash' => 40.0, 'card' => 0.0], (new \DateTimeImmutable('-31 days'))->format('Y-m-d H:i:s'));
        $this->expectRefundError(422, fn () => $service->processExchange(1, self::ORDER_ID, $this->returnFirstLine(), 'size', '', $this->cart(930006), 'cash', 20.0, false));

        $this->seedSale(930005, ['cash' => 40.0, 'card' => 0.0], null, self::ORDER_ID + 1);
        $this->expectRefundError(422, fn () => $service->processExchange(1, self::ORDER_ID + 1, [['line_index' => 0, 'quantity' => 2, 'restock' => true]], 'size', '', $this->cart(930006), 'cash', 20.0, false));
        $this->expectRefundError(422, fn () => $service->processExchange(1, self::ORDER_ID + 1, $this->returnFirstLine(), 'whatever', '', $this->cart(930006), 'cash', 20.0, false));
        $this->expectRefundError(422, fn () => $service->processExchange(1, self::ORDER_ID + 1, $this->returnFirstLine(), 'other', ' ', $this->cart(930006), 'cash', 20.0, false));
        $this->expectRefundError(422, fn () => $service->processExchange(1, self::ORDER_ID + 1, [], 'size', '', $this->cart(930006), 'cash', 20.0, false));
        $this->expectRefundError(404, fn () => $service->processExchange(1, 939999, $this->returnFirstLine(), 'size', '', $this->cart(930006), 'cash', 20.0, false));
        $this->assertSame([], $this->refundPayloads);
        $this->assertSame([], $this->orderPayloads);

        // Defective goods are not restocked when the cashier unticks it.
        $result = $service->processExchange(1, self::ORDER_ID + 1, [['line_index' => 0, 'quantity' => 1, 'restock' => false]], 'defective', '', $this->cart(930006), 'cash', 20.0, false);
        $this->assertSame('done', $result['status']);
        $this->assertFalse($this->refundPayloads[0]['lines'][0]['restock']);
        $this->assertSame(5, $this->stock(930005));

        // A returned line cannot be exchanged twice.
        $this->expectRefundError(422, fn () => $service->processExchange(1, self::ORDER_ID + 1, $this->returnFirstLine(), 'size', '', $this->cart(930006), 'cash', 20.0, false));
    }

    public function testPrestaShopRejectingTheReturnLeavesNoEffects(): void
    {
        $this->seedSale(930005, ['cash' => 40.0, 'card' => 0.0]);
        $this->refundFailures = [RefundException::validationFailed('PS validation failed')];

        $this->expectRefundError(422, fn () => $this->service()->processExchange(1, self::ORDER_ID, $this->returnFirstLine(), 'size', '', $this->cart(930006), 'cash', 20.0, false));

        $this->assertNothingHappened();
        $this->assertSame('failed', $this->connection->fetchOne('SELECT status FROM refund'));
        $this->assertSame('failed', $this->connection->fetchOne('SELECT status FROM exchange'));
    }

    public function testPrestaShopDownBlocksTheExchangeUntilItIsRetried(): void
    {
        $this->seedSale(930005, ['cash' => 40.0, 'card' => 0.0]);
        $service = $this->service();
        $this->refundFailures = [RefundException::psUnavailable('Connection refused')];

        $this->expectRefundError(0, fn () => $service->processExchange(1, self::ORDER_ID, $this->returnFirstLine(), 'size', '', $this->cart(930006), 'cash', 20.0, false));

        $this->assertNothingHappened();
        $exchangeId = (int) $this->connection->fetchOne('SELECT id FROM exchange');
        $this->assertSame('pending', $this->connection->fetchOne('SELECT status FROM exchange'));
        $open = (new OrderSearchService($this->connection))->getOrder(self::ORDER_ID)['open_exchange'];
        $this->assertSame($exchangeId, $open['id']);
        $this->assertSame('pending', $open['status']);
        // The sale stays blocked like any refund with an unknown outcome.
        $this->expectRefundError(409, fn () => $service->processExchange(1, self::ORDER_ID, $this->returnFirstLine(), 'size', '', $this->cart(930006), 'cash', 20.0, false));

        $result = $service->retry(1, $exchangeId);

        $this->assertSame('done', $result['status']);
        $this->assertSame($this->refundPayloads[0]['request_id'], $this->refundPayloads[\count($this->refundPayloads) - 1]['request_id'], 'same request id: PrestaShop replays instead of refunding twice');
        $this->assertCount(1, $this->orderPayloads);
        $this->assertEquals([['type' => 'sale', 'amount' => '15.00']], $this->movements());
        $this->assertNull((new OrderSearchService($this->connection))->getOrder(self::ORDER_ID)['open_exchange']);
    }

    public function testFailedNewSaleKeepsTheCreditOpenAndRetryNeverRefundsOrPaysTwice(): void
    {
        $this->seedSale(930006, ['cash' => 55.0, 'card' => 0.0]);
        $service = $this->service();
        $this->orderFailures = [CheckoutException::psUnavailable('timeout')];

        $result = $service->processExchange(1, self::ORDER_ID, $this->returnFirstLine(), 'size', '', $this->cart(930005), 'cash', 0.0, false);

        $this->assertSame('credit_open', $result['status']);
        $this->assertNull($result['sale']);
        $this->assertTrue($result['sale_outcome_unknown']);
        $this->assertStringContainsString('timeout', (string) $result['sale_error']);
        // The returned part is complete: credit note issued, 15 paid out once.
        $this->assertNotNull($result['refund']['credit_note_number']);
        $this->assertEquals([['type' => 'refund', 'amount' => '15.00']], $this->movements());
        $row = $this->connection->fetchAssociative('SELECT status, credit_applied, error_message FROM exchange WHERE id = ?', [$result['exchange_id']]);
        $this->assertSame('credit_open', $row['status']);
        $this->assertSame('40.00', $row['credit_applied']);
        $this->assertStringContainsString('timeout', (string) $row['error_message']);
        $open = (new OrderSearchService($this->connection))->getOrder(self::ORDER_ID)['open_exchange'];
        $this->assertSame(['id' => $result['exchange_id'], 'status' => 'credit_open', 'credit_applied' => 40.0, 'new_total' => 40.0, 'balance' => -15.0, 'error' => $row['error_message']], $open);

        $retried = $service->retry(1, $result['exchange_id']);

        $this->assertSame('done', $retried['status']);
        $this->assertSame(990002, $retried['sale']['ps_order_id']);
        $this->assertCount(1, $this->refundPayloads, 'the returned part is never sent again');
        $this->assertEquals([['type' => 'refund', 'amount' => '15.00']], $this->movements(), 'the difference is never paid twice');
        $this->assertEquals(['cash' => 0.0, 'card' => 0.0, 'exchange_credit' => 40.0], $this->tender(990002));
        $this->expectRefundError(409, fn () => $service->retry(1, $result['exchange_id']));
        $this->assertCount(2, $this->orderPayloads);
    }

    /** An exchange whose new sale definitely failed: credit note done, 15 paid out, 40 of credit open. */
    private function openCredit(ExchangeService $service): int
    {
        $this->seedSale(930006, ['cash' => 55.0, 'card' => 0.0]);
        $this->orderFailures = [CheckoutException::psValidationFailed('Product not found')];
        $result = $service->processExchange(1, self::ORDER_ID, $this->returnFirstLine(), 'size', '', $this->cart(930005), 'cash', 0.0, false);
        $this->assertSame('credit_open', $result['status']);

        return (int) $result['exchange_id'];
    }

    public function testOpenCreditIsClosedWithOneVoucherAndTheExchangeCannotRunAgain(): void
    {
        $service = $this->service();
        $exchangeId = $this->openCredit($service);

        $closed = $service->closeWithVoucher(1, $exchangeId, 3);

        $this->assertSame($exchangeId, $closed['exchange_id']);
        $this->assertSame('closed_voucher', $closed['status']);
        $this->assertSame('CAMB1XYZ', $closed['voucher_code']);
        $this->assertSame(40.0, $closed['voucher_amount']);
        $this->assertCount(1, $this->coupons);
        $this->assertSame(3, $this->coupons[0]['shop']);
        $this->assertSame(40.0, $this->coupons[0]['input']['value']);
        $this->assertTrue($this->coupons[0]['input']['partial_use']);
        $this->assertSame(1, $this->coupons[0]['input']['quantity']);
        $this->assertSame((new \DateTimeImmutable('+1 year'))->format('Y-m-d'), $this->coupons[0]['input']['expires_at']);

        $row = $this->connection->fetchAssociative('SELECT * FROM exchange WHERE id = ?', [$exchangeId]);
        $this->assertSame('closed_voucher', $row['status']);
        $this->assertSame('CAMB1XYZ', $row['voucher_code']);
        $this->assertSame('40.00', (string) $row['voucher_amount']);
        $this->assertSame(671, (int) $row['voucher_cart_rule_id']);
        $this->assertSame(self::SESSION_ID, (int) $row['voucher_session_id']);
        $this->assertNotNull($row['completed_at']);
        $this->assertNull((new OrderSearchService($this->connection))->getOrder(self::ORDER_ID)['open_exchange']);

        $this->expectRefundError(409, fn () => $service->retry(1, $exchangeId));
        $this->expectRefundError(409, fn () => $service->closeWithVoucher(1, $exchangeId, 3));
        $this->assertCount(1, $this->coupons, 'never a second voucher');
        $this->assertCount(1, $this->orderPayloads, 'no new sale after closing');
        $this->assertCount(1, $this->refundPayloads);
        $this->assertEquals([['type' => 'refund', 'amount' => '15.00']], $this->movements(), 'the voucher moves no cash');
    }

    public function testCashReportCountsTheClosingVoucherOnceAndNoLongerAsExchangeCredit(): void
    {
        $service = $this->service();
        $exchangeId = $this->openCredit($service);
        $cash = new CashSessionService($this->connection, $this->createMock(TerminalService::class), new RequestStack());
        $before = $cash->getXReport(1)['totals'];
        $this->assertSame(40.0, $before['exchange_credit']);
        $this->assertSame(0.0, $before['refunds_voucher']);

        $service->closeWithVoucher(1, $exchangeId, 3);
        $totals = $cash->getXReport(1)['totals'];

        $this->assertSame(15.0, $totals['refunds_cash']);
        $this->assertSame(40.0, $totals['refunds_voucher']);
        $this->assertSame(55.0, $totals['refunds_total']);
        $this->assertSame(0.0, $totals['exchange_credit'], 'the credit was not spent on an exchange');
        $this->assertSame(0, $totals['counts']['exchanges']);
        $this->assertSame(85.0, $totals['expected_cash'], 'drawer: 100 - 15');
    }

    public function testVoucherWithUnknownOutcomeKeepsTheCreditOpenAndBlocksRetryAndASecondVoucher(): void
    {
        $service = $this->service();
        $exchangeId = $this->openCredit($service);
        $this->couponFailures = [CouponCreationException::reconcile('ABCD2345')];

        $this->expectRefundError(409, fn () => $service->closeWithVoucher(1, $exchangeId, 3));

        $row = $this->connection->fetchAssociative('SELECT status, voucher_requested_at, voucher_code, error_message FROM exchange WHERE id = ?', [$exchangeId]);
        $this->assertSame('credit_open', $row['status']);
        $this->assertNotNull($row['voucher_requested_at']);
        $this->assertNull($row['voucher_code']);
        $this->assertStringContainsString('ABCD2345', (string) $row['error_message']);

        $this->expectRefundError(409, fn () => $service->closeWithVoucher(1, $exchangeId, 3));
        $this->expectRefundError(409, fn () => $service->retry(1, $exchangeId));
        $this->assertCount(1, $this->coupons, 'never a second voucher automatically');
        $this->assertCount(1, $this->orderPayloads, 'no new sale while a voucher may exist');
        $this->assertSame(0.0, (new CashSessionService($this->connection, $this->createMock(TerminalService::class), new RequestStack()))->getXReport(1)['totals']['refunds_voucher']);
    }

    public function testOnlyAnOpenCreditCanBeClosedWithAVoucherAndOnlyForAKnownShop(): void
    {
        $this->seedSale(930005, ['cash' => 40.0, 'card' => 0.0]);
        $service = $this->service();
        $this->refundFailures = [RefundException::psUnavailable('Connection refused')];
        $this->expectRefundError(0, fn () => $service->processExchange(1, self::ORDER_ID, $this->returnFirstLine(), 'size', '', $this->cart(930006), 'cash', 20.0, false));
        $pendingId = (int) $this->connection->fetchOne('SELECT id FROM exchange');

        $this->expectRefundError(409, fn () => $service->closeWithVoucher(1, $pendingId, 3));
        $this->expectRefundError(409, fn () => $service->closeWithVoucher(1, 999999, 3));
        $this->expectRefundError(422, fn () => $service->closeWithVoucher(1, $pendingId, 0));
        $this->assertSame([], $this->coupons);
        $this->assertSame('pending', $this->connection->fetchOne('SELECT status FROM exchange WHERE id = ?', [$pendingId]));
    }

    public function testRejectedNewSaleIsReportedAsDefinitelyNotCreated(): void
    {
        $this->seedSale(930005, ['cash' => 40.0, 'card' => 0.0]);
        $this->orderFailures = [CheckoutException::psValidationFailed('Product not found')];

        $result = $this->service()->processExchange(1, self::ORDER_ID, $this->returnFirstLine(), 'size', '', $this->cart(930006), 'cash', 20.0, false);

        $this->assertSame('credit_open', $result['status']);
        $this->assertFalse($result['sale_outcome_unknown']);
        $this->assertSame([], $this->movements(), 'nothing was collected for a sale that does not exist');
    }

    public function testCashReportAfterExchangesCountsOnlyRealMoneyAndShowsTheCreditApart(): void
    {
        $this->seedSale(930005, ['cash' => 40.0, 'card' => 0.0]);
        $this->seedSale(930006, ['cash' => 55.0, 'card' => 0.0], null, self::ORDER_ID + 1);
        $service = $this->service();
        $service->processExchange(1, self::ORDER_ID, $this->returnFirstLine(), 'size', '', $this->cart(930006), 'cash', 15.0, false);
        $service->processExchange(1, self::ORDER_ID + 1, $this->returnFirstLine(), 'size', '', $this->cart(930005), 'cash', 0.0, false);

        $totals = (new CashSessionService($this->connection, $this->createMock(TerminalService::class), new RequestStack()))->getXReport(1)['totals'];

        // The two seeded original sales carry no movement; the exchanges add 15 in and 15 out.
        $this->assertSame(15.0, $totals['sales_cash']);
        $this->assertSame(0.0, $totals['sales_card']);
        $this->assertSame(15.0, $totals['refunds_cash']);
        $this->assertSame(0.0, $totals['refunds_card']);
        $this->assertSame(0.0, $totals['net_total'], 'credits inflate neither sales nor refunds');
        $this->assertSame(100.0, $totals['expected_cash'], 'drawer: 100 + 15 - 15');
        $this->assertSame(80.0, $totals['exchange_credit']);
        $this->assertSame(2, $totals['counts']['exchanges']);
    }

    private function ticketResolver(): TicketDataResolver
    {
        return new TicketDataResolver($this->connection, new TicketVerificationService('test-secret', 'http://tpv'), 'Tienda', 'Calle 1');
    }

    public function testPrintedDocumentsOfAnExchangeShowTheCredit(): void
    {
        $this->seedSale(930005, ['cash' => 40.0, 'card' => 0.0]);
        $result = $this->service()->processExchange(1, self::ORDER_ID, $this->returnFirstLine(), 'size', '', $this->cart(930006), 'cash', 20.0, false);
        $resolver = $this->ticketResolver();

        $creditNote = $resolver->resolveRefund($result['refund']['refund_id']);
        $this->assertNotNull($creditNote);
        $this->assertNotNull($creditNote->creditNote);
        $this->assertSame(40.0, $creditNote->total);
        $this->assertSame(40.0, $creditNote->creditNote->exchangeCredit);
        $this->assertSame(0.0, $creditNote->creditNote->cashAmount);
        $this->assertSame(0.0, $creditNote->creditNote->cardAmount);

        $sale = $resolver->resolve(990001);
        $this->assertNotNull($sale);
        $this->assertSame(55.0, $sale->total);
        $this->assertSame(40.0, $sale->exchangeCredit);
        $this->assertSame($result['refund']['credit_note_number'], $sale->exchangeCreditNote);
        $this->assertSame('Abono + Efectivo', $sale->paymentMethodLabel());

        // The original sale and an ordinary ticket carry no exchange credit.
        $original = $resolver->resolve(self::ORDER_ID);
        $this->assertNotNull($original);
        $this->assertNull($original->exchangeCredit);
        $this->assertNull($original->exchangeCreditNote);
    }

    public function testCreditNoteOfAnExchangeWithMoneyBackShowsTheAppliedAndThePaidOutParts(): void
    {
        $this->seedSale(930006, ['cash' => 0.0, 'card' => 55.0]);
        $result = $this->service()->processExchange(1, self::ORDER_ID, $this->returnFirstLine(), 'size', '', $this->cart(930005), 'cash', 0.0, true);
        $resolver = $this->ticketResolver();

        $creditNote = $resolver->resolveRefund($result['refund']['refund_id']);
        $this->assertNotNull($creditNote?->creditNote);
        $this->assertSame(55.0, $creditNote->total);
        $this->assertSame(40.0, $creditNote->creditNote->exchangeCredit);
        $this->assertSame(15.0, $creditNote->creditNote->cardAmount);
        $this->assertSame(0.0, $creditNote->creditNote->cashAmount);

        $sale = $resolver->resolve(990001);
        $this->assertSame(40.0, $sale?->exchangeCredit);
        $this->assertSame('Abono', $sale->paymentMethodLabel());
    }
}
