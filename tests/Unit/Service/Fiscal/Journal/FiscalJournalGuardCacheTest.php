<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuardCache;

/**
 * The cheap form of the guard asked by every state-changing request and by the
 * every-minute task: a "not blocked" answer is reused for a few seconds while the
 * database's chain head stays the same (a restore changes it), and a blocking
 * answer is never reused, so recovering the documents unblocks at once.
 */
final class FiscalJournalGuardCacheTest extends TestCase
{
    private string $root;
    private Connection $connection;
    private \DateTimeImmutable $now;
    /** @var list<string> the states the guard will answer, in order */
    private array $answers = [];
    private int $checks = 0;

    protected function setUp(): void
    {
        $this->root = JournalLines::temporaryDirectory('guard-cache');
        mkdir($this->root . '/var', 0700, true);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->root . '/db.sqlite']);
        $this->connection->executeStatement('CREATE TABLE fiscal_chain (id INTEGER PRIMARY KEY, last_record_id INTEGER NULL, last_hash VARCHAR(64) NULL)');
        $this->connection->executeStatement("INSERT INTO fiscal_chain (id, last_record_id, last_hash) VALUES (1, 3, 'aaa')");
        $this->now = new \DateTimeImmutable('2026-11-14T18:00:00+01:00');
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        JournalLines::remove($this->root);
    }

    private function cache(?string $file = null): FiscalJournalGuardCache
    {
        $guard = $this->createMock(FiscalJournalGuard::class);
        $guard->method('check')->willReturnCallback(function (): array {
            ++$this->checks;
            $state = array_shift($this->answers) ?? FiscalJournalGuard::OK;

            return ['state' => $state, 'missing' => null];
        });

        return new FiscalJournalGuardCache($guard, $this->connection, $file ?? $this->root . '/var/fiscal-journal-guard.json', null, fn (): \DateTimeImmutable => $this->now);
    }

    private function later(int $seconds): void
    {
        $this->now = $this->now->modify('+' . $seconds . ' seconds');
    }

    public function testANotBlockedAnswerIsReusedWithinTheTtlAcrossProcesses(): void
    {
        $this->assertSame(FiscalJournalGuard::OK, $this->cache()->state());
        $this->later(FiscalJournalGuardCache::TTL_SECONDS - 1);
        $this->assertSame(FiscalJournalGuard::OK, $this->cache()->state(), 'another request (another process) reads the same answer');
        $this->assertFalse($this->cache()->blocks());

        $this->assertSame(1, $this->checks);
    }

    public function testTheAnswerIsCheckedAgainOnceTheTtlIsOver(): void
    {
        $this->cache()->state();
        $this->later(FiscalJournalGuardCache::TTL_SECONDS);
        $this->answers = [FiscalJournalGuard::BEHIND_JOURNAL];

        $this->assertSame(FiscalJournalGuard::BEHIND_JOURNAL, $this->cache()->state());
        $this->assertSame(2, $this->checks);
    }

    public function testARestoreThatChangesTheChainHeadIsSeenByTheNextRequest(): void
    {
        $this->cache()->state();
        $this->connection->executeStatement("UPDATE fiscal_chain SET last_hash = 'older', last_record_id = 2 WHERE id = 1");
        $this->answers = [FiscalJournalGuard::BEHIND_JOURNAL];

        $this->assertTrue($this->cache()->blocks(), 'no reuse of the answer given for another database');
        $this->assertSame(2, $this->checks);
    }

    public function testABlockingAnswerIsNeverReusedSoTheRecoveryUnblocksAtOnce(): void
    {
        foreach ([FiscalJournalGuard::BEHIND_JOURNAL, FiscalJournalGuard::BEHIND_SHOP, FiscalJournalGuard::JOURNAL_MISSING] as $state) {
            $this->answers = [$state];
            $this->assertTrue($this->cache()->blocks(), $state);
        }
        $this->assertSame(FiscalJournalGuard::OK, $this->cache()->state(), 'recovered: the next request is not blocked');
        $this->assertSame(4, $this->checks);
    }

    public function testABlockingAnswerReplacesAReusableOne(): void
    {
        $this->cache()->state();
        $this->later(FiscalJournalGuardCache::TTL_SECONDS);
        $this->answers = [FiscalJournalGuard::BEHIND_SHOP];
        $this->cache()->state();
        $this->later(1);

        $this->assertSame(FiscalJournalGuard::OK, $this->cache()->state(), 'checked again, not the old "ok"');
        $this->assertSame(3, $this->checks);
    }

    public function testAnAnswerFromTheFutureIsNotReused(): void
    {
        $this->cache()->state();
        $this->later(-60);
        $this->cache()->state();

        $this->assertSame(2, $this->checks);
    }

    public function testAFolderThatCannotHoldTheCacheOnlyCostsAFullCheckEachTime(): void
    {
        $file = $this->root . '/missing-folder/fiscal-journal-guard.json';
        $this->assertSame(FiscalJournalGuard::OK, $this->cache($file)->state());
        $this->assertSame(FiscalJournalGuard::OK, $this->cache($file)->state());

        $this->assertSame(2, $this->checks);
        $this->assertFileDoesNotExist($file);
    }

    public function testADamagedCacheFileIsIgnored(): void
    {
        file_put_contents($this->root . '/var/fiscal-journal-guard.json', '{"state":');

        $this->assertSame(FiscalJournalGuard::OK, $this->cache()->state());
        $this->assertSame(1, $this->checks);
    }

    public function testADatabaseWhoseChainCannotBeReadIsCheckedEveryTime(): void
    {
        $this->connection->executeStatement('DROP TABLE fiscal_chain');

        $this->assertSame(FiscalJournalGuard::OK, $this->cache()->state());
        $this->assertSame(FiscalJournalGuard::OK, $this->cache()->state());
        $this->assertSame(2, $this->checks, 'nothing to tell this database from a restored one: no reuse');
    }

    public function testAnEmptyChainIsAHeadLikeAnyOther(): void
    {
        $this->connection->executeStatement('UPDATE fiscal_chain SET last_hash = NULL, last_record_id = NULL WHERE id = 1');
        $this->cache()->state();
        $this->cache()->state();
        $this->assertSame(1, $this->checks);

        $this->connection->executeStatement("UPDATE fiscal_chain SET last_hash = 'bbb', last_record_id = 1 WHERE id = 1");
        $this->cache()->state();
        $this->assertSame(2, $this->checks);
    }
}
