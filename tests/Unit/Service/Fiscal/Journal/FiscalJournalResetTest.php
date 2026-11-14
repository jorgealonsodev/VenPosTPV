<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIdentity;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalReset;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalResetResult;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalShopSync;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalStarter;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalStep;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalWriter;
use VenPosTPV\Service\PrestaShop\PsJournalClient;

/**
 * tpv:fiscal:journal:reset (fiscal-journal A6, decisions in the Log): a new journal
 * from the current database, the old files archived byte for byte (never deleted),
 * an audit_log record, every document backfilled; refused, writing nothing, while
 * the database is behind a healthy journal, behind the shop (asked by the reset
 * itself), behind what a damaged journal still shows, or while the shop cannot be
 * asked (unless --shop-unavailable). On SQLite.
 */
final class FiscalJournalResetTest extends TestCase
{
    private const LOST = '0b4e8d0e-5d0c-4d6a-9f00-1a2b3c4d5e6f';

    private string $root;
    private Connection $connection;
    private FiscalJournalLocations $locations;
    private FiscalJournalWriter $writer;
    private FiscalJournalIdentity $identity;

    /** @var array<string, array<string, mixed>> the journals the shop holds, by id (their heads) */
    private array $shopJournals = [];
    /** @var array{status: string, journals: null, series: null, error: string}|null what the shop answers instead, when it cannot */
    private ?array $shopFailure = null;
    /** @var list<string> what the shop was asked */
    private array $shopAsked = [];
    private bool $shopConfigured = true;

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

    private function reset(int $backfillLinesPerRun = 500): FiscalJournalReset
    {
        $starter = new FiscalJournalStarter($this->connection, $this->writer, $this->identity);
        $client = new class($this->shopJournals, $this->shopFailure, $this->shopAsked) extends PsJournalClient {
            public function __construct(private array &$journals, private ?array &$failure, private array &$asked)
            {
                parent::__construct('https://tienda.tutienda.com', 'secret', new MockHttpClient());
            }

            public function heads(): array
            {
                $this->asked[] = 'heads';

                return $this->failure ?? ['status' => PsJournalClient::OK, 'journals' => $this->journals, 'series' => [], 'error' => null];
            }

            public function head(string $journalId): array
            {
                throw new \LogicException('the reset asks for every journal');
            }

            public function send(string $journalId, string $line): array
            {
                throw new \LogicException('the reset never sends');
            }
        };

        return new FiscalJournalReset(
            $this->connection,
            $this->locations,
            $this->writer,
            $starter,
            $this->identity,
            $this->root . '/var/fiscal-replay.lock',
            new FiscalJournalGuard($this->connection, $this->locations, null, $this->identity),
            null,
            $this->shopConfigured ? new FiscalJournalShopSync($this->connection, $this->locations, $client, $this->identity) : null,
            $backfillLinesPerRun,
        );
    }

    /** The shop's head of $journalId holding document $n of the series A26 (FiscalJournalStepTest::issue numbering). */
    private function shopHolds(string $journalId, int $n): void
    {
        $this->shopJournals[$journalId] = [
            'journal_id' => $journalId, 'last_seq' => $n, 'contiguous_seq' => $n,
            'series' => ['A26' => ['number' => sprintf('A26%06d', $n), 'hash' => self::hashOf($n), 'seq' => $n]],
        ];
    }

