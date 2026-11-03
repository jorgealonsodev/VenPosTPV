<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Sync;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\PsRefundClient;
use VenPosTPV\Service\Sync\Outbox;
use VenPosTPV\Service\Sync\OutboxSync;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * PRD S1-S5: uploads in order per sale, the ticket code as reference, growing waits
 * for temporary errors, incidents for definitive rejections, one uploader per row.
 */
final class OutboxSyncMySqlIntegrationTest extends TestCase
{
    private const SESSION_ID = 9403;
    private const PRODUCT = 940301;

    private ?Connection $connection = null;

    private \DateTimeImmutable $now;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @var list<\Throwable|array<string, mixed>> what the module answers, in order */
    private array $answers = [];

    /** @var list<array<string, mixed>> refund requests the module received */
    private array $refunds = [];

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();
        // Rows are queued at the real time; the sync clock starts just after it.
        $this->now = new \DateTimeImmutable(date('Y-m-d H:i:s', time() + 60));
        $this->connection->insert('catalog_stock', ['id_product' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 4]);
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
        foreach ($c->fetchFirstColumn('SELECT id FROM order_meta WHERE session_id = ?', [self::SESSION_ID]) as $id) {
            $c->executeStatement('DELETE FROM outbox WHERE order_id = ?', [(int) $id]);
        }
        $c->executeStatement('DELETE FROM movement WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM order_meta WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM catalog_stock WHERE id_product = ?', [self::PRODUCT]);
    }

    /** A local sale as CheckoutService stores it: order_meta, cash movement and outbox row. */
    private function seedSale(string $reference): int
    {
        $c = $this->connection;

        return $c->transactional(function () use ($c, $reference): int {
            $c->insert('order_meta', [
                'reference' => $reference, 'terminal_id' => 1, 'session_id' => self::SESSION_ID, 'user_id' => 1, 'synced' => 0,
                'payment_breakdown' => json_encode(['reference' => $reference, 'total' => 10.0, 'lines' => []], JSON_THROW_ON_ERROR),
            ]);
            $id = (int) $c->lastInsertId();
            $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            $c->insert('movement', ['session_id' => self::SESSION_ID, 'type' => 'sale', 'amount' => 10, 'order_id' => $id]);
            $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            (new Outbox($c))->enqueueSale($id, ['products' => [['id_product' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 1, 'price' => 10.0]], 'reference' => $reference]);

            return $id;
        });
    }

    private function sync(?Connection $connection = null): OutboxSync
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createOrder');
        $client->method('createReferencedOrder')->willReturnCallback(function (array $payload): array {
            $this->sent[] = $payload;
            $answer = array_shift($this->answers) ?? $this->created(5000 + \count($this->sent), (string) $payload['reference']);
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        });

        $refundClient = $this->createMock(PsRefundClient::class);
        $refundClient->method('createRefund')->willReturnCallback(function (array $payload): array {
            $this->refunds[] = $payload;
            $answer = array_shift($this->answers);
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return ['order_slip_id' => 8800 + \count($this->refunds), 'refunded_amount' => 10.0, 'refunded_lines' => [], 'restock_failed' => [], 'replayed' => false];
        });

        return new OutboxSync($connection ?? $this->connection, $client, null, fn (): \DateTimeImmutable => $this->now, $refundClient);
    }

    /** @return array{order_id: int, reference: string, total_paid: float, replayed: bool} */
    private function created(int $orderId, string $reference, bool $replayed = false): array
    {
        return ['order_id' => $orderId, 'reference' => $reference, 'total_paid' => 10.0, 'replayed' => $replayed];
    }

    /** @return array<string, mixed> */
    private function outbox(int $orderId, int $sequence = 1): array
    {
        return $this->connection->fetchAssociative('SELECT * FROM outbox WHERE order_id = ? AND sequence = ?', [$orderId, $sequence]);
    }

    /** @return array<string, mixed> */
    private function sale(int $orderId): array
    {
        return $this->connection->fetchAssociative('SELECT ps_order_id, ps_reference, synced FROM order_meta WHERE id = ?', [$orderId]);
    }

    private function movementOrder(int $orderId): mixed
    {
        return $this->connection->fetchOne('SELECT ps_order_id FROM movement WHERE order_id = ?', [$orderId]);
    }

    private function stock(): int
    {
        return (int) $this->connection->fetchOne('SELECT quantity FROM catalog_stock WHERE id_product = ?', [self::PRODUCT]);
    }

    public function testUploadStoresThePrestaShopOrderAndMarksTheSaleSyncedWithoutPushingStock(): void
    {
        $id = $this->seedSale('Q26000101');
        $this->answers = [$this->created(7101, 'Q26000101')];

        $counts = $this->sync()->run();

        $this->assertSame(['uploaded' => 1, 'retried' => 0, 'incidents' => 0, 'recovered' => 0], $counts);
        $this->assertSame('Q26000101', $this->sent[0]['reference']);
        $this->assertEquals(['ps_order_id' => 7101, 'ps_reference' => null, 'synced' => 1], $this->sale($id));
        $this->assertEquals(7101, $this->movementOrder($id), 'the cash movement learns its PrestaShop order');
        $row = $this->outbox($id);
        $this->assertSame('done', $row['status']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertSame($this->now->format('Y-m-d H:i:s'), $row['done_at']);
        $this->assertNull($row['claim_token']);
        $this->assertNull($row['last_error']);
        $this->assertSame(4, $this->stock(), 'PrestaShop lowers its own stock: nothing is pushed or changed');

        $this->assertSame(['uploaded' => 0, 'retried' => 0, 'incidents' => 0, 'recovered' => 0], $this->sync()->run());
        $this->assertCount(1, $this->sent, 'a done row is never sent again');
    }

    public function testReplayedOrderIsSuccessAndADifferentReferenceIsKept(): void
    {
        $replayed = $this->seedSale('Q26000102');
        $renamed = $this->seedSale('Q26000103');
        $this->answers = [$this->created(7102, 'Q26000102', true), $this->created(7103, 'WEBREFABC')];

        $this->assertSame(2, $this->sync()->run()['uploaded']);

        $this->assertEquals(['ps_order_id' => 7102, 'ps_reference' => null, 'synced' => 1], $this->sale($replayed));
        $this->assertEquals(['ps_order_id' => 7103, 'ps_reference' => 'WEBREFABC', 'synced' => 1], $this->sale($renamed), 'PRD S5: the union is never lost');
    }

    public function testTemporaryErrorsRetryWithGrowingWaitsAndNeverTouchTheSale(): void
    {
        $id = $this->seedSale('Q26000104');
        $errors = [
            CheckoutException::psUnavailable('Connection refused'),
            CheckoutException::psUnavailable('PS module returned HTTP 409: Another order with this reference is in progress'),
            CheckoutException::psUnavailable('PS module returned HTTP 503: down'),
            CheckoutException::psUnavailable('Idle timeout reached'),
            CheckoutException::psUnavailable('PS module returned HTTP 500: boom'),
            CheckoutException::psUnavailable('Connection refused'),
            CheckoutException::psUnavailable('Connection refused'),
        ];
        $this->answers = $errors;
        $start = $this->now;
        $waits = [];
        foreach ([1, 2, 5, 15, 30, 60, 60] as $attempt => $minutes) {
            $this->assertSame(1, $this->sync()->run()['retried'], 'attempt ' . ($attempt + 1));
            $row = $this->outbox($id);
            $this->assertSame('pending', $row['status']);
            $this->assertSame($attempt + 1, (int) $row['attempts']);
            $this->assertStringContainsString($errors[$attempt]->getMessage(), (string) $row['last_error']);
            $next = new \DateTimeImmutable($row['next_attempt_at']);
            $waits[] = (int) (($next->getTimestamp() - $this->now->getTimestamp()) / 60);

            // Not due yet: nothing is sent.
            $this->now = $next->modify('-1 second');
            $this->assertSame(['uploaded' => 0, 'retried' => 0, 'incidents' => 0, 'recovered' => 0], $this->sync()->run());
            $this->now = $next;
        }
        $this->assertSame([1, 2, 5, 15, 30, 60, 60], $waits, 'waits grow and stop at one hour');
        $this->assertCount(7, $this->sent);
        $this->assertEquals(['ps_order_id' => null, 'ps_reference' => null, 'synced' => 0], $this->sale($id));
        $this->assertNull($this->movementOrder($id));
        $this->assertGreaterThan($start, $this->now);

        $this->assertSame(1, $this->sync()->run()['uploaded'], 'it finally goes through');
        $this->assertSame(8, (int) $this->outbox($id)['attempts']);
    }

    public function testDefinitiveRejectionBecomesAnIncidentWithoutTouchingTheSaleOrTheCash(): void
    {
        foreach ([['Q26000105', 'PS validation failed: {"products":"Product 940301 not found"}'], ['Q26000106', 'PrestaShop rejected the order (HTTP 404): Not Found']] as [$reference, $message]) {
            $id = $this->seedSale($reference);
            $this->answers = [new CheckoutException($message, 422)];
            $cashBefore = $this->connection->fetchAllAssociative('SELECT * FROM movement WHERE session_id = ? ORDER BY id', [self::SESSION_ID]);

            $this->assertSame(['uploaded' => 0, 'retried' => 0, 'incidents' => 1, 'recovered' => 0], $this->sync()->run());

            $row = $this->outbox($id);
            $this->assertSame('incident', $row['status']);
            $this->assertSame($message, $row['last_error']);
            $this->assertNull($row['claim_token']);
            $this->assertEquals(['ps_order_id' => null, 'ps_reference' => null, 'synced' => 0], $this->sale($id));
            $this->assertSame($cashBefore, $this->connection->fetchAllAssociative('SELECT * FROM movement WHERE session_id = ? ORDER BY id', [self::SESSION_ID]));

            $this->now = $this->now->modify('+1 day');
            $this->assertSame(0, $this->sync()->run()['incidents'], 'an incident waits for the administrator');
        }
        $this->assertCount(2, $this->sent);
    }

    public function testARefundOfASaleNotUploadedYetIsSentAfterItWithThePrestaShopOrderIdOfTheSale(): void
    {
        $id = $this->seedSale('Q26000107');
        $refundPayload = ['request_id' => 'r1-refund-of-q107', 'refund_type' => 'partial', 'reason' => 'size',
            'lines' => [['id_product' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 1, 'amount' => 10.0, 'restock' => true]]];
        $this->connection->transactional(fn (): string => (new Outbox($this->connection))->enqueueAfter(Outbox::TYPE_REFUND, $id, $refundPayload, 'r1-refund-of-q107'));
        $this->assertSame(2, (int) $this->outbox($id, 2)['sequence']);
        $this->assertSame('r1-refund-of-q107', $this->outbox($id, 2)['request_id'], 'the refund keeps its own idempotency key');
        $this->answers = [CheckoutException::psUnavailable('Connection refused')];

        $this->sync()->run();

        $this->assertCount(1, $this->sent, 'only the sale is tried');
        $this->assertSame([], $this->refunds, 'the refund waits behind its sale');
        $this->assertSame(0, (int) $this->outbox($id, 2)['attempts']);
        $this->assertSame('pending', $this->outbox($id, 2)['status']);

        $this->now = $this->now->modify('+1 minute');
        $this->answers = [$this->created(7107, 'Q26000107')];
        $counts = $this->sync()->run();

        $this->assertSame(2, $counts['uploaded']);
        $this->assertSame('done', $this->outbox($id)['status']);
        $this->assertSame('done', $this->outbox($id, 2)['status']);
        $this->assertCount(1, $this->refunds);
        // PRD S2/D2: the sale's PrestaShop id is known only now, and is what the slip uses.
        $this->assertSame(['ps_order_id' => 7107] + $refundPayload, $this->refunds[0]);
    }

    /**
     * A row queued after its sale (or its till-created customer) was discarded can never
     * upload: it becomes an incident the administrator sees, instead of waiting forever.
     */
    public function testARowBehindADiscardedSaleBecomesAnIncidentInsteadOfWaitingForever(): void
    {
        $id = $this->seedSale('Q26000120');
        $this->connection->executeStatement("UPDATE outbox SET status = 'discarded', last_error = 'Product not found' WHERE order_id = ? AND sequence = 1", [$id]);
        $refundPayload = ['request_id' => 'r1-refund-of-q120', 'refund_type' => 'partial', 'reason' => '',
            'lines' => [['id_product' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 1, 'amount' => 10.0, 'restock' => true]]];
        $this->connection->transactional(fn (): string => (new Outbox($this->connection))->enqueueAfter(Outbox::TYPE_REFUND, $id, $refundPayload, 'r1-refund-of-q120'));

        $counts = $this->sync()->run();

        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->refunds, 'nothing is sent for a sale PrestaShop never got');
        $this->assertSame(1, $counts['incidents']);
        $refund = $this->outbox($id, 2);
        $this->assertSame('incident', $refund['status']);
        $this->assertSame('Depende de una operación descartada', $refund['last_error']);
        $this->assertSame('discarded', $this->outbox($id)['status'], 'the discarded row is left as it is');
        $this->assertSame(0, $this->sync()->run()['incidents'], 'counted once');
    }

    public function testASaleThatDependsOnADiscardedCustomerBecomesAnIncident(): void
    {
        $customerRow = $this->connection->transactional(fn (): int => (new Outbox($this->connection))->enqueueCustomer(0, ['firstname' => 'Ana', 'lastname' => 'Ruiz', 'email' => 'ana-q121@example.com']));
        $this->connection->executeStatement("UPDATE outbox SET status = 'discarded' WHERE id = ?", [$customerRow]);
        $id = $this->seedSale('Q26000121');
        $this->connection->executeStatement('UPDATE outbox SET depends_on = ? WHERE order_id = ? AND sequence = 1', [$customerRow, $id]);
        $other = $this->seedSale('Q26000122');

        try {
            $this->sync()->run();

            $this->assertSame('incident', $this->outbox($id)['status']);
            $this->assertSame('Depende de una operación descartada', $this->outbox($id)['last_error']);
            $this->assertSame('done', $this->outbox($other)['status'], 'other sales upload as before');
            $this->assertSame(['Q26000122'], array_column($this->sent, 'reference'));
        } finally {
            $this->connection->executeStatement('DELETE FROM outbox WHERE id = ?', [$customerRow]);
        }
    }

    public function testARefundRejectedByPrestaShopBecomesAnIncidentAndABusyOrderIsRetried(): void
    {
        $id = $this->seedSale('Q26000109');
        $this->connection->executeStatement('UPDATE order_meta SET ps_order_id = 7109, synced = 1 WHERE id = ?', [$id]);
        $this->connection->executeStatement("UPDATE outbox SET status = 'done' WHERE order_id = ?", [$id]);
        foreach (['r-busy-0109', 'r-bad-0109'] as $requestId) {
            $this->connection->transactional(fn (): string => (new Outbox($this->connection))->enqueueAfter(Outbox::TYPE_REFUND, $id, ['request_id' => $requestId, 'lines' => []], $requestId));
        }
        $this->answers = [RefundException::conflict('Another refund for this order is in progress')];

        $this->assertSame(1, $this->sync()->run()['retried']);
        $this->assertSame('pending', $this->outbox($id, 2)['status']);
        $this->assertSame(0, (int) $this->outbox($id, 3)['attempts'], 'the next refund waits for the busy one');

        $this->now = $this->now->modify('+2 minutes');
        $this->answers = [['order_slip_id' => 1, 'refunded_amount' => 0.0, 'refunded_lines' => [], 'restock_failed' => [], 'replayed' => true], RefundException::validationFailed('PS validation failed')];
        $counts = $this->sync()->run();

        $this->assertSame(['uploaded' => 1, 'retried' => 0, 'incidents' => 1, 'recovered' => 0], $counts);
        $this->assertSame('incident', $this->outbox($id, 3)['status']);
        $this->assertSame([7109, 7109, 7109], array_column($this->refunds, 'ps_order_id'));
    }

    public function testAWorkerNeverUploadsARowAnotherWorkerHolds(): void
    {
        $id = $this->seedSale('Q26000108');
        $second = TestDatabase::connection();
        $this->assertNotNull($second);
        $nested = null;
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->once())->method('createReferencedOrder')->willReturnCallback(function (array $payload) use ($second, &$nested): array {
            // While this upload is in flight, the cron task runs on another connection.
            $nested = $this->sync($second)->run();

            return $this->created(7108, (string) $payload['reference']);
        });

        $counts = (new OutboxSync($this->connection, $client, null, fn (): \DateTimeImmutable => $this->now))->run();

        $this->assertSame(['uploaded' => 0, 'retried' => 0, 'incidents' => 0, 'recovered' => 0], $nested);
        $this->assertSame([], $this->sent);
        $this->assertSame(1, $counts['uploaded']);
        $this->assertSame('done', $this->outbox($id)['status']);
        $second->close();
    }

    public function testALockConflictWhileLinkingIsRetriedNeverAnIncident(): void
    {
        $id = $this->seedSale('Q26000111');
        $other = TestDatabase::connection();
        $this->assertNotNull($other);
        // Another transaction holds the sale row, so linking cannot get its lock.
        $other->beginTransaction();
        $other->fetchOne('SELECT id FROM order_meta WHERE id = ? FOR UPDATE', [$id]);
        $this->connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $counts = $this->sync()->run();
        } finally {
            $other->rollBack();
            $other->close();
            $this->connection->executeStatement('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }

        $this->assertSame(['uploaded' => 0, 'retried' => 1, 'incidents' => 0, 'recovered' => 0], $counts);
        $row = $this->outbox($id);
        $this->assertSame('pending', $row['status']);
        $this->assertStringStartsWith('Linking the PrestaShop order failed', (string) $row['last_error']);
        $this->assertEquals(['ps_order_id' => null, 'ps_reference' => null, 'synced' => 0], $this->sale($id));

        // The next attempt is replayed by the module and linked.
        $this->now = $this->now->modify('+1 minute');
        $this->answers = [$this->created(7111, 'Q26000111', true)];
        $this->assertSame(1, $this->sync()->run()['uploaded']);
        $this->assertEquals(['ps_order_id' => 7111, 'ps_reference' => null, 'synced' => 1], $this->sale($id));
    }

    public function testARowStuckInProcessingIsRecoveredAndUploadedOnce(): void
    {
        $id = $this->seedSale('Q26000109');
        // A worker died mid-upload six minutes ago.
        $this->connection->executeStatement(
            "UPDATE outbox SET status = 'processing', claim_token = 'dead', claimed_at = ?, attempts = 1 WHERE order_id = ?",
            [$this->now->modify('-6 minutes')->format('Y-m-d H:i:s'), $id]
        );
        $fresh = $this->seedSale('Q26000110');
        $this->connection->executeStatement(
            "UPDATE outbox SET status = 'processing', claim_token = 'alive', claimed_at = ?, attempts = 1 WHERE order_id = ?",
            [$this->now->modify('-1 minute')->format('Y-m-d H:i:s'), $fresh]
        );
        // The module replays the order the dead worker may have created.
        $this->answers = [$this->created(7109, 'Q26000109', true)];

        $counts = $this->sync()->run();

        $this->assertSame(['uploaded' => 1, 'retried' => 0, 'incidents' => 0, 'recovered' => 1], $counts);
        $this->assertSame(['Q26000109'], array_column($this->sent, 'reference'), 'a recent claim is left to its worker');
        $this->assertSame('done', $this->outbox($id)['status']);
        $this->assertSame(2, (int) $this->outbox($id)['attempts']);
        $this->assertSame('processing', $this->outbox($fresh)['status']);

        // The dead worker's late answer cannot overwrite the new claim's result.
        $this->assertSame(0, $this->connection->executeStatement("UPDATE outbox SET status = 'pending' WHERE order_id = ? AND claim_token = 'dead'", [$id]));
    }

    /**
     * Several cron processes at once (PRD S4 "un bloqueo"): every sale is uploaded
     * exactly once.
     */
    public function testConcurrentProcessesUploadEachSaleExactlyOnce(): void
    {
        $references = [];
        for ($i = 0; $i < 12; ++$i) {
            $references[] = sprintf('Q2600%04d', 200 + $i);
            $this->seedSale(end($references));
        }
        $log = tempnam(sys_get_temp_dir(), 'outbox-sync-');
        $workers = [];
        for ($w = 0; $w < 4; ++$w) {
            $workers[] = proc_open([PHP_BINARY, __DIR__ . '/fixtures/outbox_sync_worker.php', $log], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes) ?: self::fail('worker');
            $workers[$w] = [$workers[$w], $pipes];
        }
        foreach ($workers as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            $this->assertSame(0, proc_close($process), $out);
            $this->assertStringContainsString('"ok":true', $out);
        }
        $sent = array_values(array_filter(explode("\n", (string) file_get_contents($log))));
        unlink($log);
        sort($sent);

        $this->assertSame($references, $sent, 'each sale was sent exactly once');
        $rows = $this->connection->fetchAllAssociative(
            'SELECT o.status, o.attempts, o.last_error, m.synced FROM outbox o JOIN order_meta m ON m.id = o.order_id WHERE m.session_id = ? ORDER BY o.id',
            [self::SESSION_ID]
        );
        $this->assertSame(
            array_fill(0, 12, ['status' => 'done', 'attempts' => 1, 'last_error' => null, 'synced' => 1]),
            array_map(static fn (array $r): array => ['status' => $r['status'], 'attempts' => (int) $r['attempts'], 'last_error' => $r['last_error'], 'synced' => (int) $r['synced']], $rows)
        );
    }
}
