<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Refund;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\TerminalService;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\PrestaShop\CouponCreationService;
use VenPosTPV\Service\PrestaShop\Exception\CouponCreationException;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketVerificationService;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\OrderSearchService;
use VenPosTPV\Service\Refund\PsRefundClient;
use VenPosTPV\Service\Refund\RefundService;
use VenPosTPV\Service\Sync\OutboxSync;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * T7b (L26): goods of a sale paid (partly) with exchange credit. The refund splits
 * by the sale's tender {cash, card, exchange_credit}; the money shares go back as
 * always and the credit share becomes a PrestaShop voucher (1 year, partial use),
 * so cash that never entered the drawer for that sale is never paid out. The voucher
 * needs PrestaShop at once (PRD D3); the slip is queued behind the sale (upload()).
 */
final class ExchangeCreditRefundMySqlIntegrationTest extends TestCase
{
    private const SESSION_ID = 9302;
    private const NEXT_SESSION_ID = 9303;
    private const ORDER_ID = 940001;
    private const PRODUCT = 940005;

    private ?Connection $connection = null;

    /** @var array<int, array<string, mixed>> */
    private array $refundPayloads = [];

    /** @var array<int, array{input: array<string, mixed>, shop: int}> */
    private array $coupons = [];

    /** @var array<int, \Throwable|null> */
    private array $couponFailures = [];

