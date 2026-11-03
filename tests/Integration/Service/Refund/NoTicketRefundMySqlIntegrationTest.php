<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Refund;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Catalog\CatalogPriceResolver;
use VenPosTPV\Service\PrestaShop\CouponCreationService;
use VenPosTPV\Service\PrestaShop\Exception\CouponCreationException;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketVerificationService;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\OrderSearchService;
use VenPosTPV\Service\Refund\PsRefundClient;
use VenPosTPV\Service\Refund\PsRestockClient;
use VenPosTPV\Service\Refund\RefundService;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * Refunds without a ticket (R11, R12, R15): current catalog price, the method the
 * cashier chooses, restock in PrestaShop without an order, and the same
 * pending / numbering lifecycle as refunds with a ticket.
 */
final class NoTicketRefundMySqlIntegrationTest extends TestCase
{
    private const SESSION_ID = 9301;
    private const SHIRT = 88003;
    private const SHIRT_M = 88007;
    private const SOCKS = 88004;

    private ?Connection $connection = null;

    /** @var array<int, array<string, mixed>> */
    private array $restockPayloads = [];

    /** @var array<int, \Throwable|null> */
    private array $restockFailures = [];

    /** @var array<int, array{input: array<string, mixed>, shop: int}> */
    private array $coupons = [];

    /** @var array<int, \Throwable|null> */
    private array $couponFailures = [];

