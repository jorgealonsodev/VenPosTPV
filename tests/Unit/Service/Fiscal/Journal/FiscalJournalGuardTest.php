<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\FiscalHash;
use VenPosTPV\Service\Fiscal\Journal\FiscalDocumentSnapshot;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalBehindException;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIntents;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalWriter;

/**
 * The guard (fiscal-journal S5, D5, D7): the till refuses to issue while the
 * database is behind the journal (a restore), and never because the journal is
 * behind the database (normal lag) or cannot be read. On SQLite; the
 * dump/restore and the concurrency cases run on MySQL.
 */
final class FiscalJournalGuardTest extends TestCase
{
    private string $root;
    private Connection $connection;
    private FiscalJournalLocations $locations;
    private FiscalJournalWriter $writer;

    protected function setUp(): void
    {
        $this->root = JournalLines::temporaryDirectory('guard');
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->root . '/db.sqlite']);
        $this->connection->executeStatement('CREATE TABLE fiscal_record (id INTEGER PRIMARY KEY AUTOINCREMENT, record_type VARCHAR(10) NOT NULL, invoice_type VARCHAR(2) NOT NULL, issuer_nif VARCHAR(16) NOT NULL, number VARCHAR(60) NOT NULL, issue_date VARCHAR(10) NOT NULL, tax_total VARCHAR(16) NOT NULL, total VARCHAR(16) NOT NULL, previous_hash VARCHAR(64) NULL, hash VARCHAR(64) NOT NULL, generated_at VARCHAR(25) NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE fiscal_chain (id INTEGER PRIMARY KEY, last_record_id INTEGER NULL, last_hash VARCHAR(64) NULL)');
        $this->connection->executeStatement('INSERT INTO fiscal_chain (id, last_record_id, last_hash) VALUES (1, NULL, NULL)');
        $this->locations = new FiscalJournalLocations($this->root . '/backups/fiscal-journal', $this->root . '/var/fiscal-journal');
        $snapshot = new class($this->connection) extends FiscalDocumentSnapshot {
            public function build(array $record, ?int $refundMovementId = null): array
            {
                return ['kind' => (string) $record['invoice_type'], 'series' => 'A26', 'counter' => null, 'rows' => []];
            }
        };
        $this->writer = new FiscalJournalWriter($this->connection, $this->locations, new FiscalJournalIntents($this->locations), null, $snapshot);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        JournalLines::remove($this->root);
    }

    private function guard(): FiscalJournalGuard
    {
        return new FiscalJournalGuard($this->connection, $this->locations);
    }

    /** @return array<string, mixed> the record as committed */
    private function issue(int $n, string $total = '12.10'): array
    {
        $previous = $this->connection->fetchOne('SELECT last_hash FROM fiscal_chain WHERE id = 1');
        $record = [
            'record_type' => 'alta',
            'invoice_type' => 'F2',
            'issuer_nif' => 'B00000000',
            'number' => sprintf('A26%06d', $n),
            'issue_date' => '14-11-2026',
            'tax_total' => '2.10',
            'total' => $total,
            'previous_hash' => \is_string($previous) ? $previous : null,
            'generated_at' => sprintf('2026-11-14T18:%02d:00+01:00', $n),
        ];
        $record['hash'] = FiscalHash::alta('B00000000', $record['number'], '14-11-2026', 'F2', '2.10', $total, (string) $record['previous_hash'], $record['generated_at']);
        $this->connection->insert('fiscal_record', $record);
        $this->connection->update('fiscal_chain', ['last_record_id' => (int) $this->connection->lastInsertId(), 'last_hash' => $record['hash']], ['id' => 1]);

        return $record;
    }

    /** Five tickets issued and journaled. */
    private function fiveJournaled(): void
    {
        $this->writer->createJournal();
        foreach (range(1, 5) as $n) {
            $this->issue($n);
        }
        $this->writer->flush();
    }

    /** What a restore of a dump taken after ticket $kept does to the database. */
    private function restoreTo(int $kept): void
    {
        $this->connection->executeStatement('DELETE FROM fiscal_record WHERE id > ?', [$kept]);
        $head = $this->connection->fetchAssociative('SELECT id, hash FROM fiscal_record ORDER BY id DESC LIMIT 1');
        $this->connection->update('fiscal_chain', ['last_record_id' => $head['id'] ?? null, 'last_hash' => $head['hash'] ?? null], ['id' => 1]);
    }

    private function assertRefused(FiscalJournalGuard $guard): FiscalJournalBehindException
    {
        try {
            $guard->assertCanIssue();
        } catch (FiscalJournalBehindException $e) {
            $this->assertSame('Caja bloqueada: la base de datos se ha restaurado desde una copia anterior a los últimos documentos. Avisa al administrador (Ajustes → VERI*FACTU → Diario fiscal).', $e->getMessage());

            return $e;
        }
        $this->fail('the guard let the till issue while the database is behind the journal');
    }

    public function testNoJournalAndAnUpToDateJournalLetTheTillIssue(): void
    {
        $this->issue(1);
        $this->assertSame(FiscalJournalGuard::OK, $this->guard()->check()['state'], 'no journal yet: nothing to compare with');
        $this->guard()->assertCanIssue();

        $this->writer->createJournal();
        $this->writer->flush();
        $this->issue(2);
        $this->guard()->assertCanIssue();
        $this->assertSame(['state' => FiscalJournalGuard::OK, 'missing' => null], $this->guard()->check());
    }

    public function testAJournalBehindTheDatabaseIsNormalLagNeverABlock(): void
    {
        $this->fiveJournaled();
        foreach (range(6, 9) as $n) {
            $this->issue($n);
        }

        $this->guard()->assertCanIssue();
        $this->assertSame(FiscalJournalGuard::OK, $this->guard()->check()['state']);
    }

    public function testADatabaseRestoredBehindTheJournalRefusesToIssue(): void
    {
        $this->fiveJournaled();
        $this->restoreTo(3);

        $e = $this->assertRefused($this->guard());
        $this->assertSame(FiscalJournalGuard::BEHIND_JOURNAL, $e->state);
        $this->assertSame('A26000005', $e->missingNumber);
        $this->assertSame(['state' => FiscalJournalGuard::BEHIND_JOURNAL, 'missing' => ['number' => 'A26000005', 'invoice_type' => 'F2']], $this->guard()->check());

        // Nothing the guard reads is rewritten by the next flush: still refused.
        $this->writer->flush();
        $this->assertRefused($this->guard());
        $this->assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fiscal_record'), 'the guard writes nothing');
    }

    public function testTheSameNumberIssuedAgainWithAnotherRecordIsStillBehind(): void
    {
        $this->fiveJournaled();
        $this->restoreTo(4);
        // A till without the guard sold again after the restore: same number, another record.
        $this->issue(5, '99.00');

        $this->assertRefused($this->guard());
    }

    public function testHeadJsonKeepsTheEvidenceWhenTheLastLinesAreRemoved(): void
    {
        $this->fiveJournaled();
        $file = $this->locations->primary()->journalFiles()[0];
        $lines = explode("\n", rtrim((string) file_get_contents($file), "\n"));
        file_put_contents($file, implode("\n", \array_slice($lines, 0, 3)) . "\n");
        $this->restoreTo(3);

        $e = $this->assertRefused($this->guard());
        $this->assertSame('A26000005', $e->missingNumber, 'head.json still names the last document');
    }

    public function testAConfirmedIntentMissingFromTheDatabaseRefusesAndAPreparedOneDoesNot(): void
    {
        $this->fiveJournaled();
        $record = $this->issue(6);
        $this->restoreTo(5);

        $this->locations->primary()->writeIntent($record['hash'], ['fiscal_record' => $record, 'created_at' => '2026-11-14T18:06:00+01:00', 'state' => FiscalJournalIntents::STATE_PREPARED]);
        $this->guard()->assertCanIssue();

        $this->locations->primary()->writeIntent($record['hash'], ['fiscal_record' => $record, 'created_at' => '2026-11-14T18:06:00+01:00', 'state' => FiscalJournalIntents::STATE_CONFIRMED]);
        $e = $this->assertRefused($this->guard());
        $this->assertSame('A26000006', $e->missingNumber);
    }

    public function testTheSecondaryLinesCountToo(): void
    {
        $this->fiveJournaled();
        chmod($this->root . '/backups/fiscal-journal', 0500);
        try {
            $this->issue(6);
            $this->writer->flush();
            $this->assertCount(1, $this->locations->secondary()->journalFiles(), 'the sixth line went to the secondary');
            $this->restoreTo(5);

            $e = $this->assertRefused($this->guard());
            $this->assertSame('A26000006', $e->missingNumber);
        } finally {
            chmod($this->root . '/backups/fiscal-journal', 0700);
        }
    }

    public function testAJournalThatCannotBeReadOrADatabaseErrorNeverBlocks(): void
    {
        $this->fiveJournaled();
        $this->restoreTo(3);
        chmod($this->root . '/backups/fiscal-journal', 0000);
        try {
            $this->guard()->assertCanIssue();
        } finally {
            chmod($this->root . '/backups/fiscal-journal', 0700);
        }

        $this->connection->executeStatement('DROP TABLE fiscal_record');
        $this->guard()->assertCanIssue();
        $this->assertSame(FiscalJournalGuard::OK, $this->guard()->check()['state']);
    }

    public function testTheReportNamesTheFirstMissingDocumentAndHowManyFollow(): void
    {
        $this->fiveJournaled();
        $this->assertSame(['state' => FiscalJournalGuard::OK, 'missing' => null], $this->guard()->report());

        $this->restoreTo(2);
        $this->assertSame(['state' => FiscalJournalGuard::BEHIND_JOURNAL, 'missing' => ['number' => 'A26000003', 'invoice_type' => 'F2', 'after' => 2]], $this->guard()->report());

        $this->restoreTo(0);
        $this->assertSame(['number' => 'A26000001', 'invoice_type' => 'F2', 'after' => 4], $this->guard()->report()['missing'], 'an empty database misses every line');
    }

    public function testTheReportCannotCountWhenTheDatabaseHeadIsNotInTheJournal(): void
    {
        $this->fiveJournaled();
        $this->restoreTo(3);
        $this->connection->update('fiscal_chain', ['last_hash' => str_repeat('F', 64)], ['id' => 1]);

        $this->assertSame(['number' => 'A26000005', 'invoice_type' => 'F2', 'after' => null], $this->guard()->report()['missing']);
    }

    public function testARefusalIsRememberedOnceForTheResponse(): void
    {
        $this->fiveJournaled();
        $guard = $this->guard();
        $this->assertFalse($guard->takeRefusal());
        $guard->assertCanIssue();
        $this->assertFalse($guard->takeRefusal(), 'an allowed issue is no refusal');

        $this->restoreTo(3);
        try {
            $guard->assertCanIssue();
        } catch (FiscalJournalBehindException) {
        }
        $this->assertTrue($guard->takeRefusal());
        $this->assertFalse($guard->takeRefusal(), 'taken once');
    }
}
