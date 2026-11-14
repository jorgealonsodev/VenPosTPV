<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use VenPosTPV\Command\FiscalJournalCommand;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalReconciler;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalState;

/** tpv:fiscal:journal, the every-minute job of the fiscal journal (fiscal-journal D9 exit codes). */
final class FiscalJournalCommandTest extends TestCase
{
    private function tester(FiscalJournalState $state, bool $verifyAll = false): CommandTester
    {
        $reconciler = $this->createMock(FiscalJournalReconciler::class);
        $reconciler->expects($this->once())->method('run')->with(null, $verifyAll)->willReturn($state);
        $application = new Application();
        $application->add(new FiscalJournalCommand($reconciler));

        return new CommandTester($application->find('tpv:fiscal:journal'));
    }

    /** @param array<string, mixed> $overrides */
    private static function state(string $status, int $exitCode, array $overrides = []): FiscalJournalState
    {
        $data = $overrides + FiscalJournalState::notStarted(new \DateTimeImmutable('2026-11-14T18:00:00+01:00'))->data;
        $data['status'] = $status;

        return new FiscalJournalState($data, $exitCode);
    }

    public function testWithoutAJournalItExitsZeroQuietly(): void
    {
        $tester = $this->tester(FiscalJournalState::notStarted(new \DateTimeImmutable()));

        $this->assertSame(0, $tester->execute([]));
        $this->assertSame('', $tester->getDisplay(), 'nothing for the cron log every minute');
    }

    public function testPendingDocumentsExitZero(): void
    {
        $tester = $this->tester(self::state(FiscalJournalState::PENDING, 0, ['pending' => ['count' => 2, 'oldest_number' => 'A26000002', 'oldest_at' => null]]));

        $this->assertSame(0, $tester->execute([], ['capture_stderr_separately' => true]));
        $this->assertSame('', $tester->getErrorOutput());
    }

    public function testTheRunThatFirstFindsNoFolderWritableExitsOneWithOneLineOnStderr(): void
    {
        $tester = $this->tester(self::state(FiscalJournalState::PENDING, 1, ['writable' => false, 'pending' => ['count' => 3, 'oldest_number' => 'A26000002', 'oldest_at' => null]]));

        $this->assertSame(1, $tester->execute([], ['capture_stderr_separately' => true]));
        $this->assertSame("Fiscal journal: neither backups/fiscal-journal nor var/fiscal-journal can be written; sales go on and the documents wait in the database.\n", str_replace(\PHP_EOL, "\n", $tester->getErrorOutput()));
        $this->assertSame('', $tester->getDisplay());
    }

    public function testWhileTheSameIncidentLastsTheJobExitsZeroAndStderrStaysSilent(): void
    {
        $tester = $this->tester(self::state(FiscalJournalState::LAGGING, 0, ['writable' => false, 'pending' => ['count' => 3, 'oldest_number' => 'A26000002', 'oldest_at' => null]]));

        $this->assertSame(0, $tester->execute([], ['capture_stderr_separately' => true]));
        $this->assertSame('', $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay(), 'only with -v');
    }

    public function testADamagedJournalExitsThreeWhenReported(): void
    {
        $tester = $this->tester(self::state(FiscalJournalState::DAMAGED, 3, ['writable' => true, 'damaged' => ['folder' => 'primary', 'file' => 'journal-2026-11.jsonl', 'line' => 3, 'reason' => 'line']]));

        $this->assertSame(3, $tester->execute([], ['capture_stderr_separately' => true]));
        $this->assertStringContainsString('Fiscal journal damaged: journal-2026-11.jsonl line 3 (line).', $tester->getErrorOutput());
    }

    public function testABlockedDocumentExitsOneWhenReported(): void
    {
        $tester = $this->tester(self::state(FiscalJournalState::BLOCKED, 1, ['writable' => true, 'blocked' => ['number' => 'X-1', 'reason' => 'Unknown fiscal document number X-1']]));

        $this->assertSame(1, $tester->execute([], ['capture_stderr_separately' => true]));
        $this->assertStringContainsString('document X-1 cannot be journaled: Unknown fiscal document number X-1', $tester->getErrorOutput());
    }

    public function testVerifyAllAsksForTheFullCheck(): void
    {
        $tester = $this->tester(self::state(FiscalJournalState::OK, 0), true);

        $this->assertSame(0, $tester->execute(['--verify-all' => true]));
    }

    public function testADatabaseBehindTheJournalExitsTwoWhenReported(): void
    {
        $tester = $this->tester(self::state(FiscalJournalState::HEAD_MISSING, 2, ['writable' => true]));

        $this->assertSame(2, $tester->execute([], ['capture_stderr_separately' => true]));
        $this->assertStringContainsString('its last document is not in the database', $tester->getErrorOutput());
    }

    public function testALostJournalExitsThreeWhenReportedAndSaysNothingIsJournaledUntilTheReset(): void
    {
        $tester = $this->tester(self::state(FiscalJournalState::JOURNAL_MISSING, 3, ['writable' => true]));

        $this->assertSame(3, $tester->execute([], ['capture_stderr_separately' => true]));
        $this->assertStringContainsString('nothing is journaled until tpv:fiscal:journal:reset', $tester->getErrorOutput());
    }

    public function testTheHelpListsEveryExitCode(): void
    {
        $application = new Application();
        $application->add(new FiscalJournalCommand($this->createMock(FiscalJournalReconciler::class)));
        $help = $application->find('tpv:fiscal:journal')->getHelp();

        $this->assertStringContainsString('0 up to date or documents pending', $help);
        $this->assertStringContainsString('1 neither journal folder can be written', $help);
        $this->assertStringContainsString('2 the database is behind the fiscal journal (a restore): the till cannot issue documents until they are recovered', $help);
        $this->assertStringContainsString('3 the journal is damaged', $help);
    }
}
