<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIdentity;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalReset;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalResetResult;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalStarter;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalStep;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalWriter;

/**
 * tpv:fiscal:journal:reset (fiscal-journal A6, decisions in the Log): a new journal
 * from the current database, the old files archived byte for byte (never deleted),
 * an audit_log record, every document backfilled; refused while the database is
 * behind a healthy journal or behind the shop. On SQLite.
 */
final class FiscalJournalResetTest extends TestCase
{
    private const LOST = '0b4e8d0e-5d0c-4d6a-9f00-1a2b3c4d5e6f';

    private string $root;
    private Connection $connection;
    private FiscalJournalLocations $locations;
    private FiscalJournalWriter $writer;
    private FiscalJournalIdentity $identity;

    protected function setUp(): void
    {
        $this->root = JournalLines::temporaryDirectory('reset');
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->root . '/db.sqlite']);
        $this->connection->executeStatement('CREATE TABLE fiscal_record (id INTEGER PRIMARY KEY AUTOINCREMENT, record_type VARCHAR(10) NOT NULL, invoice_type VARCHAR(2) NOT NULL, issuer_nif VARCHAR(16) NOT NULL, number VARCHAR(60) NOT NULL, issue_date VARCHAR(10) NOT NULL, tax_total VARCHAR(16) NOT NULL, total VARCHAR(16) NOT NULL, previous_hash VARCHAR(64) NULL, hash VARCHAR(64) NOT NULL, generated_at VARCHAR(25) NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE fiscal_chain (id INTEGER PRIMARY KEY, last_record_id INTEGER NULL, last_hash VARCHAR(64) NULL)');
        $this->connection->executeStatement('INSERT INTO fiscal_chain (id, last_record_id, last_hash) VALUES (1, NULL, NULL)');
        $this->connection->executeStatement('CREATE TABLE app_setting (name VARCHAR(100) PRIMARY KEY, value TEXT)');
        $this->connection->executeStatement('CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, terminal_id INTEGER NULL, action VARCHAR(64) NOT NULL, target VARCHAR(128) NULL, payload TEXT NULL, ip VARCHAR(45) NULL, created_at DATETIME NOT NULL)');
        $this->locations = new FiscalJournalLocations($this->root . '/backups/fiscal-journal', $this->root . '/var/fiscal-journal');
        $this->writer = FiscalJournalStepTest::writer($this->connection, $this->locations);
        $this->identity = new FiscalJournalIdentity($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        JournalLines::remove($this->root);
    }

    private function reset(): FiscalJournalReset
    {
        $starter = new FiscalJournalStarter($this->connection, $this->writer, $this->identity);

        return new FiscalJournalReset(
            $this->connection,
            $this->locations,
            $this->writer,
            $starter,
            $this->identity,
            $this->root . '/var/fiscal-replay.lock',
            new FiscalJournalGuard($this->connection, $this->locations, null, $this->identity),
        );
    }

    /** An install with $n documents, journaled (the provisioning step), the last $later issued after it. */
    private function journaled(int $n, int $later = 0): string
    {
        for ($i = 1; $i <= $n - $later; ++$i) {
            FiscalJournalStepTest::issue($this->connection, $i);
        }
        $step = new FiscalJournalStep($this->locations, $this->identity, $this->writer, new FiscalJournalStarter($this->connection, $this->writer, $this->identity));
        $step->run();
        for ($i = $n - $later + 1; $i <= $n; ++$i) {
            FiscalJournalStepTest::issue($this->connection, $i);
        }
        $this->writer->flush();
        $this->locations->primary()->writeState(['status' => 'ok']);

        return (string) $this->identity->journalId();
    }

    /** As a restore of a backup taken before the last $count documents. */
    private function restoreBefore(int $count): void
    {
        $last = (int) $this->connection->fetchOne('SELECT MAX(id) FROM fiscal_record');
        $this->connection->executeStatement('DELETE FROM fiscal_record WHERE id > ?', [$last - $count]);
        $head = $this->connection->fetchAssociative('SELECT id, hash FROM fiscal_record ORDER BY id DESC LIMIT 1');
        $this->connection->update('fiscal_chain', ['last_record_id' => $head['id'] ?? null, 'last_hash' => $head['hash'] ?? null], ['id' => 1]);
    }

    /**
     * Every file of a journal folder but the ones that stay (.htaccess, journal.lock, archive/), by path, with its bytes' hash.
     *
     * @return array<string, string>
     */
    private function files(string $folder, string $base = ''): array
    {
        $files = [];
        foreach (scandir($folder . $base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || ($base === '' && \in_array($entry, ['.htaccess', 'journal.lock', 'archive'], true))) {
                continue;
            }
            $path = $base . '/' . $entry;
            if (is_dir($folder . $path)) {
                $files += $this->files($folder, $path);
            } else {
                $files[ltrim($path, '/')] = hash_file('sha256', $folder . $path);
            }
        }
        ksort($files);

        return $files;
    }

    /** @return list<string> the archive folders of a journal folder */
    private function archives(string $folder): array
    {
        return array_values(array_map('basename', glob($folder . '/archive/*', \GLOB_ONLYDIR) ?: []));
    }

    /** @return list<array<string, mixed>> */
    private function audit(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id');
    }

    private function assertNothingChanged(string $journalId, array $primary, array $secondary): void
    {
        $this->assertSame($primary, $this->files($this->root . '/backups/fiscal-journal'));
        $this->assertSame($secondary, $this->files($this->root . '/var/fiscal-journal'));
        $this->assertSame([], $this->archives($this->root . '/backups/fiscal-journal'));
        $this->assertSame([], $this->archives($this->root . '/var/fiscal-journal'));
        $this->assertSame($journalId, $this->identity->journalId());
        $this->assertSame([], $this->audit());
    }

    public function testAnUpToDateJournalIsArchivedByteForByteAndANewOneHoldsEveryDocument(): void
    {
        $old = $this->journaled(3, 1);
        $primary = $this->root . '/backups/fiscal-journal';
        $secondary = $this->root . '/var/fiscal-journal';
        $before = ['primary' => $this->files($primary), 'secondary' => $this->files($secondary)];
        $this->assertArrayHasKey('journal-' . (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->format('Y-m') . '.jsonl', $before['primary']);

        $result = $this->reset()->run('Diario restaurado sin la copia de la tienda', new \DateTimeImmutable('2026-11-14 17:05:09 UTC'));

        $this->assertSame(FiscalJournalResetResult::DONE, $result->status, $result->message);
        $this->assertSame(0, $result->exitCode());
        $this->assertSame([$old . '-20261114T170509Z'], $this->archives($primary));
        $this->assertSame($before['primary'], $this->files($primary . '/archive/' . $old . '-20261114T170509Z', ''), 'archived byte for byte');
        $this->assertSame([$old . '-20261114T170509Z'], $this->archives($secondary));
        $this->assertSame($before['secondary'], $this->files($secondary . '/archive/' . $old . '-20261114T170509Z', ''));
        $this->assertSame("Require all denied\n", file_get_contents($primary . '/.htaccess'));

        $new = $this->identity->journalId();
        $this->assertNotNull($new);
        $this->assertNotSame($old, $new);
        $this->assertSame($new, $this->locations->primary()->journalId());
        $this->assertSame($new, $this->locations->secondary()->journalId());
        $this->assertSame($new, $result->journalId);

        $lines = array_values(iterator_to_array($this->locations->primary()->lines(), false));
        $this->assertSame([1, 2, 3], array_column($lines, 'seq'));
        $this->assertSame([true, true, true], array_column($lines, 'backfill'));
        $this->assertSame(3, $result->backfilled);

        $audit = $this->audit();
        $this->assertCount(1, $audit);
        $this->assertSame('fiscal_journal_reset', $audit[0]['action']);
        $this->assertSame($new, $audit[0]['target']);
        $payload = json_decode((string) $audit[0]['payload'], true);
        $this->assertSame('Diario restaurado sin la copia de la tienda', $payload['reason']);
        $this->assertSame($old, $payload['previous_journal_id']);
        $this->assertSame(FiscalJournalGuard::OK, $payload['guard']);
        $this->assertSame(3, $payload['backfill_until']);
        $this->assertSame(['backups/fiscal-journal/archive/' . $old . '-20261114T170509Z', 'var/fiscal-journal/archive/' . $old . '-20261114T170509Z'], $payload['archived']);

        $this->assertSame(FiscalJournalGuard::OK, (new FiscalJournalGuard($this->connection, $this->locations))->check()['state']);
    }

    public function testItRefusesWhileTheDatabaseIsBehindAHealthyJournal(): void
    {
        $id = $this->journaled(4);
        $this->restoreBefore(2);
        $primary = $this->files($this->root . '/backups/fiscal-journal');
        $secondary = $this->files($this->root . '/var/fiscal-journal');

        $result = $this->reset()->run('Quiero empezar de nuevo');

        $this->assertSame(FiscalJournalResetResult::REFUSED, $result->status);
        $this->assertSame(3, $result->exitCode());
        $this->assertStringContainsString('A26000004', $result->message);
        $this->assertStringContainsString('tpv:fiscal:replay', $result->message);
        $this->assertNothingChanged($id, $primary, $secondary);
    }

    public function testItRefusesWhileTheShopHoldsADocumentTheDatabaseLacks(): void
    {
        $id = $this->journaled(2);
        $this->locations->primary()->writeShopHead(['v' => 1, 'heads' => [$id => [
            'journal_id' => $id, 'fetched_at' => '2026-11-14T17:00:00Z', 'last_seq' => 3, 'contiguous_seq' => 3,
            'series' => ['A26' => ['number' => 'A26000003', 'hash' => str_repeat('AB', 32), 'seq' => 3]],
        ]]]);
        $primary = $this->files($this->root . '/backups/fiscal-journal');
        $secondary = $this->files($this->root . '/var/fiscal-journal');

        $result = $this->reset()->run('Quiero empezar de nuevo');

        $this->assertSame(FiscalJournalResetResult::REFUSED, $result->status);
        $this->assertSame(3, $result->exitCode());
        $this->assertStringContainsString('tienda', $result->message);
        $this->assertStringContainsString('A26000003', $result->message);
        $this->assertNothingChanged($id, $primary, $secondary);
    }

    public function testADamagedJournalCanBeResetEvenWhileTheDatabaseIsBehindIt(): void
    {
        $old = $this->journaled(4);
        $this->restoreBefore(1);
        $file = $this->locations->primary()->journalFiles()[0];
        $content = (string) file_get_contents($file);
        // Line 2 altered: not the last one, so it is damage, never repaired.
        file_put_contents($file, preg_replace('/A26000002/', 'A26000092', $content, 1));
        $damagedBytes = hash_file('sha256', $file);

        $result = $this->reset()->run('Diario dañado en el disco');

        $this->assertSame(FiscalJournalResetResult::DONE, $result->status, $result->message);
        $archive = $this->root . '/backups/fiscal-journal/archive/' . $this->archives($this->root . '/backups/fiscal-journal')[0];
        $this->assertSame($damagedBytes, hash_file('sha256', $archive . '/' . basename($file)));
        $lines = array_values(iterator_to_array($this->locations->primary()->lines(), false));
        $this->assertSame([1, 2, 3], array_column($lines, 'seq'));
        $payload = json_decode((string) $this->audit()[0]['payload'], true);
        $this->assertSame($old, $payload['previous_journal_id']);
        $this->assertSame(FiscalJournalGuard::BEHIND_JOURNAL, $payload['guard']);
        $this->assertNotNull($payload['damaged']);
    }

    public function testALostJournalCanBeReset(): void
    {
        FiscalJournalStepTest::issue($this->connection, 1);
        FiscalJournalStepTest::issue($this->connection, 2);
        $this->identity->store(self::LOST);
        $this->assertSame(FiscalJournalGuard::JOURNAL_MISSING, (new FiscalJournalGuard($this->connection, $this->locations))->check()['state']);

        $result = $this->reset()->run('Servidor nuevo, el diario no se pudo copiar');

        $this->assertSame(FiscalJournalResetResult::DONE, $result->status, $result->message);
        $this->assertNotSame(self::LOST, $this->identity->journalId());
        $this->assertSame(2, $result->backfilled);
        $payload = json_decode((string) $this->audit()[0]['payload'], true);
        $this->assertSame(self::LOST, $payload['previous_journal_id']);
        $this->assertSame(FiscalJournalGuard::JOURNAL_MISSING, $payload['guard']);
        $this->assertSame([], $payload['archived']);
        $this->assertSame(FiscalJournalGuard::OK, (new FiscalJournalGuard($this->connection, $this->locations))->check()['state']);
    }

    public function testAnEmptyReasonIsRefusedAndNothingChanges(): void
    {
        $id = $this->journaled(1);
        $primary = $this->files($this->root . '/backups/fiscal-journal');
        $secondary = $this->files($this->root . '/var/fiscal-journal');

        $result = $this->reset()->run("  \n ");

        $this->assertSame(FiscalJournalResetResult::INVALID, $result->status);
        $this->assertSame(2, $result->exitCode());
        $this->assertNothingChanged($id, $primary, $secondary);
    }

    public function testItDoesNotRunWhileAReplayIsRunning(): void
    {
        $id = $this->journaled(1);
        $primary = $this->files($this->root . '/backups/fiscal-journal');
        $secondary = $this->files($this->root . '/var/fiscal-journal');
        $lock = fopen($this->root . '/var/fiscal-replay.lock', 'c');
        $this->assertTrue(flock($lock, \LOCK_EX));

        try {
            $result = $this->reset()->run('Motivo');
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }

        $this->assertSame(FiscalJournalResetResult::ERROR, $result->status);
        $this->assertSame(1, $result->exitCode());
        $this->assertNothingChanged($id, $primary, $secondary);
    }

    public function testTwoResetsInTheSameSecondKeepBothArchives(): void
    {
        $this->journaled(1);
        $at = new \DateTimeImmutable('2026-11-14 17:05:09 UTC');
        $this->assertSame(FiscalJournalResetResult::DONE, $this->reset()->run('Primero', $at)->status);
        $this->assertSame(FiscalJournalResetResult::DONE, $this->reset()->run('Segundo', $at)->status);

        $archives = $this->archives($this->root . '/backups/fiscal-journal');
        $this->assertCount(2, $archives);
        $this->assertCount(2, $this->audit());
    }
}
