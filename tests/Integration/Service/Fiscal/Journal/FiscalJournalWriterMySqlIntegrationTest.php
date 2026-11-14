<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalFlushResult;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIntents;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\Fiscal\Journal\JournalLocation;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * The write protocol of the fiscal journal (D3, D4) and its failure cases (S2, S4,
 * S11) against a real database.
 */
final class FiscalJournalWriterMySqlIntegrationTest extends TestCase
{
    private ?Connection $connection = null;

    private JournalPathFixtures $fixtures;

    private JournalHarness $journal;

    private string $timezone;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->timezone = date_default_timezone_get();
        $this->fixtures = new JournalPathFixtures($this->connection, fn (string $class) => $this->createMock($class));
        $this->fixtures->reset();
        $this->fixtures->install();
        $this->journal = new JournalHarness($this->connection);
        $this->journal->writer->createJournal();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        if ($this->connection !== null) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->fixtures->reset();
            $this->connection->close();
            $this->journal->remove();
        }
        $this->connection = null;
    }

    private function issue(?JournalHarness $process = null, bool $commit = true, ?\DateTimeImmutable $at = null): string
    {
        return JournalIssue::ticket($this->connection, ($process ?? $this->journal)->intents, JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION, $commit, $at);
    }

    /** The journal (primary) holds every fiscal record, verbatim, in order, chained. */
    private function assertPrimaryComplete(): void
    {
        $records = $this->connection->fetchAllAssociative('SELECT * FROM fiscal_record ORDER BY id');
        $lines = $this->journal->lines();
        $this->assertSame($records, array_column($lines, 'fiscal_record'));
        $this->assertSame(range(1, \count($records)), array_column($lines, 'seq'));
        $previous = '';
        foreach ($lines as $line) {
            $this->assertTrue(JournalLocation::selfValid($line));
            $this->assertSame($previous, $line['prev']);
            $previous = $line['line_hash'];
        }
    }

    public function testARolledBackIssueLeavesNoLineAndItsIntentIsRemoved(): void
    {
        $this->issue(commit: false);
        $this->assertCount(1, $this->journal->intentFiles(), 'the intent is written inside the transaction');

        $result = $this->journal->writer->flushAfterIssue();

        $this->assertSame(FiscalJournalFlushResult::OK, $result->status);
        $this->assertSame(0, $result->written);
        $this->assertSame([], $this->journal->lines());
        $this->assertSame([], $this->journal->intentFiles());
    }

    public function testACommittedDocumentWhoseProcessDiedBeforeTheFlushIsJournaledByTheNextFlush(): void
    {
        $number = $this->issue();
        $intent = $this->journal->intentFiles();
        $this->assertCount(1, $intent);

        // The process is killed after COMMIT: its flush never runs. Another one does.
        $result = $this->journal->anotherProcess()->writer->flush();

        $this->assertSame(1, $result->written);
        $this->assertSame($number, $this->journal->lines()[0]['fiscal_record']['number']);
        $this->assertSame([], $this->journal->intentFiles(), 'the line written, its intent goes');
        $this->assertPrimaryComplete();
    }

    public function testATornLastLineIsQuarantinedAndRewrittenFromTheDatabase(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->issue();
            $this->journal->writer->flushAfterIssue();
        }
        $file = $this->journal->primary()->journalFiles()[0];
        $content = (string) file_get_contents($file);
        $lines = explode("\n", rtrim($content, "\n"));
        $torn = substr($lines[2], 0, (int) (\strlen($lines[2]) / 2));
        file_put_contents($file, $lines[0] . "\n" . $lines[1] . "\n" . $torn);

        $result = $this->journal->anotherProcess()->writer->flush();

        $this->assertSame(FiscalJournalFlushResult::OK, $result->status);
        $this->assertSame(1, $result->written, 'the third document again, from the database');
        $this->assertPrimaryComplete();
        $quarantined = glob($this->journal->root . '/backups/fiscal-journal/quarantine/*') ?: [];
        $this->assertCount(1, $quarantined);
        $this->assertSame($torn, file_get_contents($quarantined[0]));
    }

    public function testADamagedLineBeforeTheLastIsReportedAndAppendsGoOn(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->issue();
            $this->journal->writer->flushAfterIssue();
        }
        $file = $this->journal->primary()->journalFiles()[0];
        $lines = explode("\n", rtrim((string) file_get_contents($file), "\n"));
        $lines[1] = str_replace('"v":1', '"v":2', $lines[1]);
        file_put_contents($file, implode("\n", $lines) . "\n");
        $damaged = (string) file_get_contents($file);
        $this->issue();

        $result = $this->journal->anotherProcess()->writer->flush();

        $this->assertTrue($result->damaged);
        $this->assertSame(1, $result->written);
        $this->assertStringStartsWith($damaged, (string) file_get_contents($file), 'the damaged line is never rewritten');
    }

    public function testWithThePrimaryReadOnlyLinesGoToTheSecondaryAndComeBackInOrderOnce(): void
    {
        $this->issue();
        $this->journal->writer->flushAfterIssue();
        chmod($this->journal->root . '/backups/fiscal-journal', 0500);

        $whileReadOnly = $this->journal->anotherProcess();
        $this->issue($whileReadOnly);
        $this->issue($whileReadOnly);
        $result = $whileReadOnly->writer->flushAfterIssue();

        $this->assertSame(FiscalJournalFlushResult::OK, $result->status);
        $this->assertSame($this->journal->root . '/var/fiscal-journal', $result->directory);
        $this->assertCount(1, $this->journal->lines());
        $secondary = $this->journal->lines($this->journal->secondary());
        $this->assertSame([2, 3], array_column($secondary, 'seq'), 'the same numbering as the primary');
        $this->assertSame($this->journal->lines()[0]['line_hash'], $secondary[0]['prev']);

        chmod($this->journal->root . '/backups/fiscal-journal', 0700);
        $writableAgain = $this->journal->anotherProcess();
        $this->issue($writableAgain);
        $writableAgain->writer->flushAfterIssue();

        $this->assertPrimaryComplete();
        $this->assertSame($secondary, \array_slice($this->journal->lines(), 1, 2), 'the secondary lines, verbatim');
        $this->assertSame([], $this->journal->secondary()->journalFiles(), 'the secondary is emptied');
        $this->assertSame([], $this->journal->intentFiles());
    }

    public function testWithNeitherFolderWritableTheSaleSucceedsAndNothingIsWrittenUntilTheyAre(): void
    {
        chmod($this->journal->root . '/backups/fiscal-journal', 0500);
        chmod($this->journal->root . '/var/fiscal-journal', 0500);
        $process = $this->journal->anotherProcess();

        $sale = $this->fixtures->checkout($this->fixtures->recorder($process->intents))
            ->createOrder(1, $this->fixtures->cart() + ['cash_amount' => JournalPathFixtures::PRICE], 'cash', JournalPathFixtures::PRICE);
        $result = $process->writer->flushAfterIssue();

        $this->assertFalse($sale['saga_error']);
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fiscal_record'));
        $this->assertSame(FiscalJournalFlushResult::UNWRITABLE, $result->status);
        $this->assertSame([], $this->journal->lines());
        $this->assertSame([], $this->journal->lines($this->journal->secondary()));

        chmod($this->journal->root . '/backups/fiscal-journal', 0700);
        chmod($this->journal->root . '/var/fiscal-journal', 0700);
        $this->journal->anotherProcess()->writer->flush();

        $this->assertPrimaryComplete();
        $this->assertSame($sale['reference'], $this->journal->lines()[0]['fiscal_record']['number']);
    }

    public function testAnIntentThatThrowsNeverFailsTheSale(): void
    {
        $locations = new FiscalJournalLocations($this->journal->root . '/backups/fiscal-journal', $this->journal->root . '/var/fiscal-journal');
        $failing = new class($locations) extends FiscalJournalIntents {
            public function prepared(array $record): void
            {
                throw new \RuntimeException('disk on fire');
            }
        };

        $sale = $this->fixtures->checkout($this->fixtures->recorder($failing))
            ->createOrder(1, $this->fixtures->cart() + ['cash_amount' => JournalPathFixtures::PRICE], 'cash', JournalPathFixtures::PRICE);

        $this->assertFalse($sale['saga_error']);
        $this->assertSame($sale['reference'], $this->connection->fetchOne('SELECT number FROM fiscal_record'));
        $this->journal->anotherProcess()->writer->flush();
        $this->assertPrimaryComplete();
    }

    public function testTheJournalNeverWritesAfterADocumentTheDatabaseNoLongerHas(): void
    {
        $this->issue();
        $this->journal->writer->flushAfterIssue();
        // A restore from before that ticket: the database lost it.
        $this->connection->executeStatement('TRUNCATE TABLE fiscal_record');
        $this->connection->executeStatement('UPDATE fiscal_chain SET last_record_id = NULL, last_hash = NULL WHERE id = 1');
        $process = $this->journal->anotherProcess();
        $this->issue($process);

        $result = $process->writer->flushAfterIssue();

        $this->assertSame(FiscalJournalFlushResult::HEAD_MISSING, $result->status);
        $this->assertCount(1, $this->journal->lines());
    }

    public function testWithoutAJournalIdentityNothingIsWritten(): void
    {
        $journal = new JournalHarness($this->connection);
        try {
            JournalIssue::ticket($this->connection, $journal->intents, JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION);

            $this->assertSame(FiscalJournalFlushResult::NO_JOURNAL, $journal->writer->flushAfterIssue()->status);
            $this->assertSame([], $journal->lines());
        } finally {
            $journal->remove();
        }
    }

    public function testAtNewYearTheLineStoresTheCounterYearTheNumbererUsed(): void
    {
        // The PHP zone is UTC: the counter takes 2026 while Spain is already in 2027.
        date_default_timezone_set('UTC');
        $at = new \DateTimeImmutable('2026-12-31 23:30:00');
        $this->connection->executeStatement('INSERT IGNORE INTO ticket_sequence (letter, year, last_number) VALUES (?, 2027, 0)', [JournalPathFixtures::LETTER]);

        $number = $this->issue(at: $at);
        $this->journal->writer->flushAfterIssue();

        [$line] = $this->journal->lines();
        $this->assertSame(JournalPathFixtures::LETTER . '26000001', $number);
        $this->assertStringContainsString('2027', (string) $line['fiscal_record']['issue_date'], 'the record is dated in Spanish time');
        $this->assertSame(['table' => 'ticket_sequence', 'letter' => JournalPathFixtures::LETTER, 'year' => 2026, 'number' => 1], $line['counter']);
        $this->assertSame(JournalPathFixtures::LETTER . '26', $line['series']);
    }
}
