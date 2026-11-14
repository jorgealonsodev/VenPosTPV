<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\HttpClient\MockHttpClient;
use VenPosTPV\Service\Backup\BackupRetention;
use VenPosTPV\Service\Fiscal\FiscalHash;
use VenPosTPV\Service\Fiscal\Journal\FiscalDocumentSnapshot;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalShopSync;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIdentity;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIntents;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalStarter;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalStep;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalWriter;
use VenPosTPV\Service\PrestaShop\PsJournalClient;
use VenPosTPV\Service\Provisioning\PendingMigrationsStep;
use VenPosTPV\Service\Provisioning\ProvisioningException;
use VenPosTPV\Service\Provisioning\ProvisioningRunner;
use VenPosTPV\Service\Provisioning\ProvisioningStateStore;
use VenPosTPV\Service\Provisioning\ProvisioningStep;

/**
 * The provisioning step «fiscal-journal» (fiscal-journal S12, D11): on a fresh
 * install it creates both journal folders and the journal; on an upgrade of a live
 * install it journals the documents issued before (backfill), a bounded number per
 * run, the rest continuing in the every-minute job still marked as backfill; it
 * never creates a journal over a lost one. On SQLite.
 */
final class FiscalJournalStepTest extends TestCase
{
    private string $root;
    private Connection $connection;
    private FiscalJournalLocations $locations;
    private FiscalJournalWriter $writer;
    private FiscalJournalIdentity $identity;

