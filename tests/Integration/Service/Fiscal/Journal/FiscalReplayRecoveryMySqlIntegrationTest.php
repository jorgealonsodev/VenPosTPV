<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use VenPosTPV\Service\Cart\CartService;
use VenPosTPV\Service\Checkout\CheckoutService;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Exchange\ExchangeService;
use VenPosTPV\Service\Fiscal\FiscalChainVerifier;
use VenPosTPV\Service\Fiscal\FiscalRecorder;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalBehindException;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalFlushResult;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIntents;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalWriter;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplay;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplayResult;
use VenPosTPV\Service\PrestaShop\CouponCreationService;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Service\Sync\Outbox;
use VenPosTPV\Service\Sync\OutboxSyncTrigger;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * The replay on restored databases that changed after the backup or before the
 * replay (fiscal-journal S6, D8; findings of the T4 verification): rows that
 * existed at backup time in an older state are brought to the journal's state,
 * ids taken by unguarded actions are remapped for movements, uploads and sessions,
 * kills at any point leave nothing, a second replay waits its turn, a database
 * only ahead of the journal and a torn tail say what resolves them, and the replay
 * runs only while the guard blocks.
 */
final class FiscalReplayRecoveryMySqlIntegrationTest extends TestCase
{
    private const RESTORE_DB = 'venposjournal_replay_recovery';

    private const TABLES = [
        'fiscal_record' => 'id', 'fiscal_chain' => 'id', 'order_meta' => 'id', 'sale_line' => 'id', 'movement' => 'id',
        'transfer_payment' => 'id', 'issued_coupon' => 'id', 'refund' => 'id', 'refund_line' => 'id', 'exchange' => 'id',
        'invoice' => 'id', 'outbox' => 'id', 'session' => 'id',
        'ticket_sequence' => 'letter, year', 'credit_note_sequence' => 'year', 'invoice_sequence' => 'series, year',
    ];

    private const OUTBOX_TIMES = ['next_attempt_at', 'updated_at'];

