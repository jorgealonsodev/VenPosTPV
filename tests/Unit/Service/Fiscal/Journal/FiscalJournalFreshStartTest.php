<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalFreshStart;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIdentity;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalReset;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalResetResult;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalShopSync;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalStarter;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalWriter;
use VenPosTPV\Service\PrestaShop\PsJournalClient;

/**
 * The installer's «Empezar de cero» (fiscal-journal, installer decision page): the same
 * reset as `tpv:fiscal:journal:reset --accept-shop-ahead`, recorded as coming from the
 * installer, and never over a journal this installation already started (a double
 * submit, or the installer run again later). On SQLite.
 */
final class FiscalJournalFreshStartTest extends TestCase
{
    private const PREVIOUS = '00000000-0000-4000-8000-0000000000c1';

    private string $root;
    private Connection $connection;
    private FiscalJournalLocations $locations;
    private FiscalJournalWriter $writer;
    private FiscalJournalIdentity $identity;
    /** @var array<string, array<string, mixed>> */
    private array $shopJournals = [];

    protected function setUp(): void
    {
        $this->root = JournalLines::temporaryDirectory('fresh-start');
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->root . '/db.sqlite']);
        $this->connection->executeStatement('CREATE TABLE fiscal_record (id INTEGER PRIMARY KEY AUTOINCREMENT, record_type VARCHAR(10) NOT NULL, invoice_type VARCHAR(2) NOT NULL, issuer_nif VARCHAR(16) NOT NULL, number VARCHAR(60) NOT NULL, issue_date VARCHAR(10) NOT NULL, tax_total VARCHAR(16) NOT NULL, total VARCHAR(16) NOT NULL, previous_hash VARCHAR(64) NULL, hash VARCHAR(64) NOT NULL, generated_at VARCHAR(25) NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE fiscal_chain (id INTEGER PRIMARY KEY, last_record_id INTEGER NULL, last_hash VARCHAR(64) NULL)');
        $this->connection->executeStatement('INSERT INTO fiscal_chain (id, last_record_id, last_hash) VALUES (1, NULL, NULL)');
        $this->connection->executeStatement('CREATE TABLE app_setting (name VARCHAR(100) PRIMARY KEY, value TEXT)');
        $this->connection->executeStatement('CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, terminal_id INTEGER NULL, action VARCHAR(64) NOT NULL, target VARCHAR(128) NULL, payload TEXT NULL, ip VARCHAR(45) NULL, created_at DATETIME NOT NULL)');
        $this->locations = new FiscalJournalLocations($this->root . '/backups/fiscal-journal', $this->root . '/var/fiscal-journal');
        $this->writer = FiscalJournalStepTest::writer($this->connection, $this->locations);
        $this->identity = new FiscalJournalIdentity($this->connection);
        // The previous installation's journal, with 3 tickets, is in the shop; this database is empty.
        $this->shopJournals[self::PREVIOUS] = [
            'journal_id' => self::PREVIOUS, 'last_seq' => 3, 'contiguous_seq' => 3,
            'series' => ['A26' => ['number' => 'A26000003', 'hash' => str_repeat('C', 64), 'seq' => 3]],
        ];
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        JournalLines::remove($this->root);
    }

    private function freshStart(): FiscalJournalFreshStart
    {
        $client = new class($this->shopJournals) extends PsJournalClient {
            public function __construct(private array &$journals)
            {
                parent::__construct('https://tienda.tutienda.com', 'secret', new MockHttpClient());
            }

            public function heads(): array
            {
                return ['status' => PsJournalClient::OK, 'journals' => $this->journals, 'series' => [], 'error' => null];
            }
        };
        $reset = new FiscalJournalReset(
            $this->connection,
            $this->locations,
            $this->writer,
            new FiscalJournalStarter($this->connection, $this->writer, $this->identity),
            $this->identity,
            $this->root . '/var/fiscal-replay.lock',
            new FiscalJournalGuard($this->connection, $this->locations, null, $this->identity),
            null,
            new FiscalJournalShopSync($this->connection, $this->locations, $client, $this->identity),
        );

        return new FiscalJournalFreshStart($reset, $this->identity, $this->locations);
    }

    /** @return list<array<string, mixed>> */
    private function audit(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id');
    }

    public function testItRetiresTheShopsJournalsRecordsTheReasonAndTheInstallerAndStartsAJournal(): void
    {
        $result = $this->freshStart()->run('Reinstalación: el servidor anterior se dio de baja');

        $this->assertSame(FiscalJournalResetResult::DONE, $result->status, $result->message);
        $this->assertSame([self::PREVIOUS], $this->identity->retired());
        $this->assertNotNull($result->journalId);
        $this->assertSame($result->journalId, $this->identity->journalId());
        $this->assertSame($result->journalId, $this->locations->primary()->journalId());
        $audit = $this->audit();
        $this->assertCount(1, $audit);
        $this->assertSame(FiscalJournalReset::AUDIT_ACTION, $audit[0]['action']);
        $payload = json_decode((string) $audit[0]['payload'], true);
        $this->assertSame('Reinstalación: el servidor anterior se dio de baja', $payload['reason']);
        $this->assertSame('installer', $payload['origin']);
        $this->assertSame(['missing' => 'A26000003', 'retired_journals' => [self::PREVIOUS]], $payload['accept_shop_ahead']);
        $this->assertSame(FiscalJournalGuard::OK, (new FiscalJournalGuard($this->connection, $this->locations, null, $this->identity))->check()['state']);
    }

    public function testASecondRunNeverRetiresOrRestartsTheJournalItStarted(): void
    {
        $first = $this->freshStart()->run('Reinstalación');
        $this->assertSame(FiscalJournalResetResult::DONE, $first->status, $first->message);
        // The shop has meanwhile received a line of the new journal too.
        $this->shopJournals[(string) $first->journalId] = ['journal_id' => (string) $first->journalId, 'last_seq' => 0, 'contiguous_seq' => 0, 'series' => []];

        $second = $this->freshStart()->run('Reinstalación');

        $this->assertSame(FiscalJournalResetResult::REFUSED, $second->status);
        $this->assertStringContainsString('ya tiene su diario fiscal', $second->message);
        $this->assertSame($first->journalId, $this->identity->journalId());
        $this->assertSame([self::PREVIOUS], $this->identity->retired());
        $this->assertCount(1, $this->audit());
        $this->assertSame([], glob($this->root . '/backups/fiscal-journal/archive/*') ?: [], 'nothing archived');
    }

    public function testItRefusesWhenTheFoldersAlreadyHoldAJournal(): void
    {
        $this->writer->createJournal(null, null, 0);

        $result = $this->freshStart()->run('Reinstalación');

        $this->assertSame(FiscalJournalResetResult::REFUSED, $result->status);
        $this->assertNull($this->identity->journalId());
        $this->assertSame([], $this->identity->retired());
        $this->assertSame([], $this->audit());
    }

    public function testWithoutAReasonNothingIsDone(): void
    {
        $result = $this->freshStart()->run('   ');

        $this->assertSame(FiscalJournalResetResult::INVALID, $result->status);
        $this->assertSame([], $this->audit());
        $this->assertNull($this->identity->journalId());
    }
}
