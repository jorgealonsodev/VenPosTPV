<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Refund;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\TerminalService;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketVerificationService;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\OrderSearchService;
use VenPosTPV\Service\Refund\PsRefundClient;
use VenPosTPV\Service\Refund\RefundService;
use VenPosTPV\Service\Sync\OutboxSync;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * Refunds of TPV sales are local first (PRD venta-local-primero D1, D2): the credit
 * note, cash and local stock happen at once and the PrestaShop slip is queued in the
 * outbox behind the sale; upload() runs the outbox like the minute task does.
 */
final class RefundServiceMySqlIntegrationTest extends TestCase
{
    private const SESSION_ID = 9101;
    private const ORDER_ID = 880001;

    private ?Connection $connection = null;

    private int $nextSessionId = self::SESSION_ID;

    private int $nextTerminalId = 1;

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
        $this->connection->executeStatement('DELETE FROM outbox');
        $this->connection->executeStatement('DELETE FROM credit_note_sequence');
        $this->connection->executeStatement('DELETE FROM refund_line');
        $this->connection->executeStatement('DELETE FROM refund');
        $this->connection->executeStatement('DELETE FROM movement WHERE session_id = ?', [self::SESSION_ID]);
        $this->connection->executeStatement('DELETE FROM order_meta WHERE ps_order_id >= 880000');
        $this->connection->executeStatement('DELETE FROM session WHERE id = ?', [self::SESSION_ID]);
        $this->connection->executeStatement('DELETE FROM terminal WHERE id = 9101');
        $this->connection->executeStatement('DELETE FROM catalog_stock WHERE id_product IN (3, 4)');
    }

    /** @param array{cash: float, card: float} $tender */
    private function seedOrder(array $tender, bool $withProductIds = true, int $orderId = self::ORDER_ID, ?string $createdAt = null): void
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
            'created_at' => $createdAt ?? (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'),
        ]);
    }

    private function service(): RefundService
    {
        // Only the session lookup is faked; the in-transaction session lock is real.
        $cash = $this->getMockBuilder(CashSessionService::class)
            ->setConstructorArgs([$this->connection, $this->createMock(TerminalService::class), new RequestStack()])
            ->onlyMethods(['requireOpenSession'])
            ->getMock();
        $cash->method('requireOpenSession')->willReturnCallback(fn (): array => ['session_id' => $this->nextSessionId, 'terminal_id' => $this->nextTerminalId, 'user_id' => 1]);

        return new RefundService(new OrderSearchService($this->connection), $this->refundClient(), $this->connection, $cash);
    }

    /**
     * Runs the outbox once, as the minute task does, with the same fake module.
     *
     * @return array{uploaded: int, retried: int, incidents: int, recovered: int}
     */
    private function upload(int $minutesLater = 0): array
    {
        $orders = $this->createMock(PsOrderClient::class);
        $orders->expects($this->never())->method('createReferencedOrder');
        $at = new \DateTimeImmutable(sprintf('+%d minutes', $minutesLater + 1));

        return (new OutboxSync($this->connection, $orders, null, fn (): \DateTimeImmutable => $at, $this->refundClient()))->run();
    }

    /** @return array<string, mixed> the outbox row of a refund */
    private function outboxOf(int $refundId): array
    {
        $requestId = $this->connection->fetchOne('SELECT request_id FROM refund WHERE id = ?', [$refundId]);
        $row = $this->connection->fetchAssociative('SELECT * FROM outbox WHERE request_id = ?', [$requestId]);
        $this->assertIsArray($row, 'the refund is queued');

        return $row;
    }

    private function refundClient(): PsRefundClient
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

        return $client;
    }

    /**
     * A refund stored by the TPV before refunds were local first: pending while its
     * PrestaShop outcome was unknown, with the module payload as it was sent.
     */
    private function seedLegacyPendingRefund(): int
    {
        $requestId = bin2hex(random_bytes(16));
        $this->connection->insert('refund', [
            'request_id' => $requestId, 'status' => 'pending', 'ps_order_id' => self::ORDER_ID, 'terminal_id' => 1,
            'session_id' => self::SESSION_ID, 'user_id' => 1, 'reason_code' => 'size', 'total' => 24.18,
            'cash_amount' => 24.18, 'card_amount' => 0, 'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'ps_payload' => json_encode([
                'ps_order_id' => self::ORDER_ID, 'request_id' => $requestId, 'refund_type' => 'partial', 'reason' => 'size',
                'lines' => [['id_product' => 3, 'id_product_attribute' => 7, 'quantity' => 1, 'amount' => 24.18, 'restock' => true]],
            ], JSON_THROW_ON_ERROR),
        ]);
        $refundId = (int) $this->connection->lastInsertId();
        $this->connection->insert('refund_line', [
            'refund_id' => $refundId, 'line_index' => 0, 'product_id' => 3, 'id_product_attribute' => 7, 'quantity' => 1, 'amount' => 24.18, 'restock' => 1,
        ]);

        return $refundId;
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

    public function testRestockZeroOrFalseStringMeansNoRestock(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);

        $this->service()->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => 0], ['line_index' => 1, 'quantity' => 1, 'restock' => 'false']], 'defective');
        $this->upload();

        $this->assertSame([false, false], array_column($this->psPayloads[0]['lines'], 'restock'));
        $this->assertSame(5, $this->stock(3, 7));
        $this->assertSame(5, $this->stock(3, 8));
    }

    public function testPartialCashRefundReturnsPaidShareAsPositiveRefundMovementAndRestocksCombination(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);

        $result = $this->service()->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size');

        $this->assertSame(24.18, $result['refunded_amount']);
        $this->assertSame(24.18, $result['cash_amount']);
        $this->assertSame(0.0, $result['card_amount']);
        $this->assertFalse($result['saga_error']);
        $this->assertTrue($result['queued']);
        $this->assertSame(0, $result['order_slip_id'], 'no slip until it is uploaded');
        $this->assertSame([], $this->psPayloads, 'PrestaShop is not called during the request');
        $this->assertEquals([['type' => 'refund', 'amount' => '24.18', 'ps_order_id' => self::ORDER_ID, 'ps_order_slip_id' => null]], $this->movements());
        $this->assertSame(6, $this->stock(3, 7), 'back in the local stock at once');

        $this->assertSame(['uploaded' => 1, 'retried' => 0, 'incidents' => 0, 'recovered' => 0], $this->upload());
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
        $this->assertSame(701, (int) $this->connection->fetchOne('SELECT ps_order_slip_id FROM refund WHERE id = ?', [$result['refund_id']]));
    }

    public function testTheCreditNoteGetsItsChainedFiscalRecordInTheSameTransaction(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);
        $head = $this->connection->fetchOne('SELECT last_hash FROM fiscal_chain WHERE id = 1');

        $result = $this->service()->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size');

        $record = $this->connection->fetchAssociative('SELECT * FROM fiscal_record WHERE refund_id = ?', [$result['refund_id']]);
        $this->assertIsArray($record);
        $this->assertSame('R5', $record['invoice_type']);
        $this->assertSame($this->connection->fetchOne('SELECT credit_note_number FROM refund WHERE id = ?', [$result['refund_id']]), $record['number']);
        $this->assertSame('-24.18', $record['total']);
        $this->assertSame($head !== false && $head !== null ? $head : null, $record['previous_hash']);
        $this->assertSame($record['hash'], $this->connection->fetchOne('SELECT last_hash FROM fiscal_chain WHERE id = 1'));
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
        $this->upload();
        $this->assertCount(2, $this->psPayloads);
        $this->assertSame(11, $this->stock(4, 0));
    }

    public function testFullRefundReturnsEverythingStillRefundable(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);
        $service = $this->service();
        $service->processRefund(1, self::ORDER_ID, [['line_index' => 1, 'quantity' => 1, 'restock' => true]], 'size');

        $result = $service->processRefund(1, self::ORDER_ID, null, 'size');
        $this->upload();

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
        $this->upload();
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

    public function testRejectionByPrestaShopBecomesAnIncidentAndLeavesTheLocalRefundAsItIs(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);
        $result = $this->service()->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size');
        $this->psFailures = [RefundException::validationFailed('PS validation failed')];

        $this->assertSame(['uploaded' => 0, 'retried' => 0, 'incidents' => 1, 'recovered' => 0], $this->upload());

        // PRD S4: the administrator sees it; the refund, cash and stock stay as booked.
        $row = $this->outboxOf($result['refund_id']);
        $this->assertSame('incident', $row['status']);
        $this->assertStringContainsString('PS validation failed', (string) $row['last_error']);
        $this->assertSame('done', $this->connection->fetchOne('SELECT status FROM refund'));
        $this->assertSame(['24.18'], array_column($this->movements(), 'amount'));
        $this->assertSame(6, $this->stock(3, 7));
    }

    public function testRefundWithPrestaShopDownIsPrintedAndPaidAtOnceAndUploadedLaterWithTheSameRequestId(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);
        $service = $this->service();
        $line = [['line_index' => 0, 'quantity' => 1, 'restock' => true]];

        $result = $service->processRefund(1, self::ORDER_ID, $line, 'size');

        $year = (new \DateTimeImmutable())->format('Y');
        $this->assertSame('R' . $year . '-000001', $result['credit_note_number'], 'numbered at once');
        $this->assertSame('done', $this->connection->fetchOne('SELECT status FROM refund'));
        $this->assertSame([], $this->psPayloads);
        $this->assertNotNull((new TicketDataResolver($this->connection, $this->createMock(TicketVerificationService::class), 'T', 'C'))->resolveRefund($result['refund_id']), 'the credit note prints');

        $this->psFailures = [RefundException::psUnavailable('timeout')];
        $this->assertSame(['uploaded' => 0, 'retried' => 1, 'incidents' => 0, 'recovered' => 0], $this->upload());
        $this->assertSame('pending', $this->outboxOf($result['refund_id'])['status']);
        // The next refund of the sale is not blocked: it queues behind the first one.
        $second = $service->processRefund(1, self::ORDER_ID, [['line_index' => 1, 'quantity' => 1, 'restock' => true]], 'size');
        $this->assertSame(2, (int) $this->outboxOf($second['refund_id'])['sequence']);

        $this->upload(5);

        $this->assertCount(3, $this->psPayloads);
        $this->assertSame($this->psPayloads[0]['request_id'], $this->psPayloads[1]['request_id'], 'the module replays it by request_id');
        $this->assertSame((string) $this->connection->fetchOne('SELECT request_id FROM refund WHERE id = ?', [$result['refund_id']]), $this->psPayloads[0]['request_id']);
        $this->assertSame(['done', 'done'], [$this->outboxOf($result['refund_id'])['status'], $this->outboxOf($second['refund_id'])['status']]);
        $this->assertSame(['24.18', '17.91'], array_column($this->movements(), 'amount'), 'cash moved once per refund');
    }

    public function testLegacyPendingRefundIsStillRetriedWithItsRequestIdAndBlocksNewRefunds(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);
        $service = $this->service();
        $refundId = $this->seedLegacyPendingRefund();

        $this->expectRefundError(409, fn () => $service->processRefund(1, self::ORDER_ID, [['line_index' => 1, 'quantity' => 1, 'restock' => true]], 'size'));
        $order = (new OrderSearchService($this->connection))->getOrder(self::ORDER_ID);
        $this->assertSame(['id' => $refundId, 'total' => 24.18], $order['pending_refund']);

        $result = $service->retryPending(1, $refundId);

        $this->assertCount(1, $this->psPayloads);
        $this->assertSame((string) $this->connection->fetchOne('SELECT request_id FROM refund WHERE id = ?', [$refundId]), $this->psPayloads[0]['request_id']);
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
        $refundId = $this->seedLegacyPendingRefund();

        // A second terminal retries while the first retry waits for PrestaShop.
        $this->duringPsCall = fn () => $service->retryPending(1, $refundId);
        $service->retryPending(1, $refundId);

        $this->assertSame(['24.18'], array_column($this->movements(), 'amount'));
        $this->assertSame(6, $this->stock(3, 7));
        $this->assertSame('24.18', (string) $this->connection->fetchOne('SELECT refunded_amount FROM order_meta WHERE ps_order_id = ?', [self::ORDER_ID]));
    }

    public function testBusyShopRetriesTheUploadInsteadOfMakingItAnIncident(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);
        $result = $this->service()->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size');
        $this->psFailures = [RefundException::conflict('Another refund for this order is in progress in PrestaShop')];

        $this->assertSame(1, $this->upload()['retried']);

        $this->assertSame('pending', $this->outboxOf($result['refund_id'])['status']);
        $this->assertSame('done', $this->connection->fetchOne('SELECT status FROM refund'));
    }

    public function testRetryRecordsCashInTheCurrentSession(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);
        $refundId = $this->seedLegacyPendingRefund();

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

    public function testShopRefundFinishingAfterCloseBooksNothingInTheClosedSession(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);
        $refundId = $this->seedLegacyPendingRefund();
        $service = $this->service();
        $c = $this->connection;
        // The drawer is closed while PrestaShop is still creating the credit slip.
        $this->duringPsCall = fn () => $c->update('session', ['closed_at' => '2026-11-03 20:00:00', 'expected_cash' => 100], ['id' => self::SESSION_ID]);

        $result = $service->retryPending(1, $refundId);

        $this->assertTrue($result['saga_error']);
        $this->assertSame([], $this->movements(), 'nothing is booked in the closed session');
        $this->assertSame('pending', $c->fetchOne('SELECT status FROM refund WHERE id = ?', [$refundId]));
        $this->assertNull($c->fetchOne('SELECT credit_note_number FROM refund WHERE id = ?', [$refundId]));
        $this->assertSame(5, $this->stock(3, 7), 'no local effect was applied');
        $orphan = $c->fetchOne("SELECT payload FROM audit_log WHERE action = 'refund_orphan' AND target = ? ORDER BY id DESC LIMIT 1", [(string) self::ORDER_ID]);
        $this->assertIsString($orphan, 'the credit slip already in the shop is recorded for reconciliation');
        $this->assertSame($refundId, json_decode($orphan, true)['refund_id']);

        // Next session: the retry replays the slip and books the cash there.
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->insert('session', ['id' => self::SESSION_ID + 1, 'terminal_id' => 1, 'user_id' => 1, 'opened_at' => '2026-11-04 09:00:00', 'opening_cash' => 50]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $this->nextSessionId = self::SESSION_ID + 1;
        try {
            $retried = $service->retryPending(1, $refundId);

            $this->assertFalse($retried['saga_error']);
            $this->assertSame('done', $c->fetchOne('SELECT status FROM refund WHERE id = ?', [$refundId]));
            $this->assertSame(self::SESSION_ID + 1, (int) $c->fetchOne('SELECT session_id FROM refund WHERE id = ?', [$refundId]));
            $this->assertEquals(
                [['session_id' => (string) (self::SESSION_ID + 1), 'amount' => '24.18']],
                $c->fetchAllAssociative("SELECT session_id, amount FROM movement WHERE type = 'refund' AND ps_order_id = ?", [self::ORDER_ID])
            );
            $this->assertSame('100.00', (string) $c->fetchOne('SELECT expected_cash FROM session WHERE id = ?', [self::SESSION_ID]));
        } finally {
            $c->executeStatement('DELETE FROM movement WHERE session_id = ?', [self::SESSION_ID + 1]);
            $c->executeStatement('DELETE FROM session WHERE id = ?', [self::SESSION_ID + 1]);
            $c->executeStatement("DELETE FROM audit_log WHERE action = 'refund_orphan' AND target = ?", [(string) self::ORDER_ID]);
        }
    }

    public function testCompletedRefundsGetConsecutiveCreditNoteNumbersAndRejectedOnesNone(): void
    {
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0]);
        $service = $this->service();
        $year = (new \DateTimeImmutable())->format('Y');

        // Rejected before anything is booked: no number taken.
        $this->expectRefundError(422, fn () => $service->processRefund(1, self::ORDER_ID, [['line_index' => 1, 'quantity' => 9, 'restock' => true]], 'size'));

        $first = $service->processRefund(1, self::ORDER_ID, [['line_index' => 1, 'quantity' => 1, 'restock' => true]], 'size');
        $second = $service->processRefund(1, self::ORDER_ID, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size');

        $this->assertSame('R' . $year . '-000001', $first['credit_note_number']);
        $this->assertSame('R' . $year . '-000002', $second['credit_note_number']);
        $this->assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM refund'));
    }

    public function testCreditNoteTicketDataRectifiesTheSaleWithRefundedLinesSplitAndProportionalTax(): void
    {
        $this->seedOrder(['cash' => 25.0, 'card' => 35.0]);
        $refund = $this->service()->processRefund(1, self::ORDER_ID, [['line_index' => 1, 'quantity' => 1, 'restock' => true]], 'other', 'Llegó descosido', true);
        $verification = $this->createMock(TicketVerificationService::class);
        $verification->method('generateToken')->willReturn('tok');
        $verification->method('buildVerificationUrl')->willReturn('http://tpv/verify?token=tok');

        $ticket = (new TicketDataResolver($this->connection, $verification, 'Tienda Test', 'Calle 1'))->resolveRefund($refund['refund_id']);

        $this->assertNotNull($ticket);
        $note = $ticket->creditNote;
        $this->assertNotNull($note);
        $this->assertSame($refund['credit_note_number'], $note->number);
        $this->assertSame('REF' . self::ORDER_ID, $note->rectifiesReference);
        $this->assertSame('Otro: Llegó descosido', $note->reason);
        $this->assertSame(17.91, $ticket->total);
        $this->assertSame(7.46, $note->cashAmount);
        $this->assertSame(10.45, $note->cardAmount);
        $this->assertNull($note->exchangeCredit, 'an ordinary refund is not applied to an exchange');
        $this->assertCount(1, $ticket->lines);
        $this->assertSame('Calcetines', $ticket->lines[0]->name);
        $this->assertSame(1, $ticket->lines[0]->quantity);
        $this->assertSame(17.91, $ticket->lines[0]->lineTotal);
        $this->assertSame($refund['credit_note_number'], $ticket->reference);
    }

    public function testRefundsOutsideTheTerminalWindowAreBlocked(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $this->connection->insert('terminal', ['id' => 9101, 'ps_shop_id' => 1, 'name' => 'Test', 'refund_window_days' => 10]);
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $this->nextTerminalId = 9101;
        $line = [['line_index' => 0, 'quantity' => 1, 'restock' => true]];

        $this->seedOrder(['cash' => 60.0, 'card' => 0.0], true, self::ORDER_ID, (new \DateTimeImmutable('-11 days'))->format('Y-m-d H:i:s'));
        $this->expectRefundError(422, fn () => $this->service()->processRefund(1, self::ORDER_ID, $line, 'size'));
        $this->assertSame([], $this->psPayloads);
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM refund'));

        $this->seedOrder(['cash' => 60.0, 'card' => 0.0], true, self::ORDER_ID + 1, (new \DateTimeImmutable('-9 days'))->format('Y-m-d H:i:s'));
        $result = $this->service()->processRefund(1, self::ORDER_ID + 1, $line, 'size');
        $this->assertSame(24.18, $result['refunded_amount']);
    }

    public function testDefaultWindowIsThirtyDaysWhenTheTerminalHasNoSetting(): void
    {
        $line = [['line_index' => 0, 'quantity' => 1, 'restock' => true]];
        $this->seedOrder(['cash' => 60.0, 'card' => 0.0], true, self::ORDER_ID, (new \DateTimeImmutable('-31 days'))->format('Y-m-d H:i:s'));

        $this->expectRefundError(422, fn () => $this->service()->processRefund(1, self::ORDER_ID, $line, 'size'));

        $order = (new OrderSearchService($this->connection))->getOrder(self::ORDER_ID);
        $this->assertArrayHasKey('created_at', $order);
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