    private ?Connection $connection = null;
    private ?Connection $restored = null;
    private JournalPathFixtures $fixtures;
    private JournalHarness $journal;
    private string $dump = '';
    private bool $userAdded = false;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        if (!JournalRestore::available()) {
            $this->markTestSkipped('MySQL test container unavailable for the restore');
        }
        $this->fixtures = new JournalPathFixtures($this->connection, fn (string $class) => $this->createMock($class));
        $this->removeOtherSessions();
        $this->fixtures->reset();
        $this->fixtures->install();
        if ($this->connection->fetchOne('SELECT 1 FROM user WHERE id = 1') === false) {
            $this->connection->insert('user', ['id' => 1, 'role_id' => (int) $this->connection->fetchOne('SELECT MIN(id) FROM role'), 'name' => 'Replay cashier', 'pin_hash' => 'x', 'active' => 1]);
            $this->userAdded = true;
        }
        $this->journal = new JournalHarness($this->connection);
        $this->journal->writer->createJournal();
        $this->fixtures->guard = new FiscalJournalGuard($this->connection, $this->journal->locations);
    }

    protected function tearDown(): void
    {
        $this->restored?->close();
        $this->restored = null;
        if ($this->connection !== null) {
            @chmod($this->journal->root . '/backups/fiscal-journal', 0700);
            JournalRestore::drop(self::RESTORE_DB);
            $this->removeOtherSessions();
            $this->fixtures->reset();
            if ($this->userAdded) {
                $this->connection->executeStatement('DELETE FROM user WHERE id = 1');
            }
            $this->connection->close();
            $this->journal->remove();
        }
        if ($this->dump !== '' && is_file($this->dump)) {
            unlink($this->dump);
        }
        $this->connection = null;
    }

    /** Sessions of the test till other than the fixture's (opened by these tests). */
    private function removeOtherSessions(): void
    {
        $c = $this->connection;
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->executeStatement('DELETE FROM movement WHERE session_id IN (SELECT id FROM session WHERE terminal_id = ? AND id <> ?)', [JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION]);
        $c->executeStatement('DELETE FROM order_meta WHERE session_id IN (SELECT id FROM session WHERE terminal_id = ? AND id <> ?)', [JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION]);
        $c->executeStatement('DELETE FROM session WHERE terminal_id = ? AND id <> ?', [JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return array<string, mixed> */
    private function sell(?JournalPathFixtures $fixtures = null, ?FiscalJournalIntents $intents = null): array
    {
        $fixtures ??= $this->fixtures;
        $total = JournalPathFixtures::PRICE;

        return $fixtures->checkout($fixtures->recorder($intents ?? $this->journal->intents))
            ->createOrder(1, $fixtures->cart() + ['cash_amount' => $total], 'cash', $total);
    }

    private function flush(): void
    {
        $this->assertSame(FiscalJournalFlushResult::OK, $this->journal->writer->flush()->status);
    }

    private function backup(): void
    {
        $this->dump = JournalRestore::dump($this->connection);
    }

    private function restore(): Connection
    {
        $this->restored?->close();

        return $this->restored = JournalRestore::restore($this->dump, self::RESTORE_DB);
    }

    private function replay(Connection $connection, ?FiscalJournalGuard $guard = null): FiscalReplay
    {
        return new FiscalReplay($connection, $this->journal->locations, $this->journal->root . '/var/fiscal-replay.lock', guard: $guard);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function tables(Connection $connection): array
    {
        $tables = [];
        foreach (self::TABLES as $table => $order) {
            $rows = $connection->fetchAllAssociative(sprintf('SELECT * FROM `%s` ORDER BY %s', $table, $order));
            if ($table === 'outbox') {
                $rows = array_map(static fn (array $row): array => array_diff_key($row, array_flip(self::OUTBOX_TIMES)), $rows);
            }
            $tables[$table] = $rows;
        }

        return $tables;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $expected
     * @param array<string, list<array<string, mixed>>> $actual
     */
    private function assertSameTables(array $expected, array $actual, string $why): void
    {
        foreach ($expected as $table => $rows) {
            $this->assertSame($rows, $actual[$table], $why . ': ' . $table);
        }
    }

    private function assertTillGoesOn(Connection $restored): void
    {
        $chain = (new FiscalChainVerifier($restored))->verify();
        $this->assertTrue($chain['ok'], (string) $chain['reason']);
        $this->assertSame(FiscalJournalGuard::OK, (new FiscalJournalGuard($restored, $this->journal->locations))->check()['state']);
    }

    /** A checkout whose first sale fails (a timeout), as in the exchange tests. */
    private function checkoutFailingOnce(): CheckoutService
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        return new class($this->createMock(CartService::class), $this->createMock(PsOrderClient::class), $this->connection, $this->fixtures->cash(), $bus, $this->createMock(PrinterService::class), false, null, new TicketNumberer($this->connection), new Outbox($this->connection), new OutboxSyncTrigger(), fiscalRecorder: $this->fixtures->recorder($this->journal->intents)) extends CheckoutService {
            public int $failures = 1;

            public function createOrder(int $userId, array $cartData, string $paymentMethod, float $amountTendered, float $exchangeCredit = 0.0, ?string $requestId = null): array
            {
                if ($this->failures-- > 0) {
                    throw new \RuntimeException('timeout');
                }

                return parent::createOrder($userId, $cartData, $paymentMethod, $amountTendered, $exchangeCredit, $requestId);
            }
        };
    }

    // B1: a row that existed at backup time in an older state.

    public function testARefundPendingAtTheBackupAndCompletedAfterItIsBroughtToItsJournalState(): void
    {
        $this->sell();
        $refundId = $this->fixtures->legacyPendingRefund(947160, 10.0);
        $this->flush();
        $this->backup();
        $this->fixtures->refunds($this->fixtures->recorder($this->journal->intents), $this->fixtures->slipClient(), $this->journal->intents)->retryPending(1, $refundId);
        $this->sell();
        $this->flush();
        $live = self::tables($this->connection);
        $restored = $this->restore();
        $this->assertSame('pending', $restored->fetchOne('SELECT status FROM refund WHERE id = ?', [$refundId]));

        $done = $this->replay($restored)->run(true);

        $this->assertSame(FiscalReplayResult::DONE, $done->status, (string) $done->message);
        $this->assertSameTables($live, self::tables($restored), 'the refund in its journal state');
        $this->assertTillGoesOn($restored);
        $this->assertSame(FiscalReplayResult::NOTHING, $this->replay($restored)->run(true)->status, 'idempotent');
    }

    public function testAnExchangeRetriedAfterTheBackupIsBroughtToItsJournalState(): void
    {
        $sale = $this->sell();
        $recorder = $this->fixtures->recorder($this->journal->intents);
        $refunds = $this->fixtures->refunds($recorder, null, $this->journal->intents);
        $first = (new ExchangeService($refunds, $this->checkoutFailingOnce(), $this->connection, $this->createMock(CouponCreationService::class), $this->fixtures->cash()))
            ->processExchange(1, 0, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size', '', $this->fixtures->cart(2), 'cash', 20.0, false, 0, $sale['order_id']);
        $this->assertSame('credit_open', $first['status']);
        $this->flush();
        $this->backup();
        $retried = (new ExchangeService($refunds, $this->fixtures->checkout($recorder), $this->connection, $this->createMock(CouponCreationService::class), $this->fixtures->cash()))
            ->retry(1, $first['exchange_id']);
        $this->assertSame('done', $retried['status']);
        $this->flush();
        $live = self::tables($this->connection);
        $restored = $this->restore();
        $this->assertSame('credit_open', $restored->fetchOne('SELECT status FROM exchange WHERE id = ?', [$first['exchange_id']]));

        $done = $this->replay($restored)->run(true);

        $this->assertSame(FiscalReplayResult::DONE, $done->status, (string) $done->message);
        $this->assertSameTables($live, self::tables($restored), 'the exchange in its journal state');
        $this->assertSame('done', $restored->fetchOne('SELECT status FROM exchange WHERE id = ?', [$first['exchange_id']]));
        $this->assertTillGoesOn($restored);
    }

    public function testARowWithTheSameIdAndAnotherIdentityStaysAConflict(): void
    {
        $this->sell();
        $refundId = $this->fixtures->legacyPendingRefund(947160, 10.0);
        $this->flush();
        $this->backup();
        $this->fixtures->refunds($this->fixtures->recorder($this->journal->intents), $this->fixtures->slipClient(), $this->journal->intents)->retryPending(1, $refundId);
        $this->flush();
        $restored = $this->restore();
        $restored->update('refund', ['request_id' => 'another-request'], ['id' => $refundId]);
        $before = self::tables($restored);

        $result = $this->replay($restored)->run(true);

        $this->assertSame(FiscalReplayResult::CONFLICT, $result->status, (string) $result->message);
        $this->assertSame(4, $result->exitCode());
        $this->assertStringContainsString(sprintf('refund id %d', $refundId), (string) $result->message);
        $this->assertSame($before, self::tables($restored));
    }

    // B2: an id taken by an unguarded action before the replay.

    public function testAManualCashMovementTakingTheIdOfAJournaledMovementIsRemapped(): void
    {
        $this->sell();
        $this->flush();
        $this->backup();
        $sale = $this->sell();
        $this->flush();
        $live = self::tables($this->connection);
        $journaled = $this->connection->fetchAssociative('SELECT * FROM movement WHERE order_id = ?', [$sale['order_id']]);
        $restored = $this->restore();
        // Manual movements are not refused while the database is behind.
        $restored->insert('movement', ['session_id' => JournalPathFixtures::SESSION, 'type' => 'cash_in', 'amount' => 5, 'concept' => 'Cambio', 'created_at' => date('Y-m-d H:i:s')]);
        $taken = (int) $restored->lastInsertId();
        $this->assertSame((int) $journaled['id'], $taken);
        $cashIn = $restored->fetchAssociative('SELECT * FROM movement WHERE id = ?', [$taken]);

        $dry = $this->replay($restored)->run(false);
        $this->assertSame(FiscalReplayResult::PENDING, $dry->status, (string) $dry->message);
        $this->assertCount(1, $dry->remaps);
        $done = $this->replay($restored)->run(true);

        $this->assertSame(FiscalReplayResult::DONE, $done->status, (string) $done->message);
        $this->assertSame([['table' => 'movement', 'from' => $taken, 'to' => $done->remaps[0]['to']]], $done->remaps);
        $this->assertGreaterThan($taken, $done->remaps[0]['to']);
        $this->assertSame($cashIn, $restored->fetchAssociative('SELECT * FROM movement WHERE id = ?', [$taken]), 'the manual movement is kept');
        $this->assertSame(array_diff_key($journaled, ['id' => 0]), array_diff_key($restored->fetchAssociative('SELECT * FROM movement WHERE id = ?', [$done->remaps[0]['to']]), ['id' => 0]), 'the journaled movement under a fresh id');
        $after = self::tables($restored);
        unset($live['movement'], $after['movement']);
        $this->assertSameTables($live, $after, 'everything else as journaled');
        $this->assertTillGoesOn($restored);
        $this->assertSame(FiscalReplayResult::NOTHING, $this->replay($restored)->run(true)->status, 'idempotent');
    }

    public function testASessionOpenedInTheRestoredDatabaseTakingTheIdOfAJournaledSessionIsRemapped(): void
    {
        $this->sell();
        $this->flush();
        $this->backup();
        // After the backup the drawer is closed and a new session opened, then a sale in it.
        $this->connection->executeStatement('UPDATE session SET closed_at = NOW(), counted_cash = 100 WHERE id = ?', [JournalPathFixtures::SESSION]);
        $this->connection->insert('session', ['terminal_id' => JournalPathFixtures::TERMINAL, 'user_id' => 1, 'opened_at' => '2026-11-14 12:00:00', 'opening_cash' => 50]);
        $newSession = (int) $this->connection->lastInsertId();
        $_SESSION['cash_session_id'] = $newSession;
        $sale = $this->sell();
        $this->flush();
        $restored = $this->restore();
        // On the restored database the drawer opened again before the replay: same id, another session.
        $restored->insert('session', ['terminal_id' => JournalPathFixtures::TERMINAL, 'user_id' => 1, 'opened_at' => '2026-11-14 13:00:00', 'opening_cash' => 7]);
        $this->assertSame($newSession, (int) $restored->lastInsertId());

        $done = $this->replay($restored)->run(true);

        $this->assertSame(FiscalReplayResult::DONE, $done->status, (string) $done->message);
        $this->assertCount(1, $done->remaps);
        $this->assertSame(['table' => 'session', 'from' => $newSession], array_diff_key($done->remaps[0], ['to' => 0]));
        $fresh = $done->remaps[0]['to'];
        $this->assertSame('7.00', $restored->fetchOne('SELECT opening_cash FROM session WHERE id = ?', [$newSession]), 'the session of the restored database is kept');
        $session = $restored->fetchAssociative('SELECT * FROM session WHERE id = ?', [$fresh]);
        $this->assertSame(['50.00', null], [$session['opening_cash'], $session['closed_at']], 'the journaled session, open');
        $this->assertSame($fresh, (int) $restored->fetchOne('SELECT session_id FROM order_meta WHERE id = ?', [$sale['order_id']]));
        $this->assertSame([$fresh], array_map('intval', $restored->fetchFirstColumn('SELECT DISTINCT session_id FROM sale_line WHERE order_id = ?', [$sale['order_id']])));
        $this->assertSame([$fresh], array_map('intval', $restored->fetchFirstColumn('SELECT DISTINCT session_id FROM movement WHERE order_id = ?', [$sale['order_id']])));
        $this->assertTillGoesOn($restored);
    }

    public function testASessionAlreadyThereWithTheSameIdentityIsKeptAsItIs(): void
    {
        $this->sell();
        $this->flush();
        $this->backup();
        $this->sell();
        $this->flush();
        $restored = $this->restore();
        // Closed on the restored database before the replay: the same session.
        $restored->executeStatement('UPDATE session SET closed_at = ?, counted_cash = 100 WHERE id = ?', ['2026-11-14 20:00:00', JournalPathFixtures::SESSION]);
        $session = $restored->fetchAssociative('SELECT * FROM session WHERE id = ?', [JournalPathFixtures::SESSION]);

        $done = $this->replay($restored)->run(true);

        $this->assertSame(FiscalReplayResult::DONE, $done->status, (string) $done->message);
        $this->assertSame([], $done->remaps);
        $this->assertSame($session, $restored->fetchAssociative('SELECT * FROM session WHERE id = ?', [JournalPathFixtures::SESSION]));
    }

    public function testAnUploadRowTakingTheIdOfAJournaledUploadIsRemapped(): void
    {
        $this->sell();
        $this->flush();
        $this->backup();
        $sale = $this->sell();
        $this->flush();
        $journaled = $this->connection->fetchAssociative("SELECT * FROM outbox WHERE type = 'sale' AND order_id = ?", [$sale['order_id']]);
        $restored = $this->restore();
        $restored->insert('outbox', ['type' => 'customer', 'sequence' => 0, 'request_id' => 'customer-' . bin2hex(random_bytes(4)), 'payload' => '{}']);
        $taken = (int) $restored->lastInsertId();
        $this->assertSame((int) $journaled['id'], $taken);

        $done = $this->replay($restored)->run(true);

        $this->assertSame(FiscalReplayResult::DONE, $done->status, (string) $done->message);
        $this->assertSame('outbox', $done->remaps[0]['table'] ?? null);
        $this->assertSame('customer', $restored->fetchOne('SELECT type FROM outbox WHERE id = ?', [$taken]));
        $requeued = $restored->fetchAssociative('SELECT * FROM outbox WHERE id = ?', [$done->remaps[0]['to']]);
        $this->assertSame([$journaled['request_id'], 'pending', (int) $sale['order_id']], [$requeued['request_id'], $requeued['status'], (int) $requeued['order_id']]);
        $this->assertTillGoesOn($restored);
    }

    public function testAFiscalIdTakenInTheRestoredDatabaseIsNeverRemapped(): void
    {
        $this->sell();
        $this->backup();
        $sale = $this->sell();
        $this->flush();
        $restored = $this->restore();
        $restored->insert('order_meta', ['id' => $sale['order_id'], 'reference' => 'Z99000001', 'terminal_id' => JournalPathFixtures::TERMINAL, 'session_id' => JournalPathFixtures::SESSION, 'user_id' => 1, 'payment_breakdown' => '{}', 'created_at' => '2026-11-14 10:00:00']);

        $result = $this->replay($restored)->run(true);

        $this->assertSame(FiscalReplayResult::CONFLICT, $result->status, (string) $result->message);
        $this->assertSame([], $result->remaps);
    }

    // The database only ahead of the journal, the guard precondition, a torn tail.

    public function testADatabaseOnlyAheadOfTheJournalSaysTheNextFlushResolvesIt(): void
    {
        $this->sell();
        $this->flush();
        $this->sell();
        $before = self::tables($this->connection);

        foreach ([false, true] as $apply) {
            $result = $this->replay($this->connection)->run($apply);

            $this->assertSame(FiscalReplayResult::AHEAD, $result->status, (string) $result->message);
            $this->assertSame(0, $result->exitCode());
            $this->assertStringContainsString('tpv:cron', (string) $result->message);
            $this->assertSame($before, self::tables($this->connection));
        }
    }

    public function testItRunsOnlyWhileTheGuardBlocks(): void
    {
        $this->sell();
        $this->backup();
        $this->sell();
        $this->flush();
        $restored = $this->restore();
        $before = self::tables($restored);
        $unblocked = new class($restored, $this->journal->locations) extends FiscalJournalGuard {
            public function check(): array
            {
                return ['state' => self::OK, 'missing' => null];
            }
        };

        $result = $this->replay($restored, $unblocked)->run(true);

        $this->assertSame(FiscalReplayResult::ERROR, $result->status, (string) $result->message);
        $this->assertStringContainsString('la caja no está bloqueada', (string) $result->message);
        $this->assertSame($before, self::tables($restored));
    }

    public function testATornTailIsRefusedUntilTheNextFlushRepairsIt(): void
    {
        $this->sell();
        $this->backup();
        $this->sell();
        $this->flush();
        $live = self::tables($this->connection);
        // A write interrupted half-way: a fragment without its newline, head.json not moved.
        $file = $this->journal->primary()->journalFiles()[0];
        file_put_contents($file, '{"v":1,"seq":3,"journaled_at":"2026-11-14T18', \FILE_APPEND);
        $restored = $this->restore();
        $before = self::tables($restored);

        $refused = $this->replay($restored)->run(true);

        $this->assertSame(FiscalReplayResult::ERROR, $refused->status, (string) $refused->message);
        $this->assertStringContainsString('a medio escribir', (string) $refused->message);
        $this->assertStringContainsString('tpv:cron', (string) $refused->message);
        $this->assertSame($before, self::tables($restored));

        // The next flush cuts it off (the database is still behind: it writes nothing more).
        $flushed = (new FiscalJournalWriter($restored, $this->journal->locations, new FiscalJournalIntents($this->journal->locations)))->flush();
        $this->assertSame(FiscalJournalFlushResult::HEAD_MISSING, $flushed->status);
        $done = $this->replay($restored)->run(true);

        $this->assertSame(FiscalReplayResult::DONE, $done->status, (string) $done->message);
        $this->assertSameTables($live, self::tables($restored), 'replayed after the repair');
    }

    // Kills, concurrency and a split journal (verification probes P2, P7).

    /** @return array{0: int, 1: string} */
    private function worker(string $mode, int $after, int $sleep = 0): array
    {
        $process = $this->spawn($mode, $after, $sleep, $pipes);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    /**
     * @param array<int, resource> $pipes
     *
     * @return resource
     */
    private function spawn(string $mode, int $after, int $sleep, ?array &$pipes)
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/replay_worker.php', $this->journal->root, self::RESTORE_DB, (string) $after, $mode, (string) $sleep], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);

        return $process;
    }

    public function testKillsAtAnyPointLeaveNothingAndASecondReplayWaitsItsTurn(): void
    {
        $this->sell();
        $this->flush();
        $this->backup();
        foreach (range(1, 5) as $ignored) {
            $this->sell();
        }
        $this->flush();
        $live = self::tables($this->connection);
        $restored = $this->restore();
        $before = self::tables($restored);
        foreach ([1, 3, 5] as $after) {
            [$status, $output] = $this->worker('kill', $after);
            $this->assertNotSame(0, $status, $output);
            $restored->close();
            $restored = $this->restored = JournalRestore::connect(self::RESTORE_DB);
            $this->assertSame($before, self::tables($restored), 'killed after document ' . $after);
        }

        // A replay paused inside its transaction; meanwhile a second replay and a sale.
        $marker = $this->journal->root . '/paused';
        $process = $this->spawn('pause', 2, 4, $pipes);
        $deadline = microtime(true) + 30;
        while (!is_file($marker) && microtime(true) < $deadline) {
            usleep(50_000);
        }
        $this->assertFileExists($marker);
        $second = $this->replay($restored)->run(true);
        $this->assertSame(FiscalReplayResult::ERROR, $second->status);
        $this->assertStringContainsString('Ya hay una recuperación de documentos en marcha', (string) $second->message);
        $fixtures = new JournalPathFixtures($restored, fn (string $class) => $this->createMock($class));
        $fixtures->guard = new FiscalJournalGuard($restored, $this->journal->locations);
        try {
            // Waits for the replay's locks; then either refused or numbered after it.
            $this->sell($fixtures, new FiscalJournalIntents($this->journal->locations));
        } catch (\Throwable $e) {
            $this->assertInstanceOf(FiscalJournalBehindException::class, $e instanceof FiscalJournalBehindException ? $e : $e->getPrevious(), $e->getMessage());
        }
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output);
        $this->assertStringContainsString('"status":"done"', $output);

        $restored->close();
        $restored = $this->restored = JournalRestore::connect(self::RESTORE_DB);
        $numbers = $restored->fetchFirstColumn('SELECT number FROM fiscal_record ORDER BY id');
        $this->assertSame(\count($numbers), \count(array_unique($numbers)), 'no number twice');
        $this->assertTrue((new FiscalChainVerifier($restored))->verify()['ok']);
        foreach (['fiscal_record', 'order_meta', 'sale_line'] as $table) {
            $this->assertSame($live[$table], \array_slice(self::tables($restored)[$table], 0, \count($live[$table])), $table . ': the journaled documents first');
        }
    }

    public function testAJournalSplitAcrossBothFoldersIsReplayedWhole(): void
    {
        $this->sell();
        $this->flush();
        $this->backup();
        $this->sell();
        $this->flush();
        // The primary cannot be written: the next documents go to the secondary.
        chmod($this->journal->root . '/backups/fiscal-journal', 0500);
        $this->sell();
        $this->sell();
        $this->flush();
        $this->assertCount(2, $this->journal->lines($this->journal->secondary()));
        $live = self::tables($this->connection);
        $restored = $this->restore();

        $done = $this->replay($restored)->run(true);

        $this->assertSame(FiscalReplayResult::DONE, $done->status, (string) $done->message);
        $this->assertCount(3, $done->documents);
        $this->assertSameTables($live, self::tables($restored), 'primary and secondary lines');
    }

    /** A partial restore either replays to the journal's state or writes nothing. */
    public function testAPartialRestoreNeverEndsHalfWay(): void
    {
        $this->sell();
        $this->flush();
        $this->backup();
        foreach (range(1, 4) as $ignored) {
            $this->sell();
        }
        $this->flush();
        $live = self::tables($this->connection);
        $ids = array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM fiscal_record ORDER BY id'));
        foreach (['fiscal_record', 'order_meta'] as $variant) {
            $restored = $this->restore();
            if ($variant === 'fiscal_record') {
                // Records newer than the chain head (a table restored on its own).
                foreach ($this->connection->fetchAllAssociative('SELECT * FROM fiscal_record WHERE id IN (?, ?) ORDER BY id', [$ids[1], $ids[2]]) as $row) {
                    $restored->insert('fiscal_record', $row);
                }
            } else {
                $orders = array_map('intval', $this->connection->fetchFirstColumn('SELECT order_id FROM fiscal_record WHERE id IN (?, ?)', [$ids[1], $ids[2]]));
                foreach (['order_meta' => 'id', 'sale_line' => 'order_id', 'movement' => 'order_id'] as $table => $column) {
                    foreach ($this->connection->fetchAllAssociative(sprintf('SELECT * FROM `%s` WHERE %s IN (?, ?)', $table, $column), $orders) as $row) {
                        $restored->insert($table, $row);
                    }
                }
            }
            $before = self::tables($restored);

            $result = $this->replay($restored)->run(true);

            if ($result->status === FiscalReplayResult::DONE) {
                $this->assertSameTables($live, self::tables($restored), $variant);
            } else {
                $this->assertSame($before, self::tables($restored), $variant . ': ' . $result->message);
            }
        }
    }
}