    private bool $refundClientCalled = false;

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
        $c->insert('catalog_product', ['id' => self::SHIRT, 'name' => 'Camisa', 'price' => 20.0, 'price_ttc' => 24.2, 'price_net' => 20.0]);
        $c->insert('catalog_combination', ['id' => self::SHIRT_M, 'id_product' => self::SHIRT, 'price_impact' => 5.0, 'price_ttc' => 30.25, 'price_net' => 25.0]);
        $c->insert('catalog_combination_attribute', ['combination_id' => self::SHIRT_M, 'group_name' => 'Talla', 'attribute_name' => 'M']);
        $c->insert('catalog_product', ['id' => self::SOCKS, 'name' => 'Calcetines', 'price' => 10.33, 'price_ttc' => 12.5, 'price_net' => 10.33]);
        $c->insert('catalog_stock', ['id_product' => self::SHIRT, 'id_product_attribute' => self::SHIRT_M, 'quantity' => 5]);
        $c->insert('catalog_stock', ['id_product' => self::SOCKS, 'id_product_attribute' => 0, 'quantity' => 9]);
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->clean();
            $this->connection->close();
        }
        $this->connection = null;
    }

    private function clean(): void
    {
        $c = $this->connection;
        $c->executeStatement('DELETE FROM credit_note_sequence');
        $c->executeStatement('DELETE FROM refund_line');
        $c->executeStatement('DELETE FROM refund');
        $c->executeStatement('DELETE FROM movement WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM session WHERE id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM catalog_stock WHERE id_product IN (?, ?)', [self::SHIRT, self::SOCKS]);
        $c->executeStatement('DELETE FROM catalog_combination_attribute WHERE combination_id = ?', [self::SHIRT_M]);
        $c->executeStatement('DELETE FROM catalog_combination WHERE id = ?', [self::SHIRT_M]);
        $c->executeStatement('DELETE FROM catalog_product WHERE id IN (?, ?)', [self::SHIRT, self::SOCKS]);
    }

    private function service(): RefundService
    {
        $refundClient = $this->createMock(PsRefundClient::class);
        $refundClient->method('createRefund')->willReturnCallback(function (): array {
            $this->refundClientCalled = true;
            throw new \LogicException('A refund without ticket must not create a PrestaShop credit slip');
        });

        $restock = $this->createMock(PsRestockClient::class);
        $restock->method('restock')->willReturnCallback(function (array $payload): array {
            $this->restockPayloads[] = $payload;
            $failure = array_shift($this->restockFailures);
            if ($failure !== null) {
                throw $failure;
            }

            return [
                'restocked_lines' => array_map(static fn (array $l): array => $l + ['restocked' => true], $payload['lines']),
                'restock_failed' => [],
                'replayed' => \count($this->restockPayloads) > 1,
            ];
        });

        $coupons = $this->createMock(CouponCreationService::class);
        $coupons->method('create')->willReturnCallback(function (array $input, int $shop): array {
            $this->coupons[] = ['input' => $input, 'shop' => $shop];
            $failure = array_shift($this->couponFailures);
            if ($failure !== null) {
                throw $failure;
            }

            return ['id' => 600 + \count($this->coupons), 'code' => 'VALE' . \count($this->coupons) . 'XYZ', 'type' => 'amount', 'value' => $input['value'],
                'expires_at' => $input['expires_at'], 'quantity' => 1, 'quantity_per_user' => 1];
        });

        $cash = $this->createMock(CashSessionService::class);
        $cash->method('requireOpenSession')->willReturn(['session_id' => self::SESSION_ID, 'terminal_id' => 1, 'user_id' => 1]);

        return new RefundService(
            new OrderSearchService($this->connection),
            $refundClient,
            $this->connection,
            $cash,
            null,
            $restock,
            $coupons,
            new CatalogPriceResolver($this->connection),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function lines(bool $restockShirt = true, bool $restockSocks = true): array
    {
        return [
            // A browser price is ignored: the server prices from the catalog.
            ['product_id' => self::SHIRT, 'id_product_attribute' => self::SHIRT_M, 'quantity' => 1, 'restock' => $restockShirt, 'price' => 0.01],
            ['product_id' => self::SOCKS, 'id_product_attribute' => 0, 'quantity' => 2, 'restock' => $restockSocks],
        ];
    }

    private function stock(int $product, int $attribute): int
    {
        return (int) $this->connection->fetchOne('SELECT quantity FROM catalog_stock WHERE id_product = ? AND id_product_attribute = ?', [$product, $attribute]);
    }

    /** @return array<int, array<string, mixed>> */
    private function movements(): array
    {
        return $this->connection->fetchAllAssociative('SELECT type, amount, concept, ps_order_id, ps_order_slip_id FROM movement WHERE session_id = ? ORDER BY id', [self::SESSION_ID]);
    }

    /** @return array<string, mixed> */
    private function refundRow(): array
    {
        return $this->connection->fetchAssociative('SELECT * FROM refund ORDER BY id DESC LIMIT 1') ?: [];
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

    public function testCashRefundPaysTheCurrentCatalogPriceAndRestocksInPrestaShopWithoutAnOrder(): void
    {
        $year = (new \DateTimeImmutable())->format('Y');

        $result = $this->service()->processNoTicketRefund(1, 1, $this->lines(), 'cash', 'size');

        // 30.25 (combination TTC) + 2 x 12.50.
        $this->assertSame(55.25, $result['refunded_amount']);
        $this->assertSame(55.25, $result['cash_amount']);
        $this->assertSame(0.0, $result['card_amount']);
        $this->assertNull($result['voucher_code']);
        $this->assertSame('R' . $year . '-000001', $result['credit_note_number']);
        $this->assertFalse($result['saga_error']);
        $this->assertFalse($this->refundClientCalled);

        $this->assertCount(1, $this->restockPayloads);
        $this->assertSame($this->refundRow()['request_id'], $this->restockPayloads[0]['request_id']);
        $this->assertSame([
            ['id_product' => self::SHIRT, 'id_product_attribute' => self::SHIRT_M, 'quantity' => 1],
            ['id_product' => self::SOCKS, 'id_product_attribute' => 0, 'quantity' => 2],
        ], $this->restockPayloads[0]['lines']);
        $this->assertSame(6, $this->stock(self::SHIRT, self::SHIRT_M));
        $this->assertSame(11, $this->stock(self::SOCKS, 0));

        $this->assertEquals([['type' => 'refund', 'amount' => '55.25', 'concept' => 'Devolución sin ticket', 'ps_order_id' => null, 'ps_order_slip_id' => null]], $this->movements());
        $row = $this->refundRow();
        $this->assertSame('done', $row['status']);
        $this->assertNull($row['ps_order_id']);
        $this->assertNull($row['voucher_amount']);
        $lines = $this->connection->fetchAllAssociative('SELECT line_index, product_id, quantity, amount, restock, restocked FROM refund_line WHERE refund_id = ? ORDER BY id', [$result['refund_id']]);
        $this->assertEquals([
            ['line_index' => null, 'product_id' => self::SHIRT, 'quantity' => 1, 'amount' => '30.25', 'restock' => 1, 'restocked' => 1],
            ['line_index' => null, 'product_id' => self::SOCKS, 'quantity' => 2, 'amount' => '25.00', 'restock' => 1, 'restocked' => 1],
        ], $lines);
    }

    public function testOnlyLinesToRestockAreSentAndNoneMeansNoPrestaShopCall(): void
    {
        $service = $this->service();

        $service->processNoTicketRefund(1, 1, $this->lines(false, true), 'cash', 'defective');
        $this->assertSame([self::SOCKS], array_column($this->restockPayloads[0]['lines'], 'id_product'));
        $this->assertSame(5, $this->stock(self::SHIRT, self::SHIRT_M));

        $result = $service->processNoTicketRefund(1, 1, $this->lines(false, false), 'cash', 'defective');
        $this->assertCount(1, $this->restockPayloads, 'nothing to restock: PrestaShop is not called');
        $this->assertSame('done', $this->connection->fetchOne('SELECT status FROM refund WHERE id = ?', [$result['refund_id']]));
        $this->assertSame(11, $this->stock(self::SOCKS, 0));
    }

    public function testCardRefundNeedsConfirmationAndBooksNoCash(): void
    {
        $service = $this->service();

        $e = $this->expectRefundError(422, fn () => $service->processNoTicketRefund(1, 1, $this->lines(), 'card', 'size'));
        $this->assertStringContainsString('Confirm the 55.25 card refund on the payment terminal first', $e->getMessage());
        $this->assertSame([], $this->restockPayloads);
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM refund'));

        $result = $service->processNoTicketRefund(1, 1, $this->lines(), 'card', 'size', '', true);

        $this->assertSame(55.25, $result['card_amount']);
        $this->assertSame(0.0, $result['cash_amount']);
        $this->assertSame([], $this->movements());
    }

    public function testVoucherRefundIssuesOnePartialUseVoucherForOneYearAndBooksNoCash(): void
    {
        $result = $this->service()->processNoTicketRefund(1, 3, $this->lines(), 'voucher', 'dislike');

        $this->assertCount(1, $this->coupons);
        $this->assertSame([
            'discount_type' => 'amount',
            'value' => 55.25,
            'quantity' => 1,
            'quantity_per_user' => 1,
            'expires_at' => (new \DateTimeImmutable('+1 year'))->format('Y-m-d'),
            'partial_use' => true,
        ], $this->coupons[0]['input']);
        $this->assertSame(3, $this->coupons[0]['shop']);
        $this->assertSame('VALE1XYZ', $result['voucher_code']);
        $this->assertSame(0.0, $result['cash_amount']);
        $this->assertSame(0.0, $result['card_amount']);
        $this->assertSame(55.25, $result['refunded_amount']);
        $this->assertSame([], $this->movements());
        $row = $this->refundRow();
        $this->assertSame('VALE1XYZ', $row['voucher_code']);
        $this->assertSame('55.25', (string) $row['voucher_amount']);
        $this->assertSame(601, (int) $row['voucher_cart_rule_id']);
        $this->assertSame('done', $row['status']);
    }

    public function testVoucherWithUnknownOutcomeStaysPendingAndIsNeverCreatedTwice(): void
    {
        $service = $this->service();
        $this->couponFailures = [CouponCreationException::reconcile('ABCD2345')];

        $e = $this->expectRefundError(409, fn () => $service->processNoTicketRefund(1, 1, $this->lines(), 'voucher', 'size'));
        $this->assertStringContainsString('ABCD2345', $e->getMessage());
        $row = $this->refundRow();
        $this->assertSame('pending', $row['status']);
        $this->assertNotNull($row['voucher_requested_at']);
        $this->assertNull($row['credit_note_number']);

        $retry = $this->expectRefundError(409, fn () => $service->retryPending(1, (int) $row['id']));
        $this->assertStringContainsString('never created twice automatically', $retry->getMessage());
        $this->assertCount(1, $this->coupons, 'no second voucher');
        $this->assertSame('pending', $this->refundRow()['status']);
        $this->assertSame([], $this->movements());
    }

    public function testVoucherServiceDownBeforeSendingKeepsTheRefundPendingAndRetryIssuesItOnce(): void
    {
        $service = $this->service();
        $this->couponFailures = [CouponCreationException::unavailable()];

        $this->expectRefundError(0, fn () => $service->processNoTicketRefund(1, 1, $this->lines(), 'voucher', 'size'));
        $row = $this->refundRow();
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['voucher_requested_at'], 'nothing reached PrestaShop, so the voucher may be requested again');

        $result = $service->retryPending(1, (int) $row['id']);

        $this->assertCount(2, $this->coupons);
        $this->assertSame('VALE2XYZ', $result['voucher_code']);
        $this->assertSame($this->restockPayloads[0]['request_id'], $this->restockPayloads[1]['request_id'], 'the restock is replayed, not repeated');
        $this->assertSame(6, $this->stock(self::SHIRT, self::SHIRT_M));
    }

    public function testPrestaShopDownBlocksTheRefundUntilAnExplicitRetryWithTheSameRequestId(): void
    {
        $service = $this->service();
        $this->restockFailures = [RefundException::psUnavailable('timeout')];

        $this->expectRefundError(0, fn () => $service->processNoTicketRefund(1, 1, $this->lines(), 'cash', 'size'));
        $row = $this->refundRow();
        $this->assertSame('pending', $row['status']);
        $this->assertSame([], $this->movements());
        $this->assertSame(5, $this->stock(self::SHIRT, self::SHIRT_M));
        $this->assertSame([['id' => (int) $row['id'], 'total' => 55.25, 'method' => 'cash', 'created_at' => $row['created_at']]], array_map(
            static fn (array $p): array => array_intersect_key($p, ['id' => 1, 'total' => 1, 'method' => 1, 'created_at' => 1]),
            $service->pendingNoTicketRefunds()
        ));

        $result = $service->retryPending(1, (int) $row['id']);

        $this->assertSame($this->restockPayloads[0]['request_id'], $this->restockPayloads[1]['request_id']);
        $this->assertSame(55.25, $result['cash_amount']);
        $this->assertSame(['55.25'], array_column($this->movements(), 'amount'));
        $this->assertSame(6, $this->stock(self::SHIRT, self::SHIRT_M));
        $this->assertSame([], $service->pendingNoTicketRefunds());
    }

    public function testUnknownProductInPrestaShopFailsTheRefundWithNoEffects(): void
    {
        $this->restockFailures = [new RefundException('Product not found in PrestaShop', 404)];

        $this->expectRefundError(404, fn () => $this->service()->processNoTicketRefund(1, 1, $this->lines(), 'cash', 'size'));

        $this->assertSame('failed', $this->refundRow()['status']);
        $this->assertSame([], $this->movements());
        $this->assertSame(5, $this->stock(self::SHIRT, self::SHIRT_M));
    }

    public function testInvalidRequestsAreRejectedBeforeAnythingIsWritten(): void
    {
        $service = $this->service();
        $socks = [['product_id' => self::SOCKS, 'id_product_attribute' => 0, 'quantity' => 1, 'restock' => true]];

        $this->expectRefundError(422, fn () => $service->processNoTicketRefund(1, 1, $socks, 'bizum', 'size'));
        $this->expectRefundError(422, fn () => $service->processNoTicketRefund(1, 1, $socks, 'cash', 'whatever'));
        $this->expectRefundError(422, fn () => $service->processNoTicketRefund(1, 1, $socks, 'cash', 'other', ' '));
        $this->expectRefundError(422, fn () => $service->processNoTicketRefund(1, 1, [], 'cash', 'size'));
        $this->expectRefundError(422, fn () => $service->processNoTicketRefund(1, 1, [['product_id' => self::SOCKS, 'quantity' => 0]], 'cash', 'size'));
        $this->expectRefundError(422, fn () => $service->processNoTicketRefund(1, 1, [['product_id' => 88999, 'quantity' => 1]], 'cash', 'size'));
        $this->expectRefundError(422, fn () => $service->processNoTicketRefund(1, 1, [['product_id' => self::SOCKS, 'id_product_attribute' => 12345, 'quantity' => 1]], 'cash', 'size'));
        $this->expectRefundError(422, fn () => $service->processNoTicketRefund(1, 1, array_merge($socks, $socks), 'cash', 'size'));
        $this->expectRefundError(422, fn () => $service->processNoTicketRefund(1, 0, $socks, 'voucher', 'size'));

        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM refund'));
        $this->assertSame([], $this->restockPayloads);
        $this->assertSame([], $this->coupons);
    }

    public function testNoTicketAndTicketRefundsShareTheCreditNoteSeries(): void
    {
        $service = $this->service();
        $year = (new \DateTimeImmutable())->format('Y');

        $first = $service->processNoTicketRefund(1, 1, $this->lines(), 'cash', 'size');
        $second = $service->processNoTicketRefund(1, 1, $this->lines(), 'voucher', 'size');

        $this->assertSame('R' . $year . '-000001', $first['credit_note_number']);
        $this->assertSame('R' . $year . '-000002', $second['credit_note_number']);
    }

    public function testCreditNoteSaysSinTicketNamesLinesFromTheCatalogAndCarriesTheVoucher(): void
    {
        $refund = $this->service()->processNoTicketRefund(1, 1, $this->lines(), 'voucher', 'other', 'Sin caja');
        $verification = $this->createMock(TicketVerificationService::class);
        $verification->method('generateToken')->willReturn('tok');
        $verification->method('buildVerificationUrl')->willReturn('http://tpv/verify?token=tok');

        $ticket = (new TicketDataResolver($this->connection, $verification, 'Tienda Test', 'Calle 1'))->resolveRefund($refund['refund_id']);

        $this->assertNotNull($ticket);
        $note = $ticket->creditNote;
        $this->assertNotNull($note);
        $this->assertSame('Sin ticket', $note->rectifiesReference);
        $this->assertNull($note->rectifiesDate);
        $this->assertSame('Otro: Sin caja', $note->reason);
        $this->assertSame('VALE1XYZ', $note->voucherCode);
        $this->assertSame(55.25, $note->voucherAmount);
        $this->assertSame(0.0, $note->cashAmount);
        $this->assertSame(['Camisa (Talla: M)', 'Calcetines'], array_map(static fn ($l) => $l->name, $ticket->lines));
        $this->assertSame([30.25, 25.0], array_map(static fn ($l) => $l->lineTotal, $ticket->lines));
        $this->assertSame(55.25, $ticket->total);
        // VAT from the catalog: 30.25 - 25.00 and 2 x (12.50 - 10.33).
        $this->assertSame(9.59, $ticket->tax);
        $this->assertSame(0, $ticket->orderId);
    }
}
