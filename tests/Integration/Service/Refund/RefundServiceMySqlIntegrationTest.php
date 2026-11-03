<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Refund;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\OrderSearchService;
use VenPosTPV\Service\Refund\PsRefundClient;
use VenPosTPV\Service\Refund\RefundService;
use VenPosTPV\Tests\Support\TestDatabase;

final class RefundServiceMySqlIntegrationTest extends TestCase
{
    private const SESSION_ID = 9101;
    private const ORDER_ID = 880001;

    private ?Connection $connection = null;

    private int $nextSessionId = self::SESSION_ID;

    /** @var (callable(): void)|null runs inside the next PrestaShop call, to model a concurrent request */
    private $duringPsCall = null;

    /** @var array<int, array<string, mixed>> */
    private array $psPayloads = [];

    /** @var array<int, \Throwable|null> per-call failure, consumed in order */
    private array $psFailures = [];

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->cleanTables();
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $this->connection->insert('session', [
            'id' => self::SESSION_ID, 'terminal_id' => 1, 'user_id' => 1,
            'opened_at' => '2026-11-03 09:00:00', 'opening_cash' => 100,
        ]);
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        foreach ([[3, 7, 5], [3, 8, 5], [4, 0, 9]] as [$product, $attribute, $qty]) {
            $this->connection->insert('catalog_stock', ['id_product' => $product, 'id_product_attribute' => $attribute, 'quantity' => $qty]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->cleanTables();
            $this->connection->close();
        }
        $this->connection = null;
    }

    private function cleanTables(): void
    {
        $this->connection->executeStatement('DELETE FROM refund_line');
        $this->connection->executeStatement('DELETE FROM refund');
        $this->connection->executeStatement('DELETE FROM movement WHERE session_id = ?', [self::SESSION_ID]);
        $this->connection->executeStatement('DELETE FROM order_meta WHERE ps_order_id >= 880000');
        $this->connection->executeStatement('DELETE FROM session WHERE id = ?', [self::SESSION_ID]);
        $this->connection->executeStatement('DELETE FROM catalog_stock WHERE id_product IN (3, 4)');
    }

    /** @param array{cash: float, card: float} $tender */
    private function seedOrder(array $tender, bool $withProductIds = true, int $orderId = self::ORDER_ID): void
    {
        $lines = [
            ['name' => 'Camisa M', 'product_id' => 3, 'id_product_attribute' => 7, 'qty' => 1, 'price' => 30.0, 'line_total' => 30.0, 'paid_total' => 24.18],
            ['name' => 'Calcetines', 'product_id' => 4, 'id_product_attribute' => 0, 'qty' => 2, 'price' => 20.0, 'line_total' => 40.0, 'paid_total' => 35.82],
        ];
        if (!$withProductIds) {
            $lines = array_map(static fn (array $l): array => array_diff_key($l, ['product_id' => 1, 'id_product_attribute' => 1, 'paid_total' => 1]), $lines);
        }
        $this->connection->insert('order_meta', [
            'ps_order_id' => $orderId, 'terminal_id' => 1, 'session_id' => self::SESSION_ID, 'user_id' => 1,
            'payment_breakdown' => json_encode([
                'reference' => 'REF' . $orderId, 'total' => 60.0,
                'payment_method' => $tender['card'] > 0 && $tender['cash'] > 0 ? 'mixed' : ($tender['card'] > 0 ? 'card' : 'cash'),
                'amount_tendered' => 60.0, 'change_due' => 0, 'lines' => $lines, 'tender' => $tender,
            ], JSON_THROW_ON_ERROR),
            'created_at' => '2026-11-03 10:00:00',
        ]);
    }

    private function service(): RefundService
    {
        $client = $this->createMock(PsRefundClient::class);
        $client->method('createRefund')->willReturnCallback(function (array $payload): array {
            $this->psPayloads[] = $payload;
            if ($this->duringPsCall !== null) {
                $concurrent = $this->duringPsCall;
                $this->duringPsCall = null;
                $concurrent();
            }
            $failure = array_shift($this->psFailures);
            if ($failure !== null) {
                throw $failure;
            }

            return [
                'order_slip_id' => 700 + \count($this->psPayloads),
                'refunded_amount' => round(array_sum(array_column($payload['lines'], 'amount')), 2),
                'refunded_lines' => array_map(static fn (array $l): array => $l + ['restocked' => $l['restock']], $payload['lines']),
                'restock_failed' => [],
                'replayed' => false,
            ];
        });
        $cash = $this->createMock(CashSessionService::class);
        $cash->method('requireOpenSession')->willReturnCallback(fn (): array => ['session_id' => $this->nextSessionId, 'terminal_id' => 1, 'user_id' => 1]);

        return new RefundService(new OrderSearchService($this->connection), $client, $this->connection, $cash);
    }

    private function stock(int $product, int $attribute): int
    {
        return (int) $this->connection->fetchOne('SELECT quantity FROM catalog_stock WHERE id_product = ? AND id_product_attribute = ?', [$product, $attribute]);
    }

