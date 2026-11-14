<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalShopSync;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * The shop copy under several uploaders at once (fiscal-journal D6): the seq gate
 * puts every worker on the same journal row, which is where MySQL deadlocks show up.
 * Each line must reach the shop once and in seq order, no worker may die of a
 * deadlock, and no line may be sent twice or wait out a backoff because recording
 * the answer deadlocked.
 */
final class FiscalJournalShopCopyConcurrencyMySqlIntegrationTest extends TestCase
{
    private const LINES = 40;
    private const WORKERS = 5;
    private const SECONDS = 50;

    private ?Connection $connection = null;
    private JournalPathFixtures $fixtures;
    private JournalHarness $journal;
    private string $store = '';

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->fixtures = new JournalPathFixtures($this->connection, fn (string $class) => $this->createMock($class));
        $this->fixtures->reset();
        $this->fixtures->install();
        $this->connection->executeStatement('DELETE FROM outbox');
        $this->journal = new JournalHarness($this->connection);
        $this->journal->writer->createJournal();
        $this->store = sys_get_temp_dir() . '/venpos-concurrent-shop-' . bin2hex(random_bytes(5)) . '.json';
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->connection->executeStatement('DELETE FROM outbox');
            $this->fixtures->reset();
            $this->connection->close();
            $this->journal->remove();
            foreach (glob($this->store . '*') ?: [] as $file) {
                @unlink($file);
            }
        }
        $this->connection = null;
    }

    public function testConcurrentOutboxRunsStoreEveryLineOnceInSeqOrderWithoutDeadlockFailures(): void
    {
        for ($i = 0; $i < self::LINES; ++$i) {
            JournalIssue::ticket($this->connection, $this->journal->intents, JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION);
        }
        $this->journal->writer->flushAfterIssue();
        // Queue the lines with the shop down, so the workers find them all waiting.
        $down = new FakeJournalShop($this->store . '.unused');
        $down->mode = FakeJournalShop::DOWN;
        (new FiscalJournalShopSync($this->connection, $this->journal->anotherProcess()->locations, $down->client()))->run((new \DateTimeImmutable())->modify('-1 minute'));
        $this->connection->executeStatement("UPDATE outbox SET next_attempt_at = NOW() - INTERVAL 1 MINUTE WHERE type = 'journal'");
        $this->assertSame(self::LINES, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM outbox WHERE type = 'journal' AND status = 'pending'"));

        $log = $this->store . '.log';
        $workers = [];
        foreach (range(1, self::WORKERS) as $worker) {
            $process = proc_open(
                [\PHP_BINARY, '-d', 'variables_order=EGPCS', __DIR__ . '/fixtures/outbox_journal_worker.php', $this->store, $log, (string) self::SECONDS],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', $this->store . '.err' . $worker, 'w']],
                $pipes
            );
            $this->assertIsResource($process);
            $workers[] = $process;
        }
        $exitCodes = array_map(static fn ($process): int => proc_close($process), $workers);

        $errors = implode('', array_map(static fn (string $file): string => (string) file_get_contents($file), glob($this->store . '.err*') ?: []));
        $this->assertSame(array_fill(0, self::WORKERS, 0), $exitCodes, $errors);
        $this->assertStringNotContainsString('UNCAUGHT', $errors);
        $this->assertStringNotContainsString('Deadlock', $errors);

        $requests = array_map(static fn (string $line): array => explode(' ', $line), file($log, FILE_IGNORE_NEW_LINES) ?: []);
        $stored = array_values(array_map(static fn (array $r): int => (int) $r[1], array_filter($requests, static fn (array $r): bool => $r[2] === '201')));
        $other = array_values(array_filter($requests, static fn (array $r): bool => $r[2] !== '201'));
        $this->assertSame(range(1, self::LINES), $stored, 'each line stored once, in seq order');
        $this->assertSame([], $other, 'no line sent twice (no duplicate after a deadlock recording the answer)');

        $rows = $this->connection->fetchAllAssociative("SELECT sequence, status, attempts, last_error FROM outbox WHERE type = 'journal' ORDER BY sequence");
        $this->assertSame(['done'], array_values(array_unique(array_column($rows, 'status'))));
        $this->assertSame([], array_values(array_filter(array_column($rows, 'last_error'))));
        $this->assertSame(['1'], array_values(array_unique(array_map('strval', array_column($rows, 'attempts')))), 'every line sent at its first attempt');
    }
}
