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
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIdentity;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIntents;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalWriter;

/**
 * The shop half of the guard (fiscal-journal D6, D7 `behind_shop`,
 * `journal_missing`, S8): the till refuses to issue only on positive evidence
 * that the shop holds a document the database lacks, read from the shop head the
 * every-minute task stored (`shop-head.json`), or while the journal the database
 * names is gone and the shop has not answered for it yet. A missing, unreadable or
 * foreign shop head is never a block. On SQLite.
 */
final class FiscalJournalGuardShopTest extends TestCase
{
    private string $root;
    private Connection $connection;
    private FiscalJournalLocations $locations;
    private FiscalJournalWriter $writer;

    protected function setUp(): void
    {
        $this->root = JournalLines::temporaryDirectory('guard-shop');
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->root . '/db.sqlite']);
        $this->connection->executeStatement('CREATE TABLE fiscal_record (id INTEGER PRIMARY KEY AUTOINCREMENT, record_type VARCHAR(10) NOT NULL, invoice_type VARCHAR(2) NOT NULL, issuer_nif VARCHAR(16) NOT NULL, number VARCHAR(60) NOT NULL, issue_date VARCHAR(10) NOT NULL, tax_total VARCHAR(16) NOT NULL, total VARCHAR(16) NOT NULL, previous_hash VARCHAR(64) NULL, hash VARCHAR(64) NOT NULL, generated_at VARCHAR(25) NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE fiscal_chain (id INTEGER PRIMARY KEY, last_record_id INTEGER NULL, last_hash VARCHAR(64) NULL)');
        $this->connection->executeStatement('INSERT INTO fiscal_chain (id, last_record_id, last_hash) VALUES (1, NULL, NULL)');
        $this->connection->executeStatement('CREATE TABLE app_setting (name VARCHAR(100) PRIMARY KEY, value TEXT)');
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

    /** @return array<string, mixed> */
    private function issue(int $n): array
    {
        $previous = $this->connection->fetchOne('SELECT last_hash FROM fiscal_chain WHERE id = 1');
        $record = [
            'record_type' => 'alta', 'invoice_type' => 'F2', 'issuer_nif' => 'B00000000', 'number' => sprintf('A26%06d', $n),
            'issue_date' => '14-11-2026', 'tax_total' => '2.10', 'total' => '12.10', 'previous_hash' => \is_string($previous) ? $previous : null,
            'generated_at' => sprintf('2026-11-14T18:%02d:00+01:00', $n),
        ];
        $record['hash'] = FiscalHash::alta('B00000000', $record['number'], '14-11-2026', 'F2', '2.10', '12.10', (string) $record['previous_hash'], $record['generated_at']);
        $this->connection->insert('fiscal_record', $record);
        $this->connection->update('fiscal_chain', ['last_record_id' => (int) $this->connection->lastInsertId(), 'last_hash' => $record['hash']], ['id' => 1]);

        return $record;
    }

    /** @param array<string, array{number: string, hash: string, seq: int}> $series */
    private function shopHead(string $journalId, array $series, string $folder = 'primary'): void
    {
        $location = $folder === 'primary' ? $this->locations->primary() : $this->locations->secondary();
        $location->prepare();
        $heads = $location->readShopHead()['heads'] ?? [];
        $heads[$journalId] = ['journal_id' => $journalId, 'fetched_at' => '2026-11-14T18:10:00+01:00', 'last_seq' => 9, 'contiguous_seq' => 9, 'series' => $series];
        $location->writeShopHead(['v' => 1, 'heads' => $heads]);
    }

    private function refusal(FiscalJournalGuard $guard): FiscalJournalBehindException
    {
        try {
            $guard->assertCanIssue();
        } catch (FiscalJournalBehindException $e) {
            $this->assertSame(FiscalJournalBehindException::MESSAGE, $e->getMessage());

            return $e;
        }
        $this->fail('the guard let the till issue');
    }

    public function testADocumentTheShopHoldsAndTheDatabaseLacksBlocks(): void
    {
        $journalId = $this->writer->createJournal();
        $three = $this->issue(3);
        $this->writer->flush();
        $this->shopHead($journalId, [
            'A26' => ['number' => 'A26000004', 'hash' => strtoupper(hash('sha256', 'not in this database')), 'seq' => 4],
            'PS' => ['number' => 'XKBKNABJK', 'hash' => $three['hash'], 'seq' => 1],
        ]);

        $e = $this->refusal($this->guard());
        $this->assertSame(FiscalJournalGuard::BEHIND_SHOP, $e->state);
        $this->assertSame('A26000004', $e->missingNumber);
        $this->assertSame(['state' => FiscalJournalGuard::BEHIND_SHOP, 'missing' => ['number' => 'A26000004', 'invoice_type' => '']], $this->guard()->check());
        $this->assertSame(['state' => FiscalJournalGuard::BEHIND_SHOP, 'missing' => ['number' => 'A26000004', 'invoice_type' => '', 'after' => null]], $this->guard()->report());
    }

    public function testAShopHeadOfDocumentsInTheDatabaseNeverBlocks(): void
    {
        $journalId = $this->writer->createJournal();
        foreach (range(1, 3) as $n) {
            $last = $this->issue($n);
        }
        $this->writer->flush();
        // The shop is behind the database (normal: the upload follows the journal).
        $this->shopHead($journalId, ['A26' => ['number' => $last['number'], 'hash' => $last['hash'], 'seq' => 3]]);
        $this->issue(4);

        $this->guard()->assertCanIssue();
        $this->assertSame(['state' => FiscalJournalGuard::OK, 'missing' => null], $this->guard()->check());
    }

    public function testAShopHeadInTheSecondaryCountsToo(): void
    {
        $journalId = $this->writer->createJournal();
        $this->issue(1);
        $this->writer->flush();
        $this->shopHead($journalId, ['A26' => ['number' => 'A26000002', 'hash' => strtoupper(hash('sha256', 'gone')), 'seq' => 2]], 'secondary');

        $this->assertSame(FiscalJournalGuard::BEHIND_SHOP, $this->refusal($this->guard())->state);
    }

    public function testTheShopHeadOfAnotherJournalAndAnUnreadableOneNeverBlock(): void
    {
        $this->writer->createJournal();
        $this->issue(1);
        $this->writer->flush();
        // An older journal (before a reset): its documents say nothing about this one.
        $this->shopHead('00000000-0000-4000-8000-000000000001', ['A26' => ['number' => 'A26000009', 'hash' => strtoupper(hash('sha256', 'old')), 'seq' => 9]]);
        $this->guard()->assertCanIssue();

        file_put_contents($this->root . '/backups/fiscal-journal/shop-head.json', '{"v":1,"heads":');
        $this->guard()->assertCanIssue();
        $this->assertSame(FiscalJournalGuard::OK, $this->guard()->check()['state']);
    }

    public function testWithoutTheSettingNoJournalIsAFreshInstallNeverABlock(): void
    {
        $this->issue(1);

        $this->assertSame(FiscalJournalGuard::OK, $this->guard()->check()['state']);
        $this->guard()->assertCanIssue();
    }

    public function testAJournalLostWhileTheDatabaseNamesItBlocksUntilTheShopAnswersForIt(): void
    {
        $journalId = '6f1c2a4e-1b2c-4d3e-8f90-0123456789ab';
        (new FiscalJournalIdentity($this->connection))->store($journalId);
        $two = [$this->issue(1), $this->issue(2)];

        // The folders were lost (a new server): nothing tells whether the database is behind.
        $e = $this->refusal($this->guard());
        $this->assertSame(FiscalJournalGuard::JOURNAL_MISSING, $e->state);
        $this->assertSame(['state' => FiscalJournalGuard::JOURNAL_MISSING, 'missing' => null], $this->guard()->report());

        // The every-minute task asked the shop: it holds nothing the database lacks.
        $this->shopHead($journalId, ['A26' => ['number' => $two[1]['number'], 'hash' => $two[1]['hash'], 'seq' => 2]]);
        $this->guard()->assertCanIssue();

        // ... or it does: the database is behind the shop.
        $this->shopHead($journalId, ['A26' => ['number' => 'A26000003', 'hash' => strtoupper(hash('sha256', 'issued after the backup')), 'seq' => 3]]);
        $this->assertSame(FiscalJournalGuard::BEHIND_SHOP, $this->refusal($this->guard())->state);
    }

    public function testAJournalOfAnotherIdThanTheSettingIsMissingUntilTheShopAnswersForTheSetting(): void
    {
        $this->writer->createJournal();
        $one = $this->issue(1);
        $this->writer->flush();
        $named = '6f1c2a4e-1b2c-4d3e-8f90-0123456789ab';
        (new FiscalJournalIdentity($this->connection))->store($named);

        $this->assertSame(FiscalJournalGuard::JOURNAL_MISSING, $this->refusal($this->guard())->state);

        $this->shopHead($named, ['A26' => ['number' => $one['number'], 'hash' => $one['hash'], 'seq' => 1]]);
        $this->guard()->assertCanIssue();
    }

    public function testTheSettingOfTheJournalInTheFoldersIsNoBlock(): void
    {
        $journalId = $this->writer->createJournal();
        (new FiscalJournalIdentity($this->connection))->store($journalId);
        $this->issue(1);
        $this->writer->flush();

        $this->guard()->assertCanIssue();
        $this->assertSame($journalId, (new FiscalJournalIdentity($this->connection))->journalId());
    }

    public function testADatabaseWithoutTheSettingsTableNeverBlocks(): void
    {
        $this->connection->executeStatement('DROP TABLE app_setting');
        $this->issue(1);

        $this->assertSame(FiscalJournalGuard::OK, $this->guard()->check()['state']);
    }

    public function testTheJournalHalfSpeaksFirst(): void
    {
        $journalId = $this->writer->createJournal();
        foreach (range(1, 3) as $n) {
            $this->issue($n);
        }
        $this->writer->flush();
        $this->shopHead($journalId, ['A26' => ['number' => 'A26000004', 'hash' => strtoupper(hash('sha256', 'x')), 'seq' => 4]]);
        $this->connection->executeStatement('DELETE FROM fiscal_record WHERE id > 2');

        $this->assertSame(FiscalJournalGuard::BEHIND_JOURNAL, $this->guard()->check()['state']);
    }
}
