<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalFlushResult;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIdentity;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalStarter;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalStep;
use VenPosTPV\Service\Provisioning\ProvisioningRunner;
use VenPosTPV\Service\Provisioning\ProvisioningStateStore;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * fiscal-journal S12, D11 on MySQL: the provisioning step «fiscal-journal» through
 * ProvisioningRunner, on a fresh install and on an upgrade of an install that has
 * already issued documents (backfill, rows verbatim, no renumbering), then the till
 * goes on selling with the guard and the journal continues.
 */
final class FiscalJournalProvisioningMySqlIntegrationTest extends TestCase
{
    private ?Connection $connection = null;
    private JournalPathFixtures $fixtures;
    private JournalHarness $journal;
    private FiscalJournalIdentity $identity;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->fixtures = new JournalPathFixtures($this->connection, fn (string $class) => $this->createMock($class));
        $this->fixtures->reset();
        $this->fixtures->install();
        $this->connection->executeStatement("DELETE FROM app_setting WHERE name = 'fiscal_journal.id' OR name LIKE 'provisioning.fiscal-journal%'");
        $this->journal = new JournalHarness($this->connection);
        $this->identity = new FiscalJournalIdentity($this->connection);
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->fixtures->reset();
            $this->connection->executeStatement("DELETE FROM app_setting WHERE name = 'fiscal_journal.id' OR name LIKE 'provisioning.fiscal-journal%'");
            $this->connection->close();
            $this->journal->remove();
        }
        $this->connection = null;
    }

    private function step(int $perRun = FiscalJournalStep::BACKFILL_LINES_PER_RUN): FiscalJournalStep
    {
        return new FiscalJournalStep(
            $this->journal->locations,
            $this->identity,
            $this->journal->writer,
            new FiscalJournalStarter($this->connection, $this->journal->writer, $this->identity),
            $perRun,
        );
    }

    private function runner(FiscalJournalStep $step): ProvisioningRunner
    {
        return new ProvisioningRunner([$step], new ProvisioningStateStore($this->connection));
    }

    private function sell(int $times = 1): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $total = JournalPathFixtures::PRICE;
            $this->fixtures->checkout($this->fixtures->recorder($this->journal->intents))
                ->createOrder(1, $this->fixtures->cart() + ['cash_amount' => $total], 'cash', $total);
        }
    }

    public function testAFreshInstallReportsTheStepDoneAndTheFirstSaleIsJournaled(): void
    {
        $report = $this->runner($this->step())->run();

        $this->assertTrue($report->isComplete());
        $this->assertSame('fiscal-journal', $report->steps[0]->id);
        $this->assertSame('Diario fiscal preparado', $report->steps[0]->description);
        $id = $this->identity->journalId();
        $this->assertSame($id, $this->journal->primary()->journalId());
        $this->assertSame($id, $this->journal->secondary()->journalId());
        $this->assertSame([], $this->journal->lines());

        $this->fixtures->guard = new FiscalJournalGuard($this->connection, $this->journal->locations);
        $this->sell();
        $this->assertSame(FiscalJournalFlushResult::OK, $this->journal->writer->flush()->status);
        $lines = $this->journal->lines();
        $this->assertCount(1, $lines);
        $this->assertFalse($lines[0]['backfill']);
        $this->assertSame(FiscalJournalGuard::OK, $this->fixtures->guard->check()['state']);
    }

    public function testAnUpgradeBackfillsEveryEarlierDocumentVerbatimAndTheTillGoesOn(): void
    {
        $this->sell(5);
        $records = $this->connection->fetchAllAssociative('SELECT * FROM fiscal_record ORDER BY id');

        // Two per run: the installer's run writes two, the every-minute job the rest.
        $report = $this->runner($this->step(2))->run();
        $this->assertTrue($report->isComplete());
        $this->assertCount(2, $this->journal->lines());
        $this->assertSame(FiscalJournalFlushResult::OK, $this->journal->anotherProcess()->writer->flush()->status);

        $lines = $this->journal->lines();
        $this->assertSame([1, 2, 3, 4, 5], array_column($lines, 'seq'));
        $this->assertSame([true, true, true, true, true], array_column($lines, 'backfill'));
        foreach ($records as $i => $record) {
            $this->assertSame(array_map(static fn ($v) => $v === null ? null : (string) $v, $record), array_map(static fn ($v) => $v === null ? null : (string) $v, $lines[$i]['fiscal_record']), 'fiscal_record verbatim');
        }
        $this->assertSame(array_column($records, 'number'), array_map(static fn (array $l): string => $l['fiscal_record']['number'], $lines), 'never renumbered');

        $this->fixtures->guard = new FiscalJournalGuard($this->connection, $this->journal->locations);
        $this->sell();
        $this->assertSame(FiscalJournalFlushResult::OK, $this->journal->writer->flush()->status);
        $lines = $this->journal->lines();
        $this->assertSame(6, $lines[5]['seq']);
        $this->assertFalse($lines[5]['backfill']);

        // Every later upgrade: done, nothing rewritten.
        $bytes = (string) file_get_contents($this->journal->primary()->journalFiles()[0]);
        $again = $this->runner($this->step())->run();
        $this->assertTrue($again->isComplete());
        $this->assertFalse($again->steps[0]->attempted);
        $this->assertSame($bytes, file_get_contents($this->journal->primary()->journalFiles()[0]));
    }
}