    protected function setUp(): void
    {
        $this->root = JournalLines::temporaryDirectory('step');
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->root . '/db.sqlite']);
        $this->connection->executeStatement('CREATE TABLE fiscal_record (id INTEGER PRIMARY KEY AUTOINCREMENT, record_type VARCHAR(10) NOT NULL, invoice_type VARCHAR(2) NOT NULL, issuer_nif VARCHAR(16) NOT NULL, number VARCHAR(60) NOT NULL, issue_date VARCHAR(10) NOT NULL, tax_total VARCHAR(16) NOT NULL, total VARCHAR(16) NOT NULL, previous_hash VARCHAR(64) NULL, hash VARCHAR(64) NOT NULL, generated_at VARCHAR(25) NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE fiscal_chain (id INTEGER PRIMARY KEY, last_record_id INTEGER NULL, last_hash VARCHAR(64) NULL)');
        $this->connection->executeStatement('INSERT INTO fiscal_chain (id, last_record_id, last_hash) VALUES (1, NULL, NULL)');
        $this->connection->executeStatement('CREATE TABLE app_setting (name VARCHAR(100) PRIMARY KEY, value TEXT)');
        $this->locations = new FiscalJournalLocations($this->root . '/backups/fiscal-journal', $this->root . '/var/fiscal-journal');
        $this->writer = self::writer($this->connection, $this->locations);
        $this->identity = new FiscalJournalIdentity($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        JournalLines::remove($this->root);
    }

    public static function writer(Connection $connection, FiscalJournalLocations $locations): FiscalJournalWriter
    {
        $snapshot = new class($connection) extends FiscalDocumentSnapshot {
            public function build(array $record, ?int $refundMovementId = null): array
            {
                return ['kind' => (string) $record['invoice_type'], 'series' => 'A26', 'counter' => null, 'rows' => []];
            }
        };

        return new FiscalJournalWriter($connection, $locations, new FiscalJournalIntents($locations), null, $snapshot);
    }

    private function step(int $backfillLimit = FiscalJournalStep::BACKFILL_LINES_PER_RUN, ?array $shopAnswer = null): FiscalJournalStep
    {
        $shop = null;
        if ($shopAnswer !== null) {
            $client = new class($shopAnswer) extends PsJournalClient {
                public function __construct(private readonly array $answer)
                {
                    parent::__construct('https://tienda.tutienda.com', 'secret', new MockHttpClient());
                }

                public function heads(): array
                {
                    return $this->answer;
                }
            };
            $shop = new FiscalJournalShopSync($this->connection, $this->locations, $client, $this->identity);
        }

        return new FiscalJournalStep($this->locations, $this->identity, $this->writer, new FiscalJournalStarter($this->connection, $this->writer, $this->identity), $backfillLimit,
            $shop, new FiscalJournalGuard($this->connection, $this->locations, null, $this->identity));
    }

    /** @return array{status: string, journals: array<string, array<string, mixed>>, series: array<string, mixed>, error: null} the shop holding document $n of the series under $journalId */
    private static function shopHolding(string $journalId, int $n, string $hash): array
    {
        return ['status' => PsJournalClient::OK, 'journals' => [$journalId => [
            'journal_id' => $journalId, 'last_seq' => $n, 'contiguous_seq' => $n,
            'series' => ['A26' => ['number' => sprintf('A26%06d', $n), 'hash' => $hash, 'seq' => $n]],
        ]], 'series' => [], 'error' => null];
    }

    /**
     * Second correction round, recheck case (iii) and decision D: a first journal is
     * never started while the shop holds, under any journal, a document this database
     * lacks. A fresh install with an empty database against a shop that holds an
     * earlier install's journal is refused by design (it cannot be told from a restore).
     */
    public function testNoJournalIsStartedWhileTheShopHoldsADocumentTheDatabaseLacks(): void
    {
        self::issue($this->connection, 1);
        $step = $this->step(shopAnswer: self::shopHolding('00000000-0000-4000-8000-0000000000c1', 4, str_repeat('C', 64)));

        try {
            $step->run();
            $this->fail('the step must refuse');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('A26000004', $e->getMessage());
            $this->assertStringContainsString('docs/diario-fiscal.md', $e->getMessage());
            $this->assertStringContainsString('tpv:fiscal:journal:reset --confirm "<motivo>" --accept-shop-ahead', $e->getMessage());
        }
        $this->assertNull($this->locations->primary()->journalId(), 'no journal started');
        $this->assertNull($this->identity->journalId());
        $this->assertFalse($step->isDone());
        $this->assertSame(FiscalJournalGuard::BEHIND_SHOP, (new FiscalJournalGuard($this->connection, $this->locations))->check()['state'], 'the till is blocked');
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function shopsThatNeverHoldAnInstallBack(): iterable
    {
        yield 'a shop that never had journal lines' => [['status' => PsJournalClient::OK, 'journals' => [], 'series' => [], 'error' => null]];
        yield 'shop down' => [['status' => PsJournalClient::ERROR, 'journals' => null, 'series' => null, 'error' => 'timeout']];
        yield 'an older module' => [['status' => PsJournalClient::UNAVAILABLE, 'journals' => null, 'series' => null, 'error' => 'HTTP 422']];
        yield 'a shop error' => [['status' => PsJournalClient::ERROR, 'journals' => null, 'series' => null, 'error' => 'HTTP 500']];
    }

    /** Decision D: no false block on a fresh install or an upgrade. */
    #[\PHPUnit\Framework\Attributes\DataProvider('shopsThatNeverHoldAnInstallBack')]
    public function testAFreshInstallAndALiveUpgradeStartNormally(array $answer): void
    {
        $this->step(shopAnswer: $answer)->run();
        $this->assertNotNull($this->identity->journalId(), 'fresh install');

        // An upgrade of a live install: months of documents, the same shop.
        JournalLines::remove($this->root . '/backups');
        JournalLines::remove($this->root . '/var');
        $this->connection->executeStatement('DELETE FROM app_setting');
        foreach (range(1, 30) as $n) {
            self::issue($this->connection, $n);
        }
        $this->step(shopAnswer: $answer)->run();
        $this->assertSame(30, \count($this->lines()));
        $this->assertSame(FiscalJournalGuard::OK, (new FiscalJournalGuard($this->connection, $this->locations))->check()['state']);
    }

    public function testAShopThatHoldsOnlyDocumentsOfTheDatabaseOrRetiredOnesDoesNotStopTheStep(): void
    {
        self::issue($this->connection, 1);
        $hash = (string) $this->connection->fetchOne('SELECT hash FROM fiscal_record WHERE id = 1');
        $this->identity->retire(['00000000-0000-4000-8000-0000000000c9']);
        $answer = self::shopHolding('00000000-0000-4000-8000-0000000000c1', 1, $hash);
        $answer['journals'] += self::shopHolding('00000000-0000-4000-8000-0000000000c9', 9, str_repeat('D', 64))['journals'];

        $this->step(shopAnswer: $answer)->run();

        $this->assertNotNull($this->identity->journalId());
        $this->assertSame(1, \count($this->lines()));
    }

    public static function issue(Connection $connection, int $n): void
    {
        $previous = $connection->fetchOne('SELECT last_hash FROM fiscal_chain WHERE id = 1');
        $record = [
            'record_type' => 'alta', 'invoice_type' => 'F2', 'issuer_nif' => 'B00000000', 'number' => sprintf('A26%06d', $n),
            'issue_date' => '14-11-2026', 'tax_total' => '2.10', 'total' => '12.10', 'previous_hash' => \is_string($previous) ? $previous : null,
            'generated_at' => sprintf('2026-11-14T18:%02d:00+01:00', $n % 60),
        ];
        $record['hash'] = FiscalHash::alta('B00000000', $record['number'], '14-11-2026', 'F2', '2.10', '12.10', (string) $record['previous_hash'], $record['generated_at']);
        $connection->insert('fiscal_record', $record);
        $connection->update('fiscal_chain', ['last_record_id' => (int) $connection->lastInsertId(), 'last_hash' => $record['hash']], ['id' => 1]);
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        return array_values(iterator_to_array($this->locations->primary()->lines(), false));
    }

    private function mode(string $path): int
    {
        clearstatcache(true, $path);

        return fileperms($path) & 0777;
    }

    public function testItHasItsIdAndDescriptionAndRunsRightAfterTheMigrations(): void
    {
        $step = $this->step();
        $this->assertInstanceOf(ProvisioningStep::class, $step);
        $this->assertSame('fiscal-journal', $step->id());
        $this->assertSame('Diario fiscal preparado', $step->description());

        $priority = static fn (string $class): int => (new \ReflectionClass($class))->getAttributes(AsTaggedItem::class)[0]->newInstance()->priority;
        $this->assertLessThan($priority(PendingMigrationsStep::class), $priority(FiscalJournalStep::class));
        $this->assertGreaterThan(100, $priority(FiscalJournalStep::class), 'before the PrestaShop steps, which may stay pending');
    }

    public function testAFreshInstallCreatesBothFoldersProtectedAndTheJournal(): void
    {
        $step = $this->step();
        $this->assertFalse($step->isDone());

        $step->run();

        foreach (['backups', 'var'] as $base) {
            $folder = $this->root . '/' . $base . '/fiscal-journal';
            $this->assertDirectoryExists($folder);
            $this->assertSame(0700, $this->mode($folder), $folder);
            $this->assertSame("Require all denied\n", file_get_contents($folder . '/.htaccess'));
            $this->assertSame(0600, $this->mode($folder . '/.htaccess'));
            $this->assertSame(0600, $this->mode($folder . '/journal.id'));
        }
        $id = $this->locations->primary()->journalId();
        $this->assertNotNull($id);
        $this->assertSame($id, $this->locations->secondary()->journalId());
        $this->assertSame($id, $this->identity->journalId());
        $this->assertSame([], $this->lines());
        $this->assertTrue($step->isDone());
    }

    public function testItIsIdempotent(): void
    {
        $this->step()->run();
        $id = $this->identity->journalId();
        $journalFile = (string) file_get_contents($this->root . '/backups/fiscal-journal/journal.id');

        $again = $this->step();
        $this->assertTrue($again->isDone());
        $again->run();

        $this->assertSame($id, $this->identity->journalId());
        $this->assertSame($journalFile, file_get_contents($this->root . '/backups/fiscal-journal/journal.id'));
    }

    public function testAnUpgradeJournalsTheEarlierDocumentsAsBackfillAndNewOnesAsUsual(): void
    {
        for ($n = 1; $n <= 3; ++$n) {
            self::issue($this->connection, $n);
        }

        $this->step()->run();

        $lines = $this->lines();
        $this->assertSame([1, 2, 3], array_column($lines, 'seq'));
        $this->assertSame([true, true, true], array_column($lines, 'backfill'));
        $this->assertSame(['A26000001', 'A26000002', 'A26000003'], array_map(static fn (array $l): string => $l['fiscal_record']['number'], $lines));
        $this->assertSame(3, $this->locations->primary()->backfillUntil());

        self::issue($this->connection, 4);
        $this->writer->flush();
        $lines = $this->lines();
        $this->assertSame(4, $lines[3]['seq']);
        $this->assertFalse($lines[3]['backfill']);
    }

    public function testALongBackfillIsBoundedPerRunAndTheMinuteJobFinishesItStillMarkedAsBackfill(): void
    {
        for ($n = 1; $n <= 5; ++$n) {
            self::issue($this->connection, $n);
        }

        $step = $this->step(2);
        $step->run();

        $this->assertSame([1, 2], array_column($this->lines(), 'seq'));
        $this->assertTrue($step->isDone(), 'the upgrade is not held by the backfill');

        self::issue($this->connection, 6);
        // The every-minute job (FiscalJournalReconciler) flushes without the backfill flag.
        $this->writer->flush();
        $lines = $this->lines();
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($lines, 'seq'));
        $this->assertSame([true, true, true, true, true, false], array_column($lines, 'backfill'));
    }

    public function testAJournalWithoutTheSettingKeepsItsJournalAndStoresTheSetting(): void
    {
        self::issue($this->connection, 1);
        $id = $this->writer->createJournal();
        $this->writer->flush();
        $before = (string) file_get_contents($this->locations->primary()->journalFiles()[0]);

        $step = $this->step();
        $this->assertFalse($step->isDone());
        $step->run();

        $this->assertSame($id, $this->identity->journalId());
        $this->assertSame($id, $this->locations->primary()->journalId());
        $this->assertSame($before, file_get_contents($this->locations->primary()->journalFiles()[0]));
        $this->assertTrue($step->isDone());
    }

    public function testALostJournalIsNeverReplacedByTheStep(): void
    {
        $this->identity->store('0b4e8d0e-5d0c-4d6a-9f00-1a2b3c4d5e6f');

        $step = $this->step();
        try {
            $step->run();
            $this->fail('a lost journal must not be recreated');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('tpv:fiscal:journal:reset', $e->getMessage());
        }

        $this->assertNull($this->locations->primary()->journalId());
        $this->assertNull($this->locations->secondary()->journalId());
        $this->assertSame('0b4e8d0e-5d0c-4d6a-9f00-1a2b3c4d5e6f', $this->identity->journalId());
        $this->assertFalse($step->isDone());
    }

    public function testADatabaseNamingAnotherJournalIsReportedAndNothingChanges(): void
    {
        $id = $this->writer->createJournal();
        $this->identity->store('0b4e8d0e-5d0c-4d6a-9f00-1a2b3c4d5e6f');

        $this->expectException(ProvisioningException::class);
        try {
            $this->step()->run();
        } finally {
            $this->assertSame($id, $this->locations->primary()->journalId());
            $this->assertSame('0b4e8d0e-5d0c-4d6a-9f00-1a2b3c4d5e6f', $this->identity->journalId());
        }
    }

    public function testJournalFilesWithoutTheirIdentityAreNeverOverwritten(): void
    {
        self::issue($this->connection, 1);
        $this->writer->createJournal();
        $this->writer->flush();
        unlink($this->root . '/backups/fiscal-journal/journal.id');
        unlink($this->root . '/var/fiscal-journal/journal.id');
        $file = $this->locations->primary()->journalFiles()[0];
        $before = (string) file_get_contents($file);

        $this->expectException(ProvisioningException::class);
        try {
            $this->step()->run();
        } finally {
            $this->assertNull($this->locations->primary()->journalId());
            $this->assertNull($this->identity->journalId());
            $this->assertSame($before, file_get_contents($file));
        }
    }

    public function testAFolderLeftTooOpenOrWithoutItsHtaccessIsPendingAndTheRunFixesIt(): void
    {
        $step = $this->step();
        $step->run();
        chmod($this->root . '/backups/fiscal-journal', 0755);
        $this->assertFalse($step->isDone());
        $step->run();
        $this->assertTrue($step->isDone());

        unlink($this->root . '/var/fiscal-journal/.htaccess');
        $this->assertFalse($step->isDone());
        $step->run();
        $this->assertTrue($step->isDone());
        $this->assertSame("Require all denied\n", file_get_contents($this->root . '/var/fiscal-journal/.htaccess'));
    }

    public function testAPrimaryThatCannotBeWrittenFailsWithAReasonAndCreatesNoJournal(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores folder permissions');
        }
        mkdir($this->root . '/backups', 0500);

        try {
            $this->step()->run();
            $this->fail('expected a ProvisioningException');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('backups/fiscal-journal', $e->getMessage());
        } finally {
            chmod($this->root . '/backups', 0700);
        }
        $this->assertNull($this->identity->journalId());
    }

    public function testTheRunnerReportsItDoneOnAFreshInstall(): void
    {
        $store = $this->createMock(ProvisioningStateStore::class);
        $report = (new ProvisioningRunner([$this->step()], $store))->run();

        $this->assertTrue($report->isComplete());
        $this->assertSame('fiscal-journal', $report->steps[0]->id);
    }

    public function testTheBackupRotationNeverTouchesTheJournalFolder(): void
    {
        $this->step()->run();
        $backups = $this->root . '/backups';
        for ($day = 1; $day <= 3; ++$day) {
            touch(sprintf('%s/venpostpv-202611%02d-040000.sql.gz', $backups, $day));
        }

        $deleted = (new BackupRetention())->prune($backups, 1);

        $this->assertCount(2, $deleted);
        $this->assertDirectoryExists($backups . '/fiscal-journal');
        $this->assertFileExists($backups . '/fiscal-journal/journal.id');
    }
}