    /** The fiscal hash document $n gets from FiscalJournalStepTest::issue on top of documents 1..n-1. */
    private static function hashOf(int $n): string
    {
        $previous = '';
        for ($i = 1; $i <= $n; ++$i) {
            $previous = \VenPosTPV\Service\Fiscal\FiscalHash::alta('B00000000', sprintf('A26%06d', $i), '14-11-2026', 'F2', '2.10', '12.10', $previous, sprintf('2026-11-14T18:%02d:00+01:00', $i % 60));
        }

        return $previous;
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
        $this->assertSame(['path' => FiscalJournalReset::SHOP_CONFIRMED, 'asked' => [], 'error' => null], $payload['shop']);
        $this->assertNull($payload['accept_shop_ahead']);
        $this->assertSame([], $this->identity->retired(), 'a normal reset retires nothing');
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

    /** Line $n of the primary altered so it fails its own checks (damage, never repaired). */
    private function damageLine(int $n): string
    {
        $file = $this->locations->primary()->journalFiles()[0];
        file_put_contents($file, preg_replace('/' . sprintf('A26%06d', $n) . '/', sprintf('A26%06d', 90 + $n), (string) file_get_contents($file), 1));

        return $file;
    }

    public function testADamagedJournalWhoseReadableLinesAreAllInTheDatabaseCanBeReset(): void
    {
        $old = $this->journaled(4);
        $file = $this->damageLine(2);
        $damagedBytes = hash_file('sha256', $file);

        $result = $this->reset()->run('Diario dañado en el disco');

        $this->assertSame(FiscalJournalResetResult::DONE, $result->status, $result->message);
        $archive = $this->root . '/backups/fiscal-journal/archive/' . $this->archives($this->root . '/backups/fiscal-journal')[0];
        $this->assertSame($damagedBytes, hash_file('sha256', $archive . '/' . basename($file)));
        $lines = array_values(iterator_to_array($this->locations->primary()->lines(), false));
        $this->assertSame([1, 2, 3, 4], array_column($lines, 'seq'));
        $payload = json_decode((string) $this->audit()[0]['payload'], true);
        $this->assertSame($old, $payload['previous_journal_id']);
        $this->assertNotNull($payload['damaged']);
    }

    /**
     * fiscal-journal, final verification gap 3: a damaged middle line no longer lets
     * the reset through while a valid line after it names a document the database lacks.
     */
    public function testADamagedMiddleLineDoesNotAllowAResetWhileALaterValidLineIsMissingFromTheDatabase(): void
    {
        $id = $this->journaled(4);
        $this->restoreBefore(1);
        $this->damageLine(2);
        $primary = $this->files($this->root . '/backups/fiscal-journal');
        $secondary = $this->files($this->root . '/var/fiscal-journal');

        $result = $this->reset()->run('Diario dañado en el disco');

        $this->assertSame(FiscalJournalResetResult::REFUSED, $result->status, $result->message);
        $this->assertSame(3, $result->exitCode());
        $this->assertStringContainsString('A26000004', $result->message);
        $this->assertStringContainsString('dañado', $result->message);
        $this->assertNothingChanged($id, $primary, $secondary);
    }

    public function testADamagedJournalWhoseHeadJsonNamesAMissingDocumentIsRefused(): void
    {
        $id = $this->journaled(4);
        $this->restoreBefore(1);
        // Line 4 damaged too: only head.json still names A26000004.
        $this->damageLine(2);
        $this->damageLine(4);
        $primary = $this->files($this->root . '/backups/fiscal-journal');
        $secondary = $this->files($this->root . '/var/fiscal-journal');

        $result = $this->reset()->run('Diario dañado en el disco');

        $this->assertSame(FiscalJournalResetResult::REFUSED, $result->status, $result->message);
        $this->assertStringContainsString('A26000004', $result->message);
        $this->assertNothingChanged($id, $primary, $secondary);
    }

    public function testAConfirmedIntentOfAMissingDocumentRefusesADamagedJournal(): void
    {
        $id = $this->journaled(3);
        $this->damageLine(2);
        $this->locations->primary()->writeIntent(self::hashOf(4), ['state' => 'confirmed', 'created_at' => '2026-11-14T18:00:00+01:00', 'fiscal_record' => ['number' => 'A26000004', 'invoice_type' => 'F2', 'hash' => self::hashOf(4)]]);
        $primary = $this->files($this->root . '/backups/fiscal-journal');
        $secondary = $this->files($this->root . '/var/fiscal-journal');

        $result = $this->reset()->run('Diario dañado en el disco');

        $this->assertSame(FiscalJournalResetResult::REFUSED, $result->status, $result->message);
        $this->assertStringContainsString('A26000004', $result->message);
        $this->assertNothingChanged($id, $primary, $secondary);
    }

    /** Final verification gap 6: a refusal writes nothing, not even a torn tail moved to quarantine/. */
    public function testARefusedResetLeavesATornTailWhereItIs(): void
    {
        $id = $this->journaled(4);
        $this->restoreBefore(2);
        file_put_contents($this->locations->primary()->journalFiles()[0], '{"v":1,"seq":5,"kind":"F2"', \FILE_APPEND);
        $primary = $this->files($this->root . '/backups/fiscal-journal');
        $secondary = $this->files($this->root . '/var/fiscal-journal');

        $result = $this->reset()->run('Quiero empezar de nuevo');

        $this->assertSame(FiscalJournalResetResult::REFUSED, $result->status, $result->message);
        $this->assertStringContainsString('A26000003', $result->message);
        $this->assertNothingChanged($id, $primary, $secondary);
        $this->assertDirectoryDoesNotExist($this->root . '/backups/fiscal-journal/quarantine/x');
        $this->assertSame([], glob($this->root . '/backups/fiscal-journal/quarantine/*') ?: []);
    }

    /** Final verification decision 2: the reset asks the shop itself before anything. */
    public function testItAsksTheShopAndRefusesWhenTheShopHoldsADocumentTheDatabaseLacks(): void
    {
        $id = $this->journaled(4);
        $this->restoreBefore(1);
        // The journal does not show it (a healthy journal behind the shop: lines lost with the restore of the folder).
        $this->shopHolds($id, 5);
        $primary = $this->files($this->root . '/backups/fiscal-journal');
        $secondary = $this->files($this->root . '/var/fiscal-journal');

        $result = $this->reset()->run('Quiero empezar de nuevo');

        $this->assertSame(['heads'], $this->shopAsked);
        $this->assertSame(FiscalJournalResetResult::REFUSED, $result->status, $result->message);
        $this->assertSame(3, $result->exitCode());
        $this->assertStringContainsString('A26000005', $result->message);
        $this->assertStringContainsString('tpv:fiscal:replay', $result->message);
        $this->assertStringContainsString('docs/diario-fiscal.md', $result->message);
        $this->assertNothingChanged($id, $primary, $secondary);
        $this->assertFileDoesNotExist($this->root . '/backups/fiscal-journal/shop-head.json', 'asked, not stored');
    }

    public function testALostJournalAfterAnOlderRestoreIsRefusedWhenTheShopHoldsTheLaterDocuments(): void
    {
        FiscalJournalStepTest::issue($this->connection, 1);
        FiscalJournalStepTest::issue($this->connection, 2);
        $this->identity->store(self::LOST);
        $this->shopHolds(self::LOST, 4);

        $result = $this->reset()->run('Servidor nuevo, el diario no se pudo copiar');

        $this->assertSame(['heads'], $this->shopAsked);
        $this->assertSame(FiscalJournalResetResult::REFUSED, $result->status, $result->message);
        $this->assertStringContainsString('A26000004', $result->message);
        $this->assertSame(self::LOST, $this->identity->journalId());
        $this->assertSame([], $this->audit());
        $this->assertDirectoryDoesNotExist($this->root . '/backups/fiscal-journal', 'a refusal creates no folder');
    }

    /**
     * @return iterable<string, array{0: ?array{status: string, head: ?array, error: ?string}}>
     */
    public static function shopThatCannotBeAsked(): iterable
    {
        yield 'down' => [['status' => PsJournalClient::ERROR, 'journals' => null, 'series' => null, 'error' => 'Connection refused']];
        yield 'module older than 0.9.0' => [['status' => PsJournalClient::UNAVAILABLE, 'journals' => null, 'series' => null, 'error' => 'HTTP 404']];
        yield 'a module without the answer for every journal' => [['status' => PsJournalClient::UNAVAILABLE, 'journals' => null, 'series' => null, 'error' => 'HTTP 422']];
        yield 'an error' => [['status' => PsJournalClient::ERROR, 'journals' => null, 'series' => null, 'error' => 'HTTP 500']];
        yield 'not configured' => [null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('shopThatCannotBeAsked')]
    public function testAShopThatCannotBeAskedRefusesTheResetWithoutTheOption(?array $answer): void
    {
        FiscalJournalStepTest::issue($this->connection, 1);
        $this->identity->store(self::LOST);
        if ($answer === null) {
            $this->shopConfigured = false;
        } else {
            $this->shopFailure = $answer;
        }

        $result = $this->reset()->run('Servidor nuevo');

        $this->assertSame(FiscalJournalResetResult::REFUSED, $result->status, $result->message);
        $this->assertSame(3, $result->exitCode());
        $this->assertStringContainsString('--shop-unavailable', $result->message);
        $this->assertStringContainsString('se pueden repetir números', $result->message);
        $this->assertSame(self::LOST, $this->identity->journalId());
        $this->assertSame([], $this->audit());
        $this->assertDirectoryDoesNotExist($this->root . '/backups/fiscal-journal');
    }

    public function testWithShopUnavailableTheResetGoesOnAndTheAuditRecordSaysSo(): void
    {
        FiscalJournalStepTest::issue($this->connection, 1);
        $this->identity->store(self::LOST);
        $this->shopFailure = ['status' => PsJournalClient::UNAVAILABLE, 'journals' => null, 'series' => null, 'error' => 'HTTP 404'];

        $result = $this->reset()->run('Tienda sin el módulo 0.9.0', null, true);

        $this->assertSame(FiscalJournalResetResult::DONE, $result->status, $result->message);
        $payload = json_decode((string) $this->audit()[0]['payload'], true);
        $this->assertSame(FiscalJournalReset::SHOP_UNAVAILABLE, $payload['shop']['path']);
        $this->assertSame([], $payload['shop']['asked']);
        $this->assertStringContainsString('0.9.0', (string) $payload['shop']['error']);
    }

    public function testWithShopUnavailableADocumentTheShopDidAnswerStillRefuses(): void
    {
        $id = $this->journaled(2);
        $this->identity->store($id);
        $this->shopHolds($id, 3);

        $result = $this->reset()->run('Motivo', null, true);

        $this->assertSame(FiscalJournalResetResult::REFUSED, $result->status, $result->message);
        $this->assertStringContainsString('A26000003', $result->message);
    }

    public function testTheBackfillOfTheResetIsBoundedAndTheMinuteJobWritesTheRest(): void
    {
        $this->journaled(5);

        $result = $this->reset(2)->run('Motivo');

        $this->assertSame(FiscalJournalResetResult::DONE, $result->status, $result->message);
        $this->assertSame(2, $result->backfilled);
        $this->assertStringContainsString('tarea de cada minuto', $result->message);
        FiscalJournalStepTest::issue($this->connection, 6);
        $this->writer->flush();
        $lines = array_values(iterator_to_array($this->locations->primary()->lines(), false));
        $this->assertSame([true, true, true, true, true, false], array_column($lines, 'backfill'), 'backfill_until labels them, whoever flushes');
    }

    /**
     * Second correction round, recheck cases (i) and (ii): the shop is asked for every
     * journal, so later documents under a journal the database setting does not name
     * (one reset in the history, an id the shop never heard of) refuse the reset.
     */
    public function testTheShopHoldingADocumentTheDatabaseLacksUnderAnyJournalRefusesTheReset(): void
    {
        FiscalJournalStepTest::issue($this->connection, 1);
        FiscalJournalStepTest::issue($this->connection, 2);
        $this->identity->store(self::LOST);
        // The shop knows nothing of LOST, but holds A26000004 under another journal (reset later).
        $this->shopHolds('00000000-0000-4000-8000-0000000000a2', 4);

        $result = $this->reset()->run('Servidor nuevo');

        $this->assertSame(FiscalJournalResetResult::REFUSED, $result->status, $result->message);
        $this->assertStringContainsString('A26000004', $result->message);
        $this->assertStringContainsString('--accept-shop-ahead', $result->message);
        $this->assertSame(self::LOST, $this->identity->journalId());
        $this->assertSame([], $this->audit());
        $this->assertDirectoryDoesNotExist($this->root . '/backups/fiscal-journal');
    }

    public function testAShopThatHoldsOnlyDocumentsOfTheDatabaseConfirmsWhateverTheirJournal(): void
    {
        FiscalJournalStepTest::issue($this->connection, 1);
        FiscalJournalStepTest::issue($this->connection, 2);
        $this->identity->store(self::LOST);
        $this->shopHolds('00000000-0000-4000-8000-0000000000a1', 2);

        $result = $this->reset()->run('Servidor nuevo');

        $this->assertSame(FiscalJournalResetResult::DONE, $result->status, $result->message);
        $payload = json_decode((string) $this->audit()[0]['payload'], true);
        $this->assertSame(['00000000-0000-4000-8000-0000000000a1'], $payload['shop']['asked']);
        $this->assertSame([], $this->identity->retired());
    }

    /** Decision C: a deliberate fresh start retires the journals the shop holds, and they never block again. */
    public function testAcceptShopAheadStartsOverRetiresTheShopsJournalsAndRecordsThem(): void
    {
        // A reinstall: an empty database against a shop that holds the previous install's journal.
        $previous = '00000000-0000-4000-8000-0000000000b1';
        $this->shopHolds($previous, 7);

        $refused = $this->reset()->run('Reinstalación desde cero');
        $this->assertSame(FiscalJournalResetResult::REFUSED, $refused->status, $refused->message);

        $result = $this->reset()->run('Reinstalación desde cero', null, false, true);

        $this->assertSame(FiscalJournalResetResult::DONE, $result->status, $result->message);
        $this->assertSame([$previous], $this->identity->retired());
        $payload = json_decode((string) $this->audit()[0]['payload'], true);
        $this->assertSame(['missing' => 'A26000007', 'retired_journals' => [$previous]], $payload['accept_shop_ahead']);
        $this->assertSame(FiscalJournalGuard::OK, (new FiscalJournalGuard($this->connection, $this->locations))->check()['state']);

        // The guard ignores the retired journal in the stored shop head, and so does a later reset.
        $this->locations->primary()->writeShopHead(['v' => 1, 'all' => ['fetched_at' => '2026-11-14T18:00:00Z'], 'heads' => [$previous => ['fetched_at' => '2026-11-14T18:00:00Z'] + $this->shopJournals[$previous]]]);
        $this->assertSame(FiscalJournalGuard::OK, (new FiscalJournalGuard($this->connection, $this->locations))->check()['state']);
        $this->assertSame(FiscalJournalResetResult::DONE, $this->reset()->run('Otra vez')->status);
        $this->assertSame([$previous], $this->identity->retired(), 'nothing more retired');
    }

    public function testAcceptShopAheadNeverOverridesTheJournalsOwnEvidence(): void
    {
        $id = $this->journaled(4);
        $this->restoreBefore(2);

        $result = $this->reset()->run('Motivo', null, false, true);

        $this->assertSame(FiscalJournalResetResult::REFUSED, $result->status, $result->message);
        $this->assertStringContainsString('A26000004', $result->message);
        $this->assertSame([], $this->identity->retired());
        $this->assertSame($id, $this->identity->journalId());
    }

    public function testASecondResetIsToldThatAResetIsRunning(): void
    {
        $id = $this->journaled(1);
        $primary = $this->files($this->root . '/backups/fiscal-journal');
        $secondary = $this->files($this->root . '/var/fiscal-journal');
        $lock = fopen($this->root . '/var/fiscal-journal-reset.lock', 'c');
        $this->assertTrue(flock($lock, \LOCK_EX));

        try {
            $result = $this->reset()->run('Motivo');
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }

        $this->assertSame(FiscalJournalResetResult::ERROR, $result->status);
        $this->assertStringContainsString('tpv:fiscal:journal:reset en marcha', $result->message);
        $this->assertStringNotContainsString('recuperación', $result->message);
        $this->assertNothingChanged($id, $primary, $secondary);
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
        $this->assertStringContainsString('recuperación de documentos en marcha', $result->message);
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
