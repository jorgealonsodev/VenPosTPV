<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalBehindException;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIdentity;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalReset;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalResetResult;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalShopSync;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalStarter;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalStep;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalWriter;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplay;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplayResult;
use VenPosTPV\Service\PrestaShop\PsJournalClient;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * The probes of the final independent verification of the fiscal journal, kept as
 * regression tests on MySQL with a real dump and restore:
 * - a sale issued while the step's or the reset's backfill runs is never marked
 *   `backfill` (only documents up to the journal's backfill_until are);
 * - after a restore of an older dump on a server whose journal folders were lost,
 *   tpv:fiscal:journal:reset refuses (the shop cannot be asked, or it holds the
 *   later documents) and writes nothing, so no number is issued twice;
 * - the shop copy job keeps running while the till is blocked, and the replay
 *   afterwards still restores every sale with its upload row.
 * The shop is the module's own JournalStore (FakeJournalShop), never a real shop.
 */
final class FiscalJournalFinalVerificationMySqlIntegrationTest extends TestCase
{
    private const RESTORE_DB = 'venposjournal_final';

    private ?Connection $connection = null;
    private ?Connection $restored = null;
    private JournalPathFixtures $fixtures;
    private JournalHarness $journal;
    private string $dump = '';
    private string $shopStore = '';
    private bool $userAdded = false;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->fixtures = new JournalPathFixtures($this->connection, fn (string $class) => $this->createMock($class));
        $this->fixtures->reset();
        $this->fixtures->install();
        $this->clean($this->connection);
        // The replay checks that every user it names exists (D8.1); the fixtures sell as user 1.
        if ($this->connection->fetchOne('SELECT 1 FROM user WHERE id = 1') === false) {
            $this->connection->insert('user', ['id' => 1, 'role_id' => (int) $this->connection->fetchOne('SELECT MIN(id) FROM role'), 'name' => 'Replay cashier', 'pin_hash' => 'x', 'active' => 1]);
            $this->userAdded = true;
        }
        $this->journal = new JournalHarness($this->connection);
        $this->shopStore = sys_get_temp_dir() . '/venpos-final-shop-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        $this->restored?->close();
        $this->restored = null;
        if ($this->connection !== null) {
            JournalRestore::drop(self::RESTORE_DB);
            $this->fixtures->reset();
            $this->clean($this->connection);
            if ($this->userAdded) {
                $this->connection->executeStatement('DELETE FROM user WHERE id = 1');
            }
            $this->connection->close();
            $this->journal->remove();
        }
        foreach ([$this->dump, $this->shopStore] as $file) {
            if ($file !== '' && is_file($file)) {
                unlink($file);
            }
        }
        $this->connection = null;
    }

    private function clean(Connection $connection): void
    {
        $connection->executeStatement("DELETE FROM app_setting WHERE name = 'fiscal_journal.id'");
        $connection->executeStatement('DELETE FROM audit_log WHERE action = ?', [FiscalJournalReset::AUDIT_ACTION]);
    }

    private function sell(int $times = 1, ?JournalPathFixtures $fixtures = null): void
    {
        $fixtures ??= $this->fixtures;
        for ($i = 0; $i < $times; ++$i) {
            $total = JournalPathFixtures::PRICE;
            $fixtures->checkout($fixtures->recorder($this->journal->intents))
                ->createOrder(1, $fixtures->cart() + ['cash_amount' => $total], 'cash', $total);
        }
    }

    private function provision(): FiscalJournalIdentity
    {
        $identity = new FiscalJournalIdentity($this->connection);
        (new FiscalJournalStep($this->journal->locations, $identity, $this->journal->writer, new FiscalJournalStarter($this->connection, $this->journal->writer, $identity)))->run();
        $this->fixtures->guard = new FiscalJournalGuard($this->connection, $this->journal->locations);

        return $identity;
    }

    /** A starter that lets a till sell right after the journal is started, before the step's or the reset's flush. */
    private function starterSellingAfter(Connection $connection, FiscalJournalWriter $writer, FiscalJournalIdentity $identity, \Closure $sale): FiscalJournalStarter
    {
        return new class ($connection, $writer, $identity, $sale) extends FiscalJournalStarter {
            public function __construct(Connection $c, FiscalJournalWriter $w, FiscalJournalIdentity $i, private \Closure $sale)
            {
                parent::__construct($c, $w, $i);
            }

            public function start(?callable $beforeCreate = null, ?callable $afterStore = null): array
            {
                $started = parent::start($beforeCreate, $afterStore);
                ($this->sale)();

                return $started;
            }
        };
    }

    private function shop(Connection $connection, FakeJournalShop $shop): FiscalJournalShopSync
    {
        return new FiscalJournalShopSync($connection, $this->journal->locations, $shop->client(), new FiscalJournalIdentity($connection));
    }

    /** Every line of the primary, raw as the file holds it, posted to the shop (as the outbox would). */
    private function uploadJournal(FakeJournalShop $shop, string $journalId): void
    {
        $client = $shop->client();
        foreach ($this->journal->primary()->journalFiles() as $file) {
            foreach (file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
                $this->assertSame(PsJournalClient::STORED, $client->send($journalId, $raw)['status']);
            }
        }
    }

    public function testTheStepNeverMarksASaleIssuedWhileItsBackfillRunsAsBackfill(): void
    {
        $this->sell(3);
        $identity = new FiscalJournalIdentity($this->connection);
        $starter = $this->starterSellingAfter($this->connection, $this->journal->writer, $identity, function (): void {
            $this->fixtures->guard = new FiscalJournalGuard($this->connection, $this->journal->locations);
            $this->sell();
        });

        (new FiscalJournalStep($this->journal->locations, $identity, $this->journal->writer, $starter))->run();

        $lines = $this->journal->lines();
        $this->assertSame(3, $this->journal->primary()->backfillUntil());
        $this->assertSame([1, 2, 3, 4], array_column($lines, 'seq'));
        $this->assertSame([true, true, true, false], array_column($lines, 'backfill'), 'a sale issued after the journal started (id > backfill_until) is not backfill');
    }

    public function testTheResetNeverMarksASaleIssuedWhileItsBackfillRunsAsBackfill(): void
    {
        $identity = $this->provision();
        $this->sell(2);
        $this->journal->writer->flush();
        $starter = $this->starterSellingAfter($this->connection, $this->journal->writer, $identity, function (): void {
            $this->sell();
        });
        $shop = new FakeJournalShop($this->shopStore);
        $reset = new FiscalJournalReset($this->connection, $this->journal->locations, $this->journal->writer, $starter, $identity, $this->journal->root . '/var/fiscal-replay.lock', shop: $this->shop($this->connection, $shop));

        $result = $reset->run('Diario nuevo');

        $this->assertSame(FiscalJournalResetResult::DONE, $result->status, $result->message);
        $lines = $this->journal->lines();
        $this->assertSame(2, $this->journal->primary()->backfillUntil());
        $this->assertSame([true, true, false], array_column($lines, 'backfill'));
    }

    /**
     * The blocker's twin found by the final verification: an older dump restored on a
     * new server without the journal folders. Before the fix the reset went through
     * and the next sale reused a number the shop already held.
     */
    public function testAfterAnOlderRestoreWithTheJournalFoldersLostTheResetIsRefusedAndNoNumberRepeats(): void
    {
        if (!JournalRestore::available()) {
            $this->markTestSkipped('MySQL test container unavailable for the restore');
        }
        $this->provision();
        $this->sell(2);
        $this->journal->writer->flush();
        $this->dump = JournalRestore::dump($this->connection);
        $this->sell(2);
        $this->journal->writer->flush();
        $journalId = (string) $this->journal->primary()->journalId();
        $shop = new FakeJournalShop($this->shopStore);
        $this->uploadJournal($shop, $journalId);
        $issued = $this->connection->fetchFirstColumn('SELECT number FROM fiscal_record ORDER BY id');
        $this->assertCount(4, $issued);

        // New server: the database restored from the older dump, the journal folders not copied.
        $this->restored = JournalRestore::restore($this->dump, self::RESTORE_DB);
        exec('rm -rf ' . escapeshellarg($this->journal->root . '/backups') . ' ' . escapeshellarg($this->journal->root . '/var/fiscal-journal'));
        $this->assertSame(FiscalJournalGuard::JOURNAL_MISSING, (new FiscalJournalGuard($this->restored, $this->journal->locations))->check()['state']);
        $identity = new FiscalJournalIdentity($this->restored);
        $writer = new FiscalJournalWriter($this->restored, $this->journal->locations, $this->journal->intents);
        $reset = fn (?FakeJournalShop $with): FiscalJournalReset => new FiscalJournalReset(
            $this->restored, $this->journal->locations, $writer, new FiscalJournalStarter($this->restored, $writer, $identity), $identity,
            $this->journal->root . '/var/fiscal-replay.lock', shop: $with !== null ? $this->shop($this->restored, $with) : null,
        );

        // The shop cannot be asked (not configured, down): refused, nothing written.
        $down = new FakeJournalShop($this->shopStore);
        $down->mode = FakeJournalShop::DOWN;
        foreach ([null, $down] as $unreachable) {
            $result = $reset($unreachable)->run('Servidor nuevo');
            $this->assertSame(FiscalJournalResetResult::REFUSED, $result->status, $result->message);
            $this->assertSame(3, $result->exitCode());
            $this->assertStringContainsString('--shop-unavailable', $result->message);
        }
        // The shop answers and holds the two later documents: refused, naming the replay and the guide.
        $result = $reset($shop)->run('Servidor nuevo');
        $this->assertSame(FiscalJournalResetResult::REFUSED, $result->status, $result->message);
        $this->assertSame(3, $result->exitCode());
        $this->assertStringContainsString($issued[3], $result->message);
        $this->assertStringContainsString('tpv:fiscal:replay', $result->message);
        $this->assertStringContainsString('docs/diario-fiscal.md', $result->message);

        $this->assertDirectoryDoesNotExist($this->journal->root . '/backups/fiscal-journal', 'a refusal writes nothing');
        $this->assertDirectoryDoesNotExist($this->journal->root . '/var/fiscal-journal');
        $this->assertSame($journalId, $identity->journalId());
        $this->assertFalse($this->restored->fetchOne('SELECT 1 FROM audit_log WHERE action = ?', [FiscalJournalReset::AUDIT_ACTION]));

        // The till stays blocked: no number is issued, so none can repeat.
        $restoredFixtures = new JournalPathFixtures($this->restored, fn (string $class) => $this->createMock($class));
        $restoredFixtures->guard = new FiscalJournalGuard($this->restored, $this->journal->locations);
        try {
            $this->sell(1, $restoredFixtures);
            $this->fail('the till must refuse while the journal is missing');
        } catch (FiscalJournalBehindException) {
        }
        $this->assertSame(\array_slice($issued, 0, 2), $this->restored->fetchFirstColumn('SELECT number FROM fiscal_record ORDER BY id'));
    }

    public function testTheShopCopyJobKeepsRunningWhileBlockedAndTheReplayStillRestoresEverySale(): void
    {
        if (!JournalRestore::available()) {
            $this->markTestSkipped('MySQL test container unavailable for the restore');
        }
        $this->provision();
        $this->sell(2);
        $this->journal->writer->flush();
        $this->dump = JournalRestore::dump($this->connection);
        $this->sell(3);
        $this->journal->writer->flush();
        $this->restored = JournalRestore::restore($this->dump, self::RESTORE_DB);
        $this->assertSame(FiscalJournalGuard::BEHIND_JOURNAL, (new FiscalJournalGuard($this->restored, $this->journal->locations))->check()['state']);

        // The every-minute journal job keeps running while blocked, with the shop unreachable.
        $down = new FakeJournalShop($this->shopStore);
        $down->mode = FakeJournalShop::DOWN;
        $sync = $this->shop($this->restored, $down);
        for ($minute = 0; $minute < 3; ++$minute) {
            $sync->run(new \DateTimeImmutable("+{$minute} minutes"));
        }

        $replay = new FiscalReplay($this->restored, $this->journal->locations, $this->journal->root . '/var/fiscal-replay.lock');
        $this->assertSame(FiscalReplayResult::PENDING, $replay->run(false)->status);
        $done = $replay->run(true);

        $this->assertSame(FiscalReplayResult::DONE, $done->status, (string) $done->message);
        $this->assertSame(FiscalJournalGuard::OK, (new FiscalJournalGuard($this->restored, $this->journal->locations))->check()['state']);
        $this->assertSame(5, (int) $this->restored->fetchOne("SELECT COUNT(DISTINCT order_id) FROM outbox WHERE type = 'sale'"), 'every sale keeps its upload row');
        $this->assertSame(5, (int) $this->restored->fetchOne('SELECT COUNT(*) FROM fiscal_record'));
    }

    /** The dump restored as the database of a new server whose journal folders were not copied. */
    private function restoreOnANewServer(): JournalPathFixtures
    {
        $this->restored = JournalRestore::restore($this->dump, self::RESTORE_DB);
        exec('rm -rf ' . escapeshellarg($this->journal->root . '/backups') . ' ' . escapeshellarg($this->journal->root . '/var/fiscal-journal'));
        $fixtures = new JournalPathFixtures($this->restored, fn (string $class) => $this->createMock($class));
        $fixtures->guard = new FiscalJournalGuard($this->restored, $this->journal->locations);

        return $fixtures;
    }

    private function resetOn(Connection $connection, FakeJournalShop $shop): FiscalJournalReset
    {
        $identity = new FiscalJournalIdentity($connection);
        $writer = new FiscalJournalWriter($connection, $this->journal->locations, $this->journal->intents);

        return new FiscalJournalReset($connection, $this->journal->locations, $writer, new FiscalJournalStarter($connection, $writer, $identity), $identity, $this->journal->root . '/var/fiscal-replay.lock', shop: $this->shop($connection, $shop));
    }

    /** @param list<string> $issued */
    private function assertNoNumberIsIssuedAgain(JournalPathFixtures $fixtures, array $issued): void
    {
        $before = $this->restored->fetchFirstColumn('SELECT number FROM fiscal_record ORDER BY id');
        try {
            $this->sell(1, $fixtures);
        } catch (FiscalJournalBehindException) {
        }
        $after = $this->restored->fetchFirstColumn('SELECT number FROM fiscal_record ORDER BY id');
        foreach (array_diff($after, $before) as $number) {
            $this->assertNotContains($number, $issued, 'a number already issued (held by the shop) was issued again');
        }
    }

    /**
     * Recheck P2c-ii: a reset in the history (J1 then J2), a dump taken under J1
     * restored on a server without the journal folders. Asking the shop about J1 only
     * found nothing; it is asked for every journal now.
     */
    public function testAfterAResetInTheHistoryAnOlderDumpIsBehindTheShopUnderTheLaterJournal(): void
    {
        if (!JournalRestore::available()) {
            $this->markTestSkipped('MySQL test container unavailable for the restore');
        }
        $this->provision();
        $this->sell(2);
        $this->journal->writer->flush();
        $shop = new FakeJournalShop($this->shopStore);
        $j1 = (string) $this->journal->primary()->journalId();
        $this->uploadJournal($shop, $j1);
        $this->dump = JournalRestore::dump($this->connection);
        $this->assertSame(FiscalJournalResetResult::DONE, $this->resetOn($this->connection, $shop)->run('Reinicio del administrador')->status);
        $j2 = (string) $this->journal->primary()->journalId();
        $this->sell(2);
        $this->journal->writer->flush();
        $this->uploadJournal($shop, $j2);
        $issued = $this->connection->fetchFirstColumn('SELECT number FROM fiscal_record ORDER BY id');

        $fixtures = $this->restoreOnANewServer();
        $this->assertSame($j1, (new FiscalJournalIdentity($this->restored))->journalId());

        $result = $this->resetOn($this->restored, $shop)->run('Servidor nuevo');
        $this->assertSame(FiscalJournalResetResult::REFUSED, $result->status, $result->message);
        $this->assertStringContainsString($issued[3], $result->message);
        // The every-minute task asks the shop for every journal: the till is blocked.
        $this->shop($this->restored, $shop)->run(new \DateTimeImmutable());
        $this->assertSame(FiscalJournalGuard::BEHIND_SHOP, (new FiscalJournalGuard($this->restored, $this->journal->locations))->check()['state']);
        $this->assertNoNumberIsIssuedAgain($fixtures, $issued);
    }

    /** Recheck P2c-ii': a setting the shop never heard of; "nothing under that id" is no confirmation. */
    public function testASettingTheShopNeverHeardOfIsNoConfirmation(): void
    {
        if (!JournalRestore::available()) {
            $this->markTestSkipped('MySQL test container unavailable for the restore');
        }
        $this->provision();
        $this->sell(2);
        $this->journal->writer->flush();
        $this->dump = JournalRestore::dump($this->connection);
        $this->sell(2);
        $this->journal->writer->flush();
        $shop = new FakeJournalShop($this->shopStore);
        $this->uploadJournal($shop, (string) $this->journal->primary()->journalId());
        $issued = $this->connection->fetchFirstColumn('SELECT number FROM fiscal_record ORDER BY id');

        $fixtures = $this->restoreOnANewServer();
        $this->restored->executeStatement("UPDATE app_setting SET value = ? WHERE name = 'fiscal_journal.id'", ['0b8f6a1e-0000-4000-8000-000000000001']);

        $result = $this->resetOn($this->restored, $shop)->run('Servidor nuevo');
        $this->assertSame(FiscalJournalResetResult::REFUSED, $result->status, $result->message);
        $this->assertStringContainsString($issued[3], $result->message);
        $this->shop($this->restored, $shop)->run(new \DateTimeImmutable());
        $this->assertSame(FiscalJournalGuard::BEHIND_SHOP, (new FiscalJournalGuard($this->restored, $this->journal->locations))->check()['state']);
        $this->assertNoNumberIsIssuedAgain($fixtures, $issued);
    }

    /**
     * Recheck P2c-i / case (iii): a database from before the journal existed (no
     * setting), the folders lost, the shop holding the journal's lines. The provisioning
     * step re-run by an upgrade used to start a fresh journal and repeat a number.
     */
    public function testADatabaseFromBeforeTheJournalIsBehindTheShopAndTheStepStartsNoJournal(): void
    {
        if (!JournalRestore::available()) {
            $this->markTestSkipped('MySQL test container unavailable for the restore');
        }
        $this->sell(2);
        $this->dump = JournalRestore::dump($this->connection);
        $this->provision();
        $this->sell(2);
        $this->journal->writer->flush();
        $shop = new FakeJournalShop($this->shopStore);
        $this->uploadJournal($shop, (string) $this->journal->primary()->journalId());
        $issued = $this->connection->fetchFirstColumn('SELECT number FROM fiscal_record ORDER BY id');

        $fixtures = $this->restoreOnANewServer();
        $this->assertNull((new FiscalJournalIdentity($this->restored))->journalId());

        // The provisioning step (an upgrade, an install re-run) asks the shop first.
        $identity = new FiscalJournalIdentity($this->restored);
        $writer = new FiscalJournalWriter($this->restored, $this->journal->locations, $this->journal->intents);
        $step = new FiscalJournalStep($this->journal->locations, $identity, $writer, new FiscalJournalStarter($this->restored, $writer, $identity),
            FiscalJournalStep::BACKFILL_LINES_PER_RUN, $this->shop($this->restored, $shop), new FiscalJournalGuard($this->restored, $this->journal->locations));
        try {
            $step->run();
            $this->fail('the step must not start a journal');
        } catch (\VenPosTPV\Service\Provisioning\ProvisioningException $e) {
            $this->assertStringContainsString($issued[3], $e->getMessage());
            $this->assertStringContainsString('docs/diario-fiscal.md', $e->getMessage());
            $this->assertStringContainsString('--accept-shop-ahead', $e->getMessage());
        }
        $this->assertNull($this->journal->primary()->journalId());
        $this->assertNull($identity->journalId());
        $this->assertSame(FiscalJournalGuard::BEHIND_SHOP, (new FiscalJournalGuard($this->restored, $this->journal->locations))->check()['state']);
        $this->assertSame(FiscalJournalResetResult::REFUSED, $this->resetOn($this->restored, $shop)->run('Servidor nuevo')->status);
        $this->assertNoNumberIsIssuedAgain($fixtures, $issued);
        $this->assertSame(\array_slice($issued, 0, 2), $this->restored->fetchFirstColumn('SELECT number FROM fiscal_record ORDER BY id'));
    }

    /** Decision C end to end: a deliberate fresh start against a shop holding another install's journal. */
    public function testAReinstallWithAnEmptyDatabaseStartsOverOnlyWithAcceptShopAhead(): void
    {
        if (!JournalRestore::available()) {
            $this->markTestSkipped('MySQL test container unavailable for the restore');
        }
        $this->dump = JournalRestore::dump($this->connection);
        $this->provision();
        $this->sell(2);
        $this->journal->writer->flush();
        $shop = new FakeJournalShop($this->shopStore);
        $previous = (string) $this->journal->primary()->journalId();
        $this->uploadJournal($shop, $previous);

        // A reinstall: the empty database, no folders.
        $this->restoreOnANewServer();
        $result = $this->resetOn($this->restored, $shop)->run('Reinstalación');
        $this->assertSame(FiscalJournalResetResult::REFUSED, $result->status, $result->message);

        $result = $this->resetOn($this->restored, $shop)->run('Reinstalación desde cero', null, false, true);

        $this->assertSame(FiscalJournalResetResult::DONE, $result->status, $result->message);
        $this->assertSame([$previous], (new FiscalJournalIdentity($this->restored))->retired());
        $audit = json_decode((string) $this->restored->fetchOne('SELECT payload FROM audit_log WHERE action = ?', [FiscalJournalReset::AUDIT_ACTION]), true);
        $this->assertSame([$previous], $audit['accept_shop_ahead']['retired_journals']);
        // The minute job stores the shop's answer again: the retired journal never blocks.
        $this->shop($this->restored, $shop)->run(new \DateTimeImmutable());
        $this->assertSame(FiscalJournalGuard::OK, (new FiscalJournalGuard($this->restored, $this->journal->locations))->check()['state']);
    }
}

