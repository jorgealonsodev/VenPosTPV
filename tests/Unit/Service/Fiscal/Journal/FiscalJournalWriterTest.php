<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use VenPosTPV\Service\Fiscal\FiscalHash;
use VenPosTPV\Service\Fiscal\Journal\FiscalDocumentSnapshot;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalFlushResult;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIntents;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalWriter;
use VenPosTPV\Service\Fiscal\Journal\JournalLocation;

/**
 * The writer's failure handling (fiscal-journal S2, S4, D3, D4 as amended by T1b) on
 * SQLite: any failure of the primary sends the lines to the secondary with the same
 * seq; a torn write is only an unterminated or non-JSON last line; anything else is
 * damage that is reported and never rewritten; an unreadable record blocks the flush.
 */
final class FiscalJournalWriterTest extends TestCase
{
    private string $root;
    private Connection $connection;
    private FiscalJournalLocations $locations;
    /** @var list<string> numbers the stubbed snapshot cannot read */
    private array $unreadable = [];
    /** @var list<array{string, string}> level and message of every log record */
    private array $logs = [];

    protected function setUp(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('File permissions do not apply to root');
        }
        $this->root = JournalLines::temporaryDirectory('writer');
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->root . '/db.sqlite']);
        $this->connection->executeStatement('CREATE TABLE fiscal_record (id INTEGER PRIMARY KEY AUTOINCREMENT, record_type VARCHAR(10) NOT NULL, invoice_type VARCHAR(2) NOT NULL, issuer_nif VARCHAR(16) NOT NULL, number VARCHAR(60) NOT NULL, issue_date VARCHAR(10) NOT NULL, tax_total VARCHAR(16) NOT NULL, total VARCHAR(16) NOT NULL, previous_hash VARCHAR(64) NULL, hash VARCHAR(64) NOT NULL, generated_at VARCHAR(25) NOT NULL)');
        $this->locations = new FiscalJournalLocations($this->root . '/backups/fiscal-journal', $this->root . '/var/fiscal-journal');
        $this->writer()->createJournal();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
            foreach (glob($this->root . '/*/fiscal-journal/journal-*.jsonl') ?: [] as $file) {
                if (!is_link($file)) {
                    @chmod($file, 0600);
                }
            }
            JournalLines::remove($this->root);
        }
    }

    /** A writer of a new process (nothing in memory), logging into $this->logs. */
    private function writer(): FiscalJournalWriter
    {
        $logger = new class($this->logs) extends AbstractLogger {
            /** @param list<array{string, string}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logs[] = [(string) $level, (string) $message];
            }
        };
        $unreadable = &$this->unreadable;
        $snapshot = new class($this->connection, $unreadable) extends FiscalDocumentSnapshot {
            /** @param list<string> $unreadable */
            public function __construct(Connection $connection, private array &$unreadable)
            {
                parent::__construct($connection);
            }

            public function build(array $record, ?int $refundMovementId = null): array
            {
                if (\in_array($record['number'], $this->unreadable, true)) {
                    throw new \UnexpectedValueException(sprintf('Unknown fiscal document number %s', $record['number']));
                }

                return ['kind' => (string) $record['invoice_type'], 'series' => 'A26', 'counter' => null, 'rows' => []];
            }
        };

        return new FiscalJournalWriter($this->connection, $this->locations, new FiscalJournalIntents($this->locations), $logger, $snapshot);
    }

    private function issue(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $previous = $this->connection->fetchOne('SELECT hash FROM fiscal_record ORDER BY id DESC LIMIT 1');
            $number = sprintf('A26%06d', 1 + (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fiscal_record'));
            $record = ['record_type' => 'alta', 'invoice_type' => 'F2', 'issuer_nif' => 'B00000000', 'number' => $number, 'issue_date' => '14-11-2026', 'tax_total' => '2.10', 'total' => '12.10', 'previous_hash' => $previous === false ? null : $previous, 'generated_at' => '2026-11-14T18:00:00+01:00'];
            $record['hash'] = FiscalHash::alta('B00000000', $number, '14-11-2026', 'F2', '2.10', '12.10', (string) $record['previous_hash'], $record['generated_at']);
            $this->connection->insert('fiscal_record', $record);
        }
    }

    private function primary(): JournalLocation
    {
        return $this->locations->primary();
    }

    private function secondary(): JournalLocation
    {
        return $this->locations->secondary();
    }

    /** @return list<array<string, mixed>> */
    private function lines(?JournalLocation $location = null): array
    {
        return array_values(iterator_to_array(($location ?? $this->primary())->lines(), false));
    }

    private function monthFile(): string
    {
        return $this->root . '/backups/fiscal-journal/journal-' . (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->format('Y-m') . '.jsonl';
    }

    /** The primary holds every record once, in order, gapless and chained. */
    private function assertPrimaryComplete(): void
    {
        $lines = $this->lines();
        $this->assertSame($this->connection->fetchFirstColumn('SELECT number FROM fiscal_record ORDER BY id'), array_column(array_column($lines, 'fiscal_record'), 'number'));
        $this->assertSame(range(1, \count($lines)), array_column($lines, 'seq'));
        $previous = '';
        foreach ($lines as $line) {
            $this->assertTrue(JournalLocation::selfValid($line));
            $this->assertSame($previous, $line['prev']);
            $previous = $line['line_hash'];
        }
        $this->assertSame($previous, $this->primary()->readHead()['line_hash'] ?? null, 'head.json agrees');
        $this->assertSame([], $this->secondary()->journalFiles(), 'the secondary is merged back and emptied');
    }

    // --- (1) any failure of the primary goes to the secondary ---------------------------------

    public function testALockFileThatCannotBeOpenedSendsTheLinesToTheSecondaryWithTheSameSeq(): void
    {
        $this->issue(2);
        $this->writer()->flush();
        unlink($this->root . '/backups/fiscal-journal/journal.lock');
        mkdir($this->root . '/backups/fiscal-journal/journal.lock');
        $this->issue(2);

        $result = $this->writer()->flush();

        $this->assertSame(FiscalJournalFlushResult::OK, $result->status);
        $this->assertSame($this->root . '/var/fiscal-journal', $result->directory);
        $this->assertSame([3, 4], array_column($this->lines($this->secondary()), 'seq'));
        $this->assertSame($this->lines()[1]['line_hash'], $this->lines($this->secondary())[0]['prev']);

        rmdir($this->root . '/backups/fiscal-journal/journal.lock');
        $this->issue(1);
        $this->writer()->flush();
        $this->assertPrimaryComplete();
    }

    /** T1b verification: the after-response flush is bounded, the merge of the secondary included. */
    public function testABoundedFlushMergesAtMostItsLimitAndLeavesTheRestForTheNextFlush(): void
    {
        $this->issue(2);
        $this->writer()->flush();
        unlink($this->root . '/backups/fiscal-journal/journal.lock');
        mkdir($this->root . '/backups/fiscal-journal/journal.lock');
        $this->issue(25);
        $this->writer()->flush();
        $this->assertCount(25, $this->lines($this->secondary()));
        rmdir($this->root . '/backups/fiscal-journal/journal.lock');
        $this->issue(3);

        $result = $this->writer()->flushAfterIssue(20, 2.0);

        $this->assertSame(FiscalJournalFlushResult::OK, $result->status);
        $this->assertSame(20, $result->written, 'merged lines count against the limit');
        $this->assertSame(range(1, 22), array_column($this->lines(), 'seq'), 'two journaled before, twenty merged, nothing more');
        $this->assertSame($this->lines()[21]['line_hash'], $this->primary()->readHead()['line_hash'] ?? null);
        $this->assertSame(range(3, 27), array_column($this->lines($this->secondary()), 'seq'), 'the secondary is kept until all of it is merged');

        $this->writer()->flush();
        $this->assertPrimaryComplete();
        $this->assertCount(30, $this->lines());
    }

    public function testAReadOnlyMonthFileSendsTheLinesToTheSecondaryAndTheyComeBackOnce(): void
    {
        $this->issue(2);
        $this->writer()->flush();
        chmod($this->monthFile(), 0400);
        $this->issue(2);

        $result = $this->writer()->flush();

        $this->assertSame(FiscalJournalFlushResult::OK, $result->status);
        $this->assertSame(2, $result->written);
        $this->assertSame([3, 4], array_column($this->lines($this->secondary()), 'seq'));

        chmod($this->monthFile(), 0600);
        $this->issue(1);
        $this->writer()->flush();
        $this->assertPrimaryComplete();
    }

    public function testAFullDiskSendsTheLinesToTheSecondary(): void
    {
        if (!is_writable('/dev/full')) {
            $this->markTestSkipped('/dev/full is not available');
        }
        $this->issue(2);
        symlink('/dev/full', $this->monthFile());

        $result = $this->writer()->flush();

        $this->assertSame(FiscalJournalFlushResult::OK, $result->status);
        $this->assertSame([1, 2], array_column($this->lines($this->secondary()), 'seq'));

        unlink($this->monthFile());
        $this->writer()->flush();
        $this->assertPrimaryComplete();
    }

    public function testAPartialLineLeftByAFailedWriteIsRepairedAndNeverDuplicated(): void
    {
        $this->issue(2);
        $this->writer()->flush();
        $this->issue(2);
        // The write of line 3 stopped half-way (disk full), then the file became read-only.
        $line = json_encode(['v' => 1, 'seq' => 3, 'filler' => str_repeat('x', 200)]);
        file_put_contents($this->monthFile(), substr((string) $line, 0, 60), \FILE_APPEND);
        chmod($this->monthFile(), 0400);

        $whileBroken = $this->writer()->flush();

        $this->assertSame(FiscalJournalFlushResult::OK, $whileBroken->status);
        $this->assertSame([3, 4], array_column($this->lines($this->secondary()), 'seq'), 'the same seq the failed write had');

        chmod($this->monthFile(), 0600);
        $this->writer()->flush();
        $this->assertPrimaryComplete();
        $this->assertCount(1, glob($this->root . '/backups/fiscal-journal/quarantine/*') ?: [], 'the fragment is kept aside');
    }

    public function testWhenTheSecondaryFailsTooTheFlushSaysNoLocationWritableWithoutThrowing(): void
    {
        $this->issue(1);
        $this->writer()->flush();
        chmod($this->monthFile(), 0400);
        chmod($this->root . '/var/fiscal-journal', 0500);
        $this->issue(1);

        $result = $this->writer()->flush();

        chmod($this->root . '/var/fiscal-journal', 0700);
        $this->assertSame(FiscalJournalFlushResult::UNWRITABLE, $result->status);
        $this->assertCount(1, $this->lines());
    }

    // --- (3) and (4) damage is reported, never rebuilt ------------------------------------------

    public function testACompleteLastLineWithAWrongHashIsDamageAndNothingIsCutOrAppended(): void
    {
        $this->issue(3);
        $this->writer()->flush();
        $lines = file($this->monthFile());
        $lines[2] = str_replace('"A26000003"', '"A26000099"', $lines[2]);
        file_put_contents($this->monthFile(), implode('', $lines));
        $before = file_get_contents($this->monthFile());
        $this->issue(1);

        $result = $this->writer()->flush();

        $this->assertSame(FiscalJournalFlushResult::DAMAGED, $result->status);
        $this->assertSame('line', $result->reason);
        $this->assertSame($before, file_get_contents($this->monthFile()), 'not treated as a torn write');
        $this->assertSame([], glob($this->root . '/backups/fiscal-journal/quarantine/*') ?: []);
        $this->assertSame([], $this->secondary()->journalFiles(), 'nothing written anywhere');
    }

    public function testACompleteLastLineThatDoesNotChainIsDamage(): void
    {
        $this->issue(3);
        $this->writer()->flush();
        $lines = file($this->monthFile());
        $third = json_decode($lines[2], true);
        $third['prev'] = str_repeat('0', 64);
        $third['line_hash'] = \VenPosTPV\Service\Fiscal\Journal\CanonicalJson::lineHash($third);
        $lines[2] = json_encode($third, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION) . "\n";
        file_put_contents($this->monthFile(), implode('', $lines));
        $before = file_get_contents($this->monthFile());

        $result = $this->writer()->flush();

        $this->assertSame(FiscalJournalFlushResult::DAMAGED, $result->status);
        $this->assertSame('chain', $result->reason);
        $this->assertSame($before, file_get_contents($this->monthFile()));
    }

    public function testATerminatedLastLineThatIsNotJsonIsATornWrite(): void
    {
        $this->issue(2);
        $this->writer()->flush();
        $this->issue(1);
        // Killed while writing line 3, before head.json recorded it.
        file_put_contents($this->monthFile(), '{"v":1,"seq":3,"journaled_at"' . "\n", \FILE_APPEND);

        $result = $this->writer()->flush();

        $this->assertSame(FiscalJournalFlushResult::OK, $result->status);
        $this->assertSame(1, $result->written, 'rewritten from the database');
        $this->assertPrimaryComplete();
    }

    public function testALastLineThatHeadJsonRecordedIsNeverCutEvenWhenItIsNoLongerJson(): void
    {
        $this->issue(3);
        $this->writer()->flush();
        $lines = file($this->monthFile());
        $lines[2] = substr($lines[2], 0, 50) . "\n";
        file_put_contents($this->monthFile(), implode('', $lines));
        $before = file_get_contents($this->monthFile());

        $result = $this->writer()->flush();

        $this->assertSame(FiscalJournalFlushResult::DAMAGED, $result->status);
        $this->assertSame('line', $result->reason);
        $this->assertSame($before, file_get_contents($this->monthFile()), 'head.json proves it was complete: damage, not a torn write');
    }

    public function testRemovedLastLinesAreDamageAndNeverRebuilt(): void
    {
        $this->issue(5);
        $this->writer()->flush();
        $lines = file($this->monthFile());
        file_put_contents($this->monthFile(), implode('', \array_slice($lines, 0, 3)));

        $result = $this->writer()->flush();

        $this->assertSame(FiscalJournalFlushResult::DAMAGED, $result->status);
        $this->assertSame('removed', $result->reason);
        $this->assertCount(3, $this->lines(), 'the database never refills a journal that lost lines');
    }

    public function testARemovedMonthFileIsDamageAndNeverRebuilt(): void
    {
        $this->issue(2);
        $this->writer()->flush();
        unlink($this->monthFile());

        $result = $this->writer()->flush();

        $this->assertSame(FiscalJournalFlushResult::DAMAGED, $result->status);
        $this->assertSame('removed', $result->reason);
        $this->assertFileDoesNotExist($this->monthFile());
    }

    public function testAHeadThatDisagreesWithTheLastLineIsDamage(): void
    {
        $this->issue(2);
        $this->writer()->flush();
        $head = $this->primary()->readHead();
        $head['line_hash'] = str_repeat('a', 64);
        file_put_contents($this->root . '/backups/fiscal-journal/head.json', json_encode($head));
        $this->issue(1);

        $result = $this->writer()->flush();

        $this->assertSame(FiscalJournalFlushResult::DAMAGED, $result->status);
        $this->assertSame('head', $result->reason);
        $this->assertCount(2, $this->lines());
    }

    public function testAMissingHeadWithIntactFilesIsRebuiltWithAWarning(): void
    {
        $this->issue(2);
        $this->writer()->flush();
        unlink($this->root . '/backups/fiscal-journal/head.json');
        $this->issue(1);

        $result = $this->writer()->flush();

        $this->assertSame(FiscalJournalFlushResult::OK, $result->status);
        $this->assertPrimaryComplete();
        $this->assertContains('warning', array_column($this->logs, 0));
    }

    public function testAHeadLeftBehindByAKilledFlushIsRefreshed(): void
    {
        $this->issue(1);
        $this->writer()->flush();
        $behind = file_get_contents($this->root . '/backups/fiscal-journal/head.json');
        $this->issue(2);
        $this->writer()->flush();
        // Killed after the lines were written and before head.json was.
        file_put_contents($this->root . '/backups/fiscal-journal/head.json', $behind);
        $this->issue(1);

        $result = $this->writer()->flush();

        $this->assertSame(FiscalJournalFlushResult::OK, $result->status);
        $this->assertPrimaryComplete();
    }

    public function testAVanishedPrimaryFolderIsJournalMissingAndNotRecreated(): void
    {
        $this->issue(2);
        $this->writer()->flush();
        JournalLines::remove($this->root . '/backups/fiscal-journal');

        $result = $this->writer()->flush();

        $this->assertSame(FiscalJournalFlushResult::JOURNAL_MISSING, $result->status);
        $this->assertNull($this->primary()->journalId(), 'only createJournal() makes a journal');
        $this->assertSame([], $this->primary()->journalFiles());
        $this->assertSame([], $this->secondary()->journalFiles());
    }

    // --- (5) an unreadable record blocks, never throws -----------------------------------------

    public function testAnUnreadableRecordStopsTheFlushAsBlockedAndKeepsSeqGapless(): void
    {
        $this->issue(3);
        $this->unreadable = ['A26000002'];

        $result = $this->writer()->flush();

        $this->assertSame(FiscalJournalFlushResult::BLOCKED, $result->status);
        $this->assertSame('A26000002', $result->number);
        $this->assertSame('Unknown fiscal document number A26000002', $result->reason);
        $this->assertSame(1, $result->written);
        $this->assertSame([1], array_column($this->lines(), 'seq'));

        $this->unreadable = [];
        $this->writer()->flush();
        $this->assertPrimaryComplete();
    }

    // --- (2) the after-response flush is bounded -----------------------------------------------

    public function testABoundedFlushWritesAtMostItsLimit(): void
    {
        $this->issue(5);

        $result = $this->writer()->flush(limit: 2);

        $this->assertSame(FiscalJournalFlushResult::OK, $result->status);
        $this->assertSame(2, $result->written);
        $this->assertCount(2, $this->lines());
        $this->assertSame(3, $this->writer()->flush()->written, 'the next unbounded flush takes the rest');
        $this->assertPrimaryComplete();
    }

    public function testABoundedFlushThatCannotGetTheLockLeavesTheWorkToTheNextOne(): void
    {
        $this->issue(1);
        $this->writer()->flush();
        $this->issue(1);
        $held = fopen($this->root . '/backups/fiscal-journal/journal.lock', 'c');
        flock($held, \LOCK_EX);
        try {
            $started = microtime(true);
            $result = $this->writer()->flush(limit: 10, lockWaitSeconds: 0.3);
            $elapsed = microtime(true) - $started;
        } finally {
            flock($held, \LOCK_UN);
            fclose($held);
        }

        $this->assertSame(FiscalJournalFlushResult::BUSY, $result->status);
        $this->assertLessThan(2.0, $elapsed);
        $this->assertGreaterThanOrEqual(0.25, $elapsed, 'it retried for the time allowed');
        $this->assertCount(1, $this->lines());
        $this->assertSame(1, $this->writer()->flush()->written);
    }

    // --- (8) a lasting condition is a warning once per process, never an error -------------------

    public function testALastingUnwritableJournalWarnsOncePerProcessAndNeverLogsAnError(): void
    {
        chmod($this->root . '/backups/fiscal-journal', 0500);
        chmod($this->root . '/var/fiscal-journal', 0500);
        $this->issue(1);
        $writer = $this->writer();

        $writer->flush();
        $writer->flush();

        chmod($this->root . '/backups/fiscal-journal', 0700);
        chmod($this->root . '/var/fiscal-journal', 0700);
        $levels = array_column($this->logs, 0);
        $this->assertNotContains('error', $levels);
        $this->assertNotContains('critical', $levels);
        $this->assertSame(['warning', 'debug'], $levels);
    }
}
