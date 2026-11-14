<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\Journal\JournalLocation;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * S4 (c): several processes issuing and flushing at once leave one line per
 * document, in chain order, with `seq` gapless and never repeated.
 */
final class FiscalJournalConcurrencyMySqlIntegrationTest extends TestCase
{
    private const WORKERS = 3;
    private const PER_WORKER = 15;

    private ?Connection $connection = null;

    private JournalPathFixtures $fixtures;

    private JournalHarness $journal;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->fixtures = new JournalPathFixtures($this->connection, fn (string $class) => $this->createMock($class));
        $this->fixtures->reset();
        $this->fixtures->install();
        $this->journal = new JournalHarness($this->connection);
        $this->journal->writer->createJournal();
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->fixtures->reset();
            $this->connection->close();
            $this->journal->remove();
        }
        $this->connection = null;
    }

    public function testConcurrentTillsLeaveAGaplessJournalInChainOrder(): void
    {
        $processes = [];
        foreach (range(1, self::WORKERS) as $worker) {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                __DIR__ . '/fixtures/journal_worker.php',
                $this->journal->root,
                (string) self::PER_WORKER,
                (string) JournalPathFixtures::TERMINAL,
                (string) JournalPathFixtures::SESSION,
            ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        $numbers = [];
        foreach ($processes as [$process, $pipes]) {
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $result = json_decode($output, true);
            $this->assertIsArray($result, $output . $errors);
            $this->assertTrue($result['ok'], $output);
            $numbers = [...$numbers, ...$result['numbers']];
        }
        $this->assertCount(self::WORKERS * self::PER_WORKER, array_unique($numbers));

        // The workers' own flushes journaled everything: a later flush finds nothing.
        $this->assertSame(0, $this->journal->anotherProcess()->writer->flush()->written);

        $records = $this->connection->fetchAllAssociative('SELECT * FROM fiscal_record ORDER BY id');
        $lines = $this->journal->lines();
        $this->assertCount(self::WORKERS * self::PER_WORKER, $records);
        $this->assertSame($records, array_column($lines, 'fiscal_record'), 'one line per document, in chain order');
        $this->assertSame(range(1, \count($records)), array_column($lines, 'seq'), 'gapless, never repeated');
        $previousLine = '';
        $previousHash = null;
        foreach ($lines as $line) {
            $this->assertTrue(JournalLocation::selfValid($line));
            $this->assertSame($previousLine, $line['prev']);
            $this->assertSame($previousHash, $line['fiscal_record']['previous_hash'], 'the journal follows the fiscal chain');
            $previousLine = $line['line_hash'];
            $previousHash = $line['fiscal_record']['hash'];
        }
        $this->assertSame([], $this->journal->intentFiles());
        $this->assertSame([], $this->journal->secondary()->journalFiles());
    }
}
