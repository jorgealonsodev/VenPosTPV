<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalBehindException;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalFlushResult;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIdentity;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalReset;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalResetResult;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalStarter;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalStep;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalWriter;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * tpv:fiscal:journal:reset on MySQL (fiscal-journal A6): refused after a real
 * dump/restore while the database is behind a healthy journal; a lost journal is
 * replaced by a new one holding every document, the till sells again and the
 * journal continues; the old files are archived byte for byte; audit_log records it.
 */
final class FiscalJournalResetMySqlIntegrationTest extends TestCase
{
    private const RESTORE_DB = 'venposjournal_reset';

    private ?Connection $connection = null;
    private ?Connection $restored = null;
    private JournalPathFixtures $fixtures;
    private JournalHarness $journal;
    private string $dump = '';

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
        $this->journal = new JournalHarness($this->connection);
    }

    protected function tearDown(): void
    {
        $this->restored?->close();
        $this->restored = null;
        if ($this->connection !== null) {
            JournalRestore::drop(self::RESTORE_DB);
            $this->fixtures->reset();
            $this->clean($this->connection);
            $this->connection->close();
            $this->journal->remove();
        }
        if ($this->dump !== '' && is_file($this->dump)) {
            unlink($this->dump);
        }
        $this->connection = null;
    }

    private function clean(Connection $connection): void
    {
        $connection->executeStatement("DELETE FROM app_setting WHERE name = 'fiscal_journal.id'");
        $connection->executeStatement('DELETE FROM audit_log WHERE action = ?', [FiscalJournalReset::AUDIT_ACTION]);
    }

    private function provision(): void
    {
        $identity = new FiscalJournalIdentity($this->connection);
        (new FiscalJournalStep($this->journal->locations, $identity, $this->journal->writer, new FiscalJournalStarter($this->connection, $this->journal->writer, $identity)))->run();
        $this->fixtures->guard = new FiscalJournalGuard($this->connection, $this->journal->locations);
    }

    private function sell(int $times = 1): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $total = JournalPathFixtures::PRICE;
            $this->fixtures->checkout($this->fixtures->recorder($this->journal->intents))
                ->createOrder(1, $this->fixtures->cart() + ['cash_amount' => $total], 'cash', $total);
        }
    }

    private function reset(Connection $connection, ?FiscalJournalLocations $locations = null): FiscalJournalReset
    {
        $locations ??= $this->journal->locations;
        $writer = new FiscalJournalWriter($connection, $locations, $this->journal->intents);
        $identity = new FiscalJournalIdentity($connection);

        return new FiscalJournalReset($connection, $locations, $writer, new FiscalJournalStarter($connection, $writer, $identity), $identity, $this->journal->root . '/var/fiscal-replay.lock');
    }

    /** @return array<string, string> every file below $folder (but .htaccess and journal.lock), by relative path, with its hash */
    private static function files(string $folder): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr((string) $file->getPathname(), \strlen($folder) + 1);
            if ($file->isFile() && !\in_array($relative, ['.htaccess', 'journal.lock'], true) && !str_starts_with($relative, 'archive/')) {
                $files[$relative] = hash_file('sha256', (string) $file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }

    public function testAfterARestoreBehindAHealthyJournalItIsRefusedAndNothingChanges(): void
    {
        $this->provision();
        $this->sell(2);
        $this->assertSame(FiscalJournalFlushResult::OK, $this->journal->writer->flush()->status);
        $this->dump = JournalRestore::dump($this->connection);
        $this->sell(2);
        $this->assertSame(FiscalJournalFlushResult::OK, $this->journal->writer->flush()->status);
        $this->restored = JournalRestore::restore($this->dump, self::RESTORE_DB);
        $journalId = $this->journal->primary()->journalId();
        $primary = self::files($this->journal->primary()->directory());
        $secondary = self::files($this->journal->secondary()->directory());

        $result = $this->reset($this->restored)->run('Empezar de nuevo');

        $this->assertSame(FiscalJournalResetResult::REFUSED, $result->status);
        $this->assertSame(3, $result->exitCode());
        $this->assertStringContainsString('tpv:fiscal:replay', $result->message);
        $this->assertSame($primary, self::files($this->journal->primary()->directory()));
        $this->assertSame($secondary, self::files($this->journal->secondary()->directory()));
        $this->assertDirectoryDoesNotExist($this->journal->primary()->directory() . '/archive');
        $this->assertSame($journalId, (new FiscalJournalIdentity($this->restored))->journalId());
        $this->assertSame(0, (int) $this->restored->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ?', [FiscalJournalReset::AUDIT_ACTION]));
        $this->assertSame(FiscalJournalGuard::BEHIND_JOURNAL, (new FiscalJournalGuard($this->restored, $this->journal->locations))->check()['state']);
    }

    public function testALostJournalIsReplacedAndTheTillSellsAgain(): void
    {
        $this->provision();
        $this->sell(3);
        $this->assertSame(FiscalJournalFlushResult::OK, $this->journal->writer->flush()->status);
        $old = (string) $this->journal->primary()->journalId();
        // A new server the journal folders were not copied to.
        $moved = $this->journal->root . '-lost';
        rename($this->journal->root . '/backups', $moved);
        exec('rm -rf ' . escapeshellarg($this->journal->root . '/var/fiscal-journal'));
        try {
            $guard = new FiscalJournalGuard($this->connection, $this->journal->locations);
            $this->assertSame(FiscalJournalGuard::JOURNAL_MISSING, $guard->check()['state']);
            try {
                $this->sell();
                $this->fail('the till must refuse while the journal is missing');
            } catch (FiscalJournalBehindException) {
            }

            $result = $this->reset($this->connection)->run('Servidor nuevo sin la carpeta del diario');
        } finally {
            exec('rm -rf ' . escapeshellarg($moved));
        }

        $this->assertSame(FiscalJournalResetResult::DONE, $result->status, $result->message);
        $this->assertSame(3, $result->backfilled);
        $new = (new FiscalJournalIdentity($this->connection))->journalId();
        $this->assertNotSame($old, $new);
        $this->assertSame($new, $this->journal->primary()->journalId());
        $lines = $this->journal->lines();
        $this->assertSame([1, 2, 3], array_column($lines, 'seq'));
        $this->assertSame([true, true, true], array_column($lines, 'backfill'));
        $audit = $this->connection->fetchAssociative('SELECT * FROM audit_log WHERE action = ?', [FiscalJournalReset::AUDIT_ACTION]);
        $this->assertIsArray($audit);
        $this->assertSame($new, $audit['target']);
        $payload = json_decode((string) $audit['payload'], true);
        $this->assertSame('Servidor nuevo sin la carpeta del diario', $payload['reason']);
        $this->assertSame($old, $payload['previous_journal_id']);
        $this->assertSame(FiscalJournalGuard::JOURNAL_MISSING, $payload['guard']);

        $this->assertSame(FiscalJournalGuard::OK, (new FiscalJournalGuard($this->connection, $this->journal->locations))->check()['state']);
        $this->sell();
        $this->assertSame(FiscalJournalFlushResult::OK, $this->journal->writer->flush()->status);
        $lines = $this->journal->lines();
        $this->assertSame(4, $lines[3]['seq']);
        $this->assertFalse($lines[3]['backfill']);
    }

    public function testAnUpToDateJournalIsArchivedByteForByte(): void
    {
        $this->provision();
        $this->sell(2);
        $this->assertSame(FiscalJournalFlushResult::OK, $this->journal->writer->flush()->status);
        $old = (string) $this->journal->primary()->journalId();
        $before = self::files($this->journal->primary()->directory());

        $result = $this->reset($this->connection)->run('Cambio de servidor');

        $this->assertSame(FiscalJournalResetResult::DONE, $result->status, $result->message);
        $archive = glob($this->journal->primary()->directory() . '/archive/' . $old . '-*', \GLOB_ONLYDIR) ?: [];
        $this->assertCount(1, $archive);
        $this->assertSame($before, self::files($archive[0]));
        $this->assertSame(2, $result->backfilled);
    }
}
