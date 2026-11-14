<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalAlerts;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalReconciler;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalState;
use VenPosTPV\Service\Mail\BackupAlertMailer;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * The every-minute catch-up of the fiscal journal (fiscal-journal S2, S3, S4 a/b,
 * D3.5) on a real database, with documents issued the way checkout does.
 */
final class FiscalJournalReconcilerMySqlIntegrationTest extends TestCase
{
    private ?Connection $connection = null;
    private JournalPathFixtures $fixtures;
    private JournalHarness $journal;
    private int $mails = 0;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->fixtures = new JournalPathFixtures($this->connection, fn (string $class) => $this->createMock($class));
        $this->fixtures->reset();
        $this->fixtures->install();
        $this->connection->executeStatement('DELETE FROM app_setting WHERE name IN (?, ?)', [FiscalJournalAlerts::SETTING, FiscalJournalAlerts::INCIDENT_SETTING]);
        $this->journal = new JournalHarness($this->connection);
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->connection->executeStatement('DELETE FROM app_setting WHERE name IN (?, ?)', [FiscalJournalAlerts::SETTING, FiscalJournalAlerts::INCIDENT_SETTING]);
            $this->fixtures->reset();
            $this->connection->close();
            $this->journal->remove();
        }
        $this->connection = null;
    }

    private function reconciler(?JournalHarness $process = null): FiscalJournalReconciler
    {
        $process ??= $this->journal->anotherProcess();
        $mailer = $this->createMock(BackupAlertMailer::class);
        $mailer->method('send')->willReturnCallback(function (): bool {
            ++$this->mails;

            return true;
        });

        return new FiscalJournalReconciler($this->connection, $process->locations, $process->intents, new FiscalJournalAlerts($this->connection, $mailer));
    }

    private function issue(JournalHarness $process, bool $commit = true): string
    {
        return JournalIssue::ticket($this->connection, $process->intents, JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION, $commit);
    }

    private static function inMinutes(float $minutes): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify(sprintf('+%d seconds', (int) ($minutes * 60)));
    }

    /** @return list<string> */
    private function journaledNumbers(): array
    {
        return array_column(array_column($this->journal->lines(), 'fiscal_record'), 'number');
    }

    public function testBeforeTheJournalIsCreatedTheJobDoesNothingAndExitsZero(): void
    {
        $this->issue($this->journal->anotherProcess());

        $state = $this->reconciler()->run(self::inMinutes(10));

        $this->assertSame(0, $state->exitCode);
        $this->assertSame(FiscalJournalState::NOT_STARTED, $state->status());
        $this->assertSame(0, $this->mails);
        $this->assertSame([], $this->journal->lines(), 'nothing journaled');
        $this->assertFileDoesNotExist($this->journal->root . '/backups/fiscal-journal/state.json');
        $this->assertFileDoesNotExist($this->journal->root . '/backups/fiscal-journal/journal.id', 'no journal is created by the job');
    }

    public function testWithNeitherFolderWritableSalesGoOnTheJobExitsOneAndOneRunCatchesUpOnceWritable(): void
    {
        $this->journal->writer->createJournal();
        chmod($this->journal->root . '/backups/fiscal-journal', 0500);
        chmod($this->journal->root . '/var/fiscal-journal', 0500);

        $till = $this->journal->anotherProcess();
        $first = $this->issue($till);
        $second = $this->issue($till);
        $till->writer->flushAfterIssue();
        $this->assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fiscal_record'), 'the sales went through');

        $state = $this->reconciler()->run(self::inMinutes(1));
        $this->assertSame(1, $state->exitCode);
        $this->assertSame(['count' => 2, 'oldest_number' => $first], array_intersect_key($state->data['pending'], ['count' => 0, 'oldest_number' => 0]));
        $this->assertSame(0, $this->mails);

        $late = $this->reconciler()->run(self::inMinutes(6));
        $this->assertSame(FiscalJournalState::LAGGING, $late->status());
        $this->assertSame(0, $late->exitCode, 'the same incident is not reported every minute');
        $this->assertSame(1, $this->mails, 'one alert after 5 minutes');
        $this->reconciler()->run(self::inMinutes(7));
        $this->assertSame(1, $this->mails, 'one per incident');

        chmod($this->journal->root . '/backups/fiscal-journal', 0700);
        chmod($this->journal->root . '/var/fiscal-journal', 0700);
        $recovered = $this->reconciler()->run(self::inMinutes(8));

        $this->assertSame(0, $recovered->exitCode);
        $this->assertSame(FiscalJournalState::OK, $recovered->status());
        $this->assertSame([$first, $second], $this->journaledNumbers());
        $this->assertSame($this->connection->fetchAllAssociative('SELECT * FROM fiscal_record ORDER BY id'), array_column($this->journal->lines(), 'fiscal_record'));
    }

    public function testACommittedDocumentWhoseProcessDiedIsJournaledAndItsIntentSpent(): void
    {
        $this->journal->writer->createJournal();
        // Committed, then the process was killed before its flush (S4 b).
        $number = $this->issue($this->journal->anotherProcess());
        $this->assertCount(1, $this->journal->intentFiles());

        $state = $this->reconciler()->run(self::inMinutes(3));

        $this->assertSame(FiscalJournalState::OK, $state->status());
        $this->assertSame([$number], $this->journaledNumbers());
        $this->assertSame([], $this->journal->intentFiles());
    }

    public function testARolledBackDocumentWhoseNumberWasTakenSinceIsDiscarded(): void
    {
        $this->journal->writer->createJournal();
        // Crash before commit (S4 a): the intent stays, the number was never issued.
        $this->issue($this->journal->anotherProcess(), commit: false);
        // Within the same second the retry would get the very same hash (same number,
        // previous hash and timestamp), so its intent would simply be the same file.
        sleep(1);
        $taken = $this->issue($this->journal->anotherProcess());
        $this->journal->anotherProcess()->writer->flush();
        $this->assertCount(1, $this->journal->intentFiles(), 'the rolled-back intent is left over');

        $state = $this->reconciler()->run(self::inMinutes(3));

        $this->assertSame(0, $state->exitCode);
        $this->assertSame([], $this->journal->intentFiles());
        $this->assertCount(1, glob($this->journal->root . '/backups/fiscal-journal/intents/discarded/*.json') ?: []);
        $this->assertSame([$taken], $this->journaledNumbers());
    }

    public function testARolledBackDocumentWhoseSlotIsStillFreeIsKeptNeverBlocksAndIsShownAfterTenMinutes(): void
    {
        $this->journal->writer->createJournal();
        $this->issue($this->journal->anotherProcess(), commit: false);

        $early = $this->reconciler()->run(self::inMinutes(3));
        $this->assertSame(0, $early->exitCode);
        $this->assertSame(FiscalJournalState::OK, $early->status());
        $this->assertSame([], $early->data['unconfirmed_intents']);
        $this->assertCount(1, $this->journal->intentFiles(), 'kept');

        $late = $this->reconciler()->run(self::inMinutes(11));
        $this->assertSame(0, $late->exitCode);
        $this->assertCount(1, $late->data['unconfirmed_intents']);

        // And selling goes on: the next document takes the slot and the intent is discarded.
        $next = $this->issue($this->journal->anotherProcess());
        $after = $this->reconciler()->run(self::inMinutes(12));
        $this->assertSame([], $after->data['unconfirmed_intents']);
        $this->assertSame([$next], $this->journaledNumbers());
        $this->assertSame([], $this->journal->intentFiles());
    }
}