    /** @var (callable(): void)|null runs while PrestaShop creates the voucher */
    private $duringCoupon = null;

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
        $c->insert('catalog_product', ['id' => self::PRODUCT, 'name' => 'Chaqueta', 'price' => 45.45, 'price_ttc' => 55.0, 'price_net' => 45.45]);
        $c->insert('catalog_stock', ['id_product' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 5]);
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
        $c->executeStatement('DELETE FROM outbox WHERE order_id IN (SELECT id FROM order_meta WHERE ps_order_id >= 940000 AND ps_order_id < 950000)');
        $c->executeStatement('DELETE FROM refund_line WHERE refund_id IN (SELECT id FROM refund WHERE ps_order_id >= 940000 AND ps_order_id < 950000)');
        $c->executeStatement('DELETE FROM refund WHERE ps_order_id >= 940000 AND ps_order_id < 950000');
        $c->executeStatement('DELETE FROM credit_note_sequence');
        $c->executeStatement('DELETE FROM movement WHERE session_id IN (?, ?)', [self::SESSION_ID, self::NEXT_SESSION_ID]);
        $c->executeStatement('DELETE FROM order_meta WHERE ps_order_id >= 940000 AND ps_order_id < 950000');
        $c->executeStatement('DELETE FROM z_report WHERE session_id IN (?, ?)', [self::SESSION_ID, self::NEXT_SESSION_ID]);
        $c->executeStatement('DELETE FROM session WHERE id IN (?, ?)', [self::SESSION_ID, self::NEXT_SESSION_ID]);
        $c->executeStatement('DELETE FROM catalog_stock WHERE id_product = ?', [self::PRODUCT]);
        $c->executeStatement('DELETE FROM catalog_product WHERE id = ?', [self::PRODUCT]);
    }

    /**
     * A TPV sale of $qty units paid $paid in total, by $tender.
     *
     * @param array<string, float> $tender
     */
    private function seedSale(array $tender, float $paid = 55.0, int $qty = 1): void
    {
        $this->connection->insert('order_meta', [
            'ps_order_id' => self::ORDER_ID, 'terminal_id' => 1, 'session_id' => self::SESSION_ID, 'user_id' => 1,
            'payment_breakdown' => json_encode([
                'reference' => 'EXCH' . self::ORDER_ID, 'total' => $paid,
                'payment_method' => isset($tender['exchange_credit']) ? 'exchange' : 'cash',
                'amount_tendered' => 0, 'change_due' => 0, 'tax' => round($paid - $paid / 1.21, 2),
                'lines' => [['name' => 'Chaqueta', 'product_id' => self::PRODUCT, 'id_product_attribute' => 0, 'qty' => $qty, 'price' => round($paid / $qty, 2), 'line_total' => $paid, 'paid_total' => $paid]],
                'tender' => $tender,
            ], JSON_THROW_ON_ERROR),
            'created_at' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'),
        ]);
    }

    /** Runs the outbox once, as the minute task does. */
    private function upload(): void
    {
        $orders = $this->createMock(PsOrderClient::class);
        $orders->expects($this->never())->method('createReferencedOrder');
        $at = new \DateTimeImmutable('+1 minute');
        (new OutboxSync($this->connection, $orders, null, fn (): \DateTimeImmutable => $at, $this->refundClient()))->run();
    }

    private function refundClient(): PsRefundClient
    {
        $refundClient = $this->createMock(PsRefundClient::class);
        $refundClient->method('createRefund')->willReturnCallback(function (array $payload): array {
            $this->refundPayloads[] = $payload;

            return [
                'order_slip_id' => 700 + \count($this->refundPayloads),
                'refunded_amount' => round(array_sum(array_column($payload['lines'], 'amount')), 2),
                'refunded_lines' => array_map(static fn (array $l): array => $l + ['restocked' => $l['restock']], $payload['lines']),
                'restock_failed' => [],
                'replayed' => \count($this->refundPayloads) > 1,
            ];
        });

        return $refundClient;
    }

    private function service(): RefundService
    {
        $c = $this->connection;
        $coupons = $this->createMock(CouponCreationService::class);
        $coupons->method('create')->willReturnCallback(function (array $input, int $shop): array {
            $this->coupons[] = ['input' => $input, 'shop' => $shop];
            if ($this->duringCoupon !== null) {
                ($this->duringCoupon)();
            }
            $failure = array_shift($this->couponFailures);
            if ($failure !== null) {
                throw $failure;
            }

            return ['id' => 650 + \count($this->coupons), 'code' => 'CRED' . \count($this->coupons) . 'XYZ', 'type' => 'amount', 'value' => $input['value'],
                'expires_at' => $input['expires_at'], 'quantity' => 1, 'quantity_per_user' => 1];
        });

        $cash = new CashSessionService($c, $this->createMock(TerminalService::class), new RequestStack());

        return new RefundService(new OrderSearchService($c), $this->refundClient(), $c, $cash, null, null, $coupons);
    }

    /** @return array<int, array{type: string, amount: string}> */
    private function movements(): array
    {
        return $this->connection->fetchAllAssociative('SELECT type, amount FROM movement WHERE session_id = ? ORDER BY id', [self::SESSION_ID]);
    }

    /** @return array<string, mixed> */
    private function refundRow(): array
    {
        return $this->connection->fetchAssociative('SELECT * FROM refund WHERE ps_order_id = ? ORDER BY id DESC LIMIT 1', [self::ORDER_ID]) ?: [];
    }

    /** @return array<string, mixed> */
    private function totals(): array
    {
        return (new CashSessionService($this->connection, $this->createMock(TerminalService::class), new RequestStack()))->getXReport(1)['totals'];
    }

    private function expectRefundError(int $code, callable $call): RefundException
    {
        try {
            $call();
        } catch (RefundException $e) {
            $this->assertSame($code, $e->getCode(), $e->getMessage());

            return $e;
        }
        $this->fail('Expected RefundException ' . $code);
    }

    /** @return array<int, array{line_index: int, quantity: int, restock: bool}> */
    private function one(): array
    {
        return [['line_index' => 0, 'quantity' => 1, 'restock' => true]];
    }

    public function testSaleFullyPaidByExchangeCreditIsRefundedAsOneVoucherWithNoCash(): void
    {
        $this->seedSale(['cash' => 0.0, 'card' => 0.0, 'exchange_credit' => 55.0]);

        $result = $this->service()->processRefund(1, self::ORDER_ID, $this->one(), 'dislike', '', false, 3);

        $this->assertSame(55.0, $result['refunded_amount']);
        $this->assertSame(0.0, $result['cash_amount']);
        $this->assertSame(0.0, $result['card_amount']);
        $this->assertSame('CRED1XYZ', $result['voucher_code']);
        $this->assertNotNull($result['credit_note_number']);
        $this->assertSame([], $this->movements(), 'no cash leaves the drawer');

        $this->assertCount(1, $this->coupons);
        $this->assertSame(3, $this->coupons[0]['shop']);
        $input = $this->coupons[0]['input'];
        $this->assertSame(55.0, $input['value']);
        $this->assertTrue($input['partial_use']);
        $this->assertSame(1, $input['quantity']);
        $this->assertSame((new \DateTimeImmutable('+1 year'))->format('Y-m-d'), $input['expires_at']);

        $row = $this->refundRow();
        $this->assertSame('done', $row['status']);
        $this->assertSame('55.00', (string) $row['voucher_amount']);
        $this->assertSame('CRED1XYZ', $row['voucher_code']);
        $this->assertSame(651, (int) $row['voucher_cart_rule_id']);
        $this->upload();
        $this->assertArrayNotHasKey('voucher_shop_id', $this->refundPayloads[0], 'PrestaShop receives the usual refund payload');

        $totals = $this->totals();
        $this->assertSame(55.0, $totals['refunds_voucher']);
        $this->assertSame(0.0, $totals['refunds_cash']);
        $this->assertSame(100.0, $totals['expected_cash']);
    }

    public function testMixedCashAndCreditSaleSplitsCentExactIntoCashAndVoucher(): void
    {
        // 15 in cash + 40 of exchange credit for three units; one unit back = 18.33.
        $this->seedSale(['cash' => 15.0, 'card' => 0.0, 'exchange_credit' => 40.0], 55.0, 3);

        $result = $this->service()->processRefund(1, self::ORDER_ID, $this->one(), 'size', '', false, 1);

        // 18.33 x 15/55 = 5.00 in cash, the 13.33 left as voucher.
        $this->assertSame(18.33, $result['refunded_amount']);
        $this->assertSame(5.0, $result['cash_amount']);
        $this->assertSame(0.0, $result['card_amount']);
        $this->assertSame(13.33, $this->coupons[0]['input']['value']);
        $this->assertEquals([['type' => 'refund', 'amount' => '5.00']], $this->movements());
        $row = $this->refundRow();
        $this->assertSame('13.33', (string) $row['voucher_amount']);
        $this->assertSame('5.00', (string) $row['cash_amount']);
    }

    public function testCashCardAndCreditSaleNeedsCardConfirmationAndSplitsThreeWays(): void
    {
        $this->seedSale(['cash' => 10.0, 'card' => 20.0, 'exchange_credit' => 25.0]);
        $service = $this->service();

        $this->expectRefundError(422, fn () => $service->processRefund(1, self::ORDER_ID, $this->one(), 'size', '', false, 1));
        $this->assertSame([], $this->coupons);

        $result = $service->processRefund(1, self::ORDER_ID, $this->one(), 'size', '', true, 1);

        $this->assertSame(10.0, $result['cash_amount']);
        $this->assertSame(20.0, $result['card_amount']);
        $this->assertSame(25.0, $this->coupons[0]['input']['value']);
        $totals = $this->totals();
        $this->assertSame(10.0, $totals['refunds_cash']);
        $this->assertSame(20.0, $totals['refunds_card']);
        $this->assertSame(25.0, $totals['refunds_voucher']);
        $this->assertSame(55.0, $totals['refunds_total']);
    }

    public function testVoucherWithUnknownOutcomeKeepsTheRefundPendingAndIsNeverCreatedTwice(): void
    {
        $this->seedSale(['cash' => 15.0, 'card' => 0.0, 'exchange_credit' => 40.0]);
        $service = $this->service();
        $this->couponFailures = [CouponCreationException::reconcile('ABCD2345')];

        $e = $this->expectRefundError(409, fn () => $service->processRefund(1, self::ORDER_ID, $this->one(), 'size', '', false, 1));
        $this->assertStringContainsString('ABCD2345', $e->getMessage());
        $row = $this->refundRow();
        $this->assertSame('pending', $row['status']);
        $this->assertNotNull($row['voucher_requested_at']);
        $this->assertNull($row['credit_note_number']);
        $this->assertSame([], $this->movements(), 'not even the cash part is paid while the voucher is unknown');

        $retry = $this->expectRefundError(409, fn () => $service->retryPending(1, (int) $row['id']));
        $this->assertStringContainsString('never created twice automatically', $retry->getMessage());
        $this->assertCount(1, $this->coupons, 'no second voucher');
        $this->upload();
        $this->assertSame([], $this->refundPayloads, 'nothing is queued for PrestaShop while the voucher is unknown');
        $this->assertSame('pending', $this->refundRow()['status']);
        $this->assertSame([], $this->movements());
    }

    public function testVoucherIssuedAfterCloseDoesNotCompleteInTheClosedSession(): void
    {
        $this->seedSale(['cash' => 15.0, 'card' => 0.0, 'exchange_credit' => 40.0], 55.0, 3);
        $c = $this->connection;
        $cash = new CashSessionService($c, $this->createMock(TerminalService::class), new RequestStack());
        // The drawer is closed while PrestaShop is still creating the voucher.
        $this->duringCoupon = function () use ($cash): void {
            $this->duringCoupon = null;
            $cash->closeSession(1, 100.0);
        };
        $service = $this->service();

        $this->expectRefundError(409, fn () => $service->processRefund(1, self::ORDER_ID, $this->one(), 'size', '', false, 1));

        $row = $this->refundRow();
        $this->assertSame('pending', $row['status']);
        $this->assertSame('CRED1XYZ', $row['voucher_code'], 'the voucher exists and is kept');
        $this->assertNull($row['credit_note_number']);
        $this->assertSame([], $this->movements(), 'nothing is booked in the closed session');
        $this->assertSame('100.00', (string) $c->fetchOne('SELECT expected_cash FROM session WHERE id = ?', [self::SESSION_ID]));
        $zTotals = (string) $c->fetchOne('SELECT totals FROM z_report WHERE session_id = ?', [self::SESSION_ID]);

        // Next session: the retry completes it there without a second voucher.
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->insert('session', ['id' => self::NEXT_SESSION_ID, 'terminal_id' => 1, 'user_id' => 1, 'opened_at' => '2026-11-03 18:00:00', 'opening_cash' => 50]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $_SESSION['cash_session_id'] = self::NEXT_SESSION_ID;
        $result = $service->retryPending(1, (int) $row['id']);

        $this->assertSame(5.0, $result['cash_amount']);
        $this->assertCount(1, $this->coupons, 'no second voucher');
        $done = $this->refundRow();
        $this->assertSame('done', $done['status']);
        $this->assertSame(self::NEXT_SESSION_ID, (int) $done['session_id']);
        $this->assertEquals(
            [['type' => 'refund', 'amount' => '5.00']],
            $c->fetchAllAssociative('SELECT type, amount FROM movement WHERE session_id = ?', [self::NEXT_SESSION_ID])
        );
        $this->assertSame([], $this->movements());
        $this->assertSame($zTotals, (string) $c->fetchOne('SELECT totals FROM z_report WHERE session_id = ?', [self::SESSION_ID]), 'the Z is unchanged');
    }

    public function testVoucherShareNeedsAKnownShopBeforeAnythingIsWritten(): void
    {
        $this->seedSale(['cash' => 0.0, 'card' => 0.0, 'exchange_credit' => 55.0]);

        $this->expectRefundError(422, fn () => $this->service()->processRefund(1, self::ORDER_ID, $this->one(), 'size'));

        $this->assertSame([], $this->refundRow());
        $this->assertSame([], $this->refundPayloads);
    }

    public function testOrdinarySaleIsRefundedExactlyAsBeforeWithNoVoucher(): void
    {
        $this->seedSale(['cash' => 55.0, 'card' => 0.0]);

        $result = $this->service()->processRefund(1, self::ORDER_ID, $this->one(), 'size', '', false, 1);

        $this->assertSame(55.0, $result['cash_amount']);
        $this->assertNull($result['voucher_code']);
        $this->assertSame([], $this->coupons);
        $this->assertNull($this->refundRow()['voucher_amount']);
        $this->assertEquals([['type' => 'refund', 'amount' => '55.00']], $this->movements());
        $this->upload();
        // Exactly these keys, no voucher ones; the outbox JSON column does not keep their order.
        $this->assertEqualsCanonicalizing(['ps_order_id', 'request_id', 'refund_type', 'reason', 'lines'], array_keys($this->refundPayloads[0]));
        $this->assertCount(5, $this->refundPayloads[0]);
    }

    public function testVoucherShareWithPrestaShopUnreachableFailsCleanlyAndCanBePaidInCashInstead(): void
    {
        $this->seedSale(['cash' => 15.0, 'card' => 0.0, 'exchange_credit' => 40.0], 55.0, 3);
        $service = $this->service();
        $this->couponFailures = [CouponCreationException::unavailable()];

        $e = $this->expectRefundError(RefundException::VOUCHER_UNAVAILABLE, fn () => $service->processRefund(1, self::ORDER_ID, $this->one(), 'size', '', false, 1));

        $this->assertSame('Sin conexión con PrestaShop: el vale no se puede crear ahora. Elige efectivo o tarjeta.', $e->getMessage());
        $this->assertSame('failed', $this->refundRow()['status']);
        $this->assertNull($this->refundRow()['credit_note_number']);
        $this->assertSame([], $this->movements(), 'nothing is paid');
        $this->assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM outbox o JOIN order_meta m ON m.id = o.order_id WHERE m.ps_order_id = ?", [self::ORDER_ID]));

        // The cashier chooses cash for the voucher share (PRD D3).
        $result = $service->processRefund(1, self::ORDER_ID, $this->one(), 'size', '', false, 1, 'cash');

        $this->assertSame(18.33, $result['cash_amount'], '5.00 cash share + 13.33 instead of the voucher');
        $this->assertNull($result['voucher_code']);
        $this->assertCount(1, $this->coupons, 'no second voucher attempt');
        $this->assertEquals([['type' => 'refund', 'amount' => '18.33']], $this->movements());
        $this->assertNull($this->refundRow()['voucher_amount']);
    }

    public function testCreditNoteShowsTheCashPartAndTheVoucherLine(): void
    {
        $this->seedSale(['cash' => 15.0, 'card' => 0.0, 'exchange_credit' => 40.0]);
        $refund = $this->service()->processRefund(1, self::ORDER_ID, $this->one(), 'size', '', false, 1);
        $verification = $this->createMock(TicketVerificationService::class);
        $verification->method('generateToken')->willReturn('tok');
        $verification->method('buildVerificationUrl')->willReturn('http://tpv/verify?token=tok');

        $ticket = (new TicketDataResolver($this->connection, $verification, 'Tienda Test', 'Calle 1'))->resolveRefund($refund['refund_id']);

        $note = $ticket?->creditNote;
        $this->assertNotNull($note);
        $this->assertSame('EXCH' . self::ORDER_ID, $note->rectifiesReference);
        $this->assertSame(15.0, $note->cashAmount);
        $this->assertSame(0.0, $note->cardAmount);
        $this->assertSame('CRED1XYZ', $note->voucherCode);
        $this->assertSame(40.0, $note->voucherAmount);
        $this->assertNull($note->exchangeCredit);
        $this->assertSame(55.0, $ticket->total);
    }
}