    /** @return array<int, array<string, mixed>> */
    private function movements(): array
    {
        return $this->connection->fetchAllAssociative('SELECT type, amount, ps_order_id, ps_order_slip_id FROM movement WHERE session_id = ? ORDER BY id', [self::SESSION_ID]);
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

    public function testPartialCashRefundReturnsPaidShareAsPositiveRefundMovementAndRestocksCombination(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);

        $result = $this->service()->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size');

        $this->assertSame(24.18, $result['refunded_amount']);
        $this->assertSame(24.18, $result['cash_amount']);
        $this->assertSame(0.0, $result['card_amount']);
        $this->assertFalse($result['saga_error']);
        $payload = $this->psPayloads[0];
        $this->assertSame(self::ORDER_ID, $payload['ps_order_id']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{8,64}$/', $payload['request_id']);
        $this->assertSame([['id_product' => 3, 'id_product_attribute' => 7, 'quantity' => 1, 'amount' => 24.18, 'restock' => true]], $payload['lines']);
        // Stored positive: the cash report subtracts refunds from the expected drawer.
        $this->assertEquals([['type' => 'refund', 'amount' => '24.18', 'ps_order_id' => self::ORDER_ID, 'ps_order_slip_id' => 701]], $this->movements());
        $this->assertSame(6, $this->stock(3, 7));
        $this->assertSame(5, $this->stock(3, 8));
        $this->assertSame('24.18', (string) $this->connection->fetchOne('SELECT refunded_amount FROM order_meta WHERE ps_order_id = ?', [self::ORDER_ID]));
        $this->assertSame('done', $this->connection->fetchOne('SELECT status FROM refund WHERE id = ?', [$result['refund_id']]));
    }

    public function testUnitsAreRefundedUntilExhaustedAndLastUnitTakesTheRemainder(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);
        $service = $this->service();

        $first = $service->processRefund(1, self::ORDER_ID, [['line_index' => 1, 'quantity' => 1, 'restock' => true]], 'dislike');
        $second = $service->processRefund(1, self::ORDER_ID, [['line_index' => 1, 'quantity' => 1, 'restock' => true]], 'dislike');

        $this->assertSame(17.91, $first['refunded_amount']);
        $this->assertSame(17.91, $second['refunded_amount']);
        $this->expectRefundError(422, fn () => $service->processRefund(1, self::ORDER_ID, [['line_index' => 1, 'quantity' => 1, 'restock' => true]], 'dislike'));
        $this->assertCount(2, $this->psPayloads);
        $this->assertSame(11, $this->stock(4, 0));
    }

    public function testFullRefundReturnsEverythingStillRefundable(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);
        $service = $this->service();
        $service->processRefund(1, self::ORDER_ID, [['line_index' => 1, 'quantity' => 1, 'restock' => true]], 'size');

        $result = $service->processRefund(1, self::ORDER_ID, null, 'size');

        $this->assertSame(42.09, $result['refunded_amount']);
        $this->assertSame([3, 4], array_column($this->psPayloads[1]['lines'], 'id_product'));
        $this->assertSame([1, 1], array_column($this->psPayloads[1]['lines'], 'quantity'));
    }

    public function testMixedRefundSplitsByOriginalTenderAndRequiresCardConfirmation(): void
    {
        $this->seedOrder(['cash' => 25.0, 'card' => 35.0]);
        $service = $this->service();

        $this->expectRefundError(422, fn () => $service->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size'));
        $this->assertSame([], $this->psPayloads);

        $result = $service->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size', '', true);

        // 24.18 x 25/60 = 10.08 in cash, the rest back to the card.
        $this->assertSame(10.08, $result['cash_amount']);
        $this->assertSame(14.1, $result['card_amount']);
        $this->assertSame(['10.08'], array_column($this->movements(), 'amount'));
    }

    public function testCardRefundCreatesNoCashMovement(): void
    {
        $this->seedOrder(['cash' => 0.0, 'card' => 60.0]);

        $result = $this->service()->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => false]], 'defective', '', true);

        $this->assertSame(24.18, $result['card_amount']);
        $this->assertSame([], $this->movements());
        $this->assertSame(5, $this->stock(3, 7));
        $this->assertFalse($this->psPayloads[0]['lines'][0]['restock']);
    }

    public function testReasonMustBeFromTheListAndOtherNeedsText(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);
        $service = $this->service();
        $line = [['line_index' => 0, 'quantity' => 1, 'restock' => true]];

        $this->expectRefundError(422, fn () => $service->processRefund(1, self::ORDER_ID, $line, 'whatever'));
        $this->expectRefundError(422, fn () => $service->processRefund(1, self::ORDER_ID, $line, 'other', '   '));
        $this->assertSame([], $this->psPayloads);

        $result = $service->processRefund(1, self::ORDER_ID, $line, 'other', 'Cliente cambió de idea');
        $this->assertSame('Cliente cambió de idea', $this->connection->fetchOne('SELECT reason_text FROM refund WHERE id = ?', [$result['refund_id']]));
    }

    public function testLegacySaleWithoutProductIdsIsRejected(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0], false);

        $this->expectRefundError(422, fn () => $this->service()->processRefund(1, self::ORDER_ID, null, 'size'));
        $this->assertSame([], $this->psPayloads);
    }

    public function testRejectionByPrestaShopLeavesNoMoneyOrStockEffects(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);
        $this->psFailures = [RefundException::validationFailed('PS validation failed')];

        $this->expectRefundError(422, fn () => $this->service()->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size'));

        $this->assertSame([], $this->movements());
        $this->assertSame(5, $this->stock(3, 7));
        $this->assertSame('failed', $this->connection->fetchOne('SELECT status FROM refund'));
    }

    public function testUncertainCallBlocksNewRefundsUntilExplicitRetryWithSameRequestId(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);
        $service = $this->service();
        $this->psFailures = [RefundException::psUnavailable('timeout')];
        $line = [['line_index' => 0, 'quantity' => 1, 'restock' => true]];

        $this->expectRefundError(0, fn () => $service->processRefund(1, self::ORDER_ID, $line, 'size'));
        $refundId = (int) $this->connection->fetchOne('SELECT id FROM refund');
        $this->assertSame('pending', $this->connection->fetchOne('SELECT status FROM refund'));
        $this->assertSame([], $this->movements());

        // Nothing is sent on its own: a new refund is refused until the cashier retries.
        $this->expectRefundError(409, fn () => $service->processRefund(1, self::ORDER_ID, $line, 'size'));
        $this->assertCount(1, $this->psPayloads);
        $order = (new OrderSearchService($this->connection))->getOrder(self::ORDER_ID);
        $this->assertSame(['id' => $refundId, 'total' => 24.18], $order['pending_refund']);

        $result = $service->retryPending(1, $refundId);

        $this->assertCount(2, $this->psPayloads);
        $this->assertSame($this->psPayloads[0]['request_id'], $this->psPayloads[1]['request_id']);
        $this->assertSame($refundId, $result['refund_id']);
        $this->assertSame('done', $this->connection->fetchOne('SELECT status FROM refund'));
        $this->assertSame(['24.18'], array_column($this->movements(), 'amount'));
        $this->assertSame(6, $this->stock(3, 7));
        $this->expectRefundError(409, fn () => $service->retryPending(1, $refundId));
    }

    public function testTwoRetriesOfTheSameRefundBookItOnlyOnce(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);
        $service = $this->service();
        $this->psFailures = [RefundException::psUnavailable('timeout')];
        $this->expectRefundError(0, fn () => $service->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size'));
        $refundId = (int) $this->connection->fetchOne('SELECT id FROM refund');

        // A second terminal retries while the first retry waits for PrestaShop.
        $this->duringPsCall = fn () => $service->retryPending(1, $refundId);
        $service->retryPending(1, $refundId);

        $this->assertSame(['24.18'], array_column($this->movements(), 'amount'));
        $this->assertSame(6, $this->stock(3, 7));
        $this->assertSame('24.18', (string) $this->connection->fetchOne('SELECT refunded_amount FROM order_meta WHERE ps_order_id = ?', [self::ORDER_ID]));
    }

    public function testBusyShopKeepsTheRefundPendingInsteadOfFailingIt(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);
        $this->psFailures = [RefundException::conflict('Another refund for this order is in progress in PrestaShop')];

        $this->expectRefundError(409, fn () => $this->service()->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size'));

        $this->assertSame('pending', $this->connection->fetchOne('SELECT status FROM refund'));
    }

    public function testRetryRecordsCashInTheCurrentSession(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);
        $this->psFailures = [RefundException::psUnavailable('timeout')];
        $this->expectRefundError(0, fn () => $this->service()->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size'));
        $refundId = (int) $this->connection->fetchOne('SELECT id FROM refund');

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $this->connection->insert('session', ['id' => self::SESSION_ID + 1, 'terminal_id' => 1, 'user_id' => 1, 'opened_at' => '2026-11-04 09:00:00', 'opening_cash' => 50]);
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $this->nextSessionId = self::SESSION_ID + 1;

        $this->service()->retryPending(1, $refundId);

        $this->assertSame(
            [self::SESSION_ID + 1],
            array_map('intval', $this->connection->fetchFirstColumn("SELECT session_id FROM movement WHERE type = 'refund' AND ps_order_id = ?", [self::ORDER_ID]))
        );
        $this->connection->executeStatement('DELETE FROM movement WHERE session_id = ?', [self::SESSION_ID + 1]);
        $this->connection->executeStatement('DELETE FROM session WHERE id = ?', [self::SESSION_ID + 1]);
    }

    public function testUnknownOrderIs404(): void
    {
        $this->expectRefundError(404, fn () => $this->service()->processRefund(1, 880999, null, 'size'));
        $this->assertSame([], $this->psPayloads);
    }

    public function testRefundableQuantitiesAreExposedPerLine(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);
        $this->service()->processRefund(1, self::ORDER_ID, [['line_index' => 1, 'quantity' => 1, 'restock' => true]], 'size');

        $order = (new OrderSearchService($this->connection))->getOrder(self::ORDER_ID);

        $this->assertSame([0, 1], array_column($order['lines'], 'refunded_qty'));
        $this->assertSame([1, 1], array_column($order['lines'], 'refundable_qty'));
    }
}
