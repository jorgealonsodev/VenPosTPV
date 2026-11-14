<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIdentity;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalShopSync;
use VenPosTPV\Service\Fiscal\Journal\JournalLocation;
use VenPosTPV\Service\PrestaShop\PsJournalClient;
use VenPosTPV\Service\Sync\Outbox;
use VenPosTPV\Service\Sync\OutboxSync;

/**
 * The every-minute part of the shop copy (fiscal-journal D6, S8, S9): every journal
 * line gets one outbox row `journal`, in seq order; the shop head is asked once,
 * stored in `shop-head.json` and summarised for Ajustes; rows lost in a restore of
 * the database, or lines the shop lost, are queued again; a module that is back
 * makes the waiting rows due. On SQLite with a scripted shop.
 */
final class FiscalJournalShopSyncTest extends TestCase
{
    private const JOURNAL = '6f1c2a4e-1b2c-4d3e-8f90-0123456789ab';

    private string $root;
    private Connection $connection;
    private FiscalJournalLocations $locations;
    /** @var list<array{status: string, head: ?array, error: ?string}> */
    private array $heads = [];
    /** @var list<string> journal ids asked for */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->root = JournalLines::temporaryDirectory('shop-sync');
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->root . '/db.sqlite']);
        $this->connection->executeStatement('CREATE TABLE outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, type VARCHAR(32) NOT NULL, order_id INTEGER NULL, sequence INTEGER NOT NULL, depends_on INTEGER NULL, request_id VARCHAR(64) NOT NULL UNIQUE, payload TEXT NOT NULL, status VARCHAR(16) NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, next_attempt_at DATETIME NOT NULL, claim_token VARCHAR(32) NULL, claimed_at DATETIME NULL, last_error VARCHAR(1000) NULL, record_failures INTEGER NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, done_at DATETIME NULL)');
        $this->connection->executeStatement('CREATE TABLE app_setting (name VARCHAR(100) PRIMARY KEY, value TEXT)');
        $this->locations = new FiscalJournalLocations($this->root . '/backups/fiscal-journal', $this->root . '/var/fiscal-journal');
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        JournalLines::remove($this->root);
    }

    private function sync(): FiscalJournalShopSync
    {
        $client = new class($this->heads, $this->asked) extends PsJournalClient {
            public function __construct(private array &$heads, private array &$asked)
            {
                parent::__construct('https://tienda.tutienda.com', 'secret', new MockHttpClient());
            }

            public function head(string $journalId): array
            {
                $this->asked[] = $journalId;

                return array_shift($this->heads) ?? ['status' => PsJournalClient::ERROR, 'head' => null, 'error' => 'no answer scripted'];
            }

            public function send(string $journalId, string $line): array
            {
                throw new \LogicException('the sync never sends: the outbox does');
            }
        };

        return new FiscalJournalShopSync($this->connection, $this->locations, $client, new FiscalJournalIdentity($this->connection));
    }

    /** @return list<array<string, mixed>> */
    private function journal(int $lines): array
    {
        $primary = $this->locations->primary();
        $primary->prepare();
        $primary->writeJournalId(self::JOURNAL, new \DateTimeImmutable());
        $chain = JournalLines::chain(1, $lines);
        foreach ($chain as $line) {
            $primary->append($line);
        }

        return $chain;
    }

    private function answer(int $lastSeq, int $contiguousSeq, array $series = []): void
    {
        $this->heads[] = ['status' => PsJournalClient::OK, 'head' => ['journal_id' => self::JOURNAL, 'last_seq' => $lastSeq, 'contiguous_seq' => $contiguousSeq, 'series' => $series], 'error' => null];
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->connection->fetchAllAssociative("SELECT * FROM outbox WHERE type = 'journal' ORDER BY id");
    }

    private static function at(string $time = '2026-11-14 18:10:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time);
    }

    public function testEveryLineGetsOneRowInSeqOrderCarryingTheLineAsWritten(): void
    {
        $lines = $this->journal(3);
        $this->answer(0, 0);

        $state = $this->sync()->run(self::at());

        $rows = $this->rows();
        $this->assertSame([1, 2, 3], array_map(static fn (array $r): int => (int) $r['sequence'], $rows));
        foreach ($rows as $i => $row) {
            $this->assertNull($row['order_id']);
            $this->assertSame(Outbox::STATUS_PENDING, $row['status']);
            $this->assertStringStartsWith(Outbox::journalRequestPrefix(self::JOURNAL), $row['request_id']);
            $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(['journal_id', 'seq', 'number', 'invoice_type', 'hash', 'line_hash', 'line'], array_keys($payload));
            $this->assertSame(self::JOURNAL, $payload['journal_id']);
            $this->assertSame($lines[$i]['seq'], $payload['seq']);
            $this->assertSame($lines[$i]['fiscal_record']['number'], $payload['number']);
            $this->assertSame($lines[$i]['fiscal_record']['hash'], $payload['hash']);
            $this->assertSame($lines[$i]['line_hash'], $payload['line_hash']);
            $this->assertSame(rtrim(JournalLocation::encode($lines[$i]), "\n"), $payload['line'], 'byte for byte as in the journal');
        }
        $this->assertSame([self::JOURNAL], $this->asked, 'one head per run');
        $this->assertSame(FiscalJournalShopSync::PENDING, $state['status']);
        $this->assertSame(3, $state['pending']);
        $this->assertSame(3, $state['enqueued']);

        // The next run queues nothing again.
        $this->answer(0, 0);
        $this->assertSame(0, $this->sync()->run(self::at())['enqueued']);
        $this->assertCount(3, $this->rows());

        // A new line: only it.
        $this->locations->primary()->append(JournalLines::chain(4, 4, $lines[2])[0]);
        $this->answer(0, 0);
        $this->assertSame(1, $this->sync()->run(self::at())['enqueued']);
        $this->assertSame([1, 2, 3, 4], array_map(static fn (array $r): int => (int) $r['sequence'], $this->rows()));
    }

    public function testTheHeadIsStoredForTheGuardAndTheShopUpToDateIsSaidWithItsLastDocument(): void
    {
        $lines = $this->journal(2);
        $this->answer(0, 0);
        $this->sync()->run(self::at());
        $this->connection->executeStatement("UPDATE outbox SET status = 'done', done_at = '2026-11-14 18:10:30'");
        $series = ['A26' => ['number' => $lines[1]['fiscal_record']['number'], 'hash' => $lines[1]['fiscal_record']['hash'], 'seq' => 2]];
        $this->answer(2, 2, $series);

        $state = $this->sync()->run(self::at('2026-11-14 18:11:00'));

        $this->assertSame(FiscalJournalShopSync::OK, $state['status']);
        $this->assertSame(['number' => $lines[1]['fiscal_record']['number'], 'seq' => 2], $state['last_document']);
        $this->assertSame(2, $state['last_seq']);
        $this->assertSame(2, $state['contiguous_seq']);
        $this->assertSame(0, $state['pending']);
        $stored = $this->locations->primary()->readShopHead();
        $this->assertSame(1, $stored['v']);
        $this->assertSame($series, $stored['heads'][self::JOURNAL]['series']);
        $this->assertSame(2, $stored['heads'][self::JOURNAL]['contiguous_seq']);
        $this->assertMatchesRegularExpression('/\A2026-11-14T\d\d:11:00\+00:00\z/', $stored['heads'][self::JOURNAL]['fetched_at']);
    }

    public function testRowsLostInARestoreOfTheDatabaseAreQueuedAgain(): void
    {
        $this->journal(5);
        $this->answer(0, 0);
        $this->sync()->run(self::at());
        $this->connection->executeStatement("UPDATE outbox SET status = 'done', done_at = '2026-11-14 18:10:30' WHERE sequence <= 2");
        // The database comes back from a backup taken when only seq 1 and 2 had rows.
        $this->connection->executeStatement('DELETE FROM outbox WHERE sequence > 2');
        $this->answer(2, 2);

        $state = $this->sync()->run(self::at('2026-11-14 18:12:00'));

        $this->assertSame(3, $state['enqueued']);
        $this->assertSame([1, 2, 3, 4, 5], array_map(static fn (array $r): int => (int) $r['sequence'], $this->rows()));
    }

    public function testLinesTheShopLostAfterAcknowledgingThemAreSentAgain(): void
    {
        $this->journal(4);
        $this->answer(0, 0);
        $this->sync()->run(self::at());
        $this->connection->executeStatement("UPDATE outbox SET status = 'done', done_at = '2026-11-14 18:10:30'");
        // The shop's table came back from an older backup: it holds 1 and 2 only.
        $this->answer(2, 2);

        $state = $this->sync()->run(self::at('2026-11-14 18:12:00'));

        $this->assertSame(2, $state['enqueued']);
        $pending = $this->connection->fetchFirstColumn("SELECT sequence FROM outbox WHERE status = 'pending' ORDER BY id");
        $this->assertSame([3, 4], array_map('intval', $pending));
    }

    public function testAGapHeldByAnIncidentNeverResendsWhatFollowsIt(): void
    {
        $this->journal(4);
        $this->answer(0, 0);
        $this->sync()->run(self::at());
        $this->connection->executeStatement("UPDATE outbox SET status = 'done', done_at = '2026-11-14 18:10:30' WHERE sequence <> 2");
        $this->connection->executeStatement("UPDATE outbox SET status = 'incident', last_error = '409' WHERE sequence = 2");
        $this->answer(4, 1);

        $state = $this->sync()->run(self::at('2026-11-14 18:12:00'));

        $this->assertSame(0, $state['enqueued']);
        $this->assertCount(4, $this->rows());
        $this->assertSame(FiscalJournalShopSync::INCIDENTS, $state['status']);
        $this->assertSame(1, $state['incidents']);
    }

    public function testAModuleTooOldWaitsAndOnceUpgradedTheWaitingRowsAreDueAtOnce(): void
    {
        $this->journal(2);
        $this->heads[] = ['status' => PsJournalClient::UNAVAILABLE, 'head' => null, 'error' => 'HTTP 404'];

        $state = $this->sync()->run(self::at());

        $this->assertSame(FiscalJournalShopSync::UNAVAILABLE, $state['status']);
        $this->assertSame(2, $state['enqueued'], 'the rows are queued and wait');
        $this->assertNull($this->locations->primary()->readShopHead(), 'nothing stored from an answer that is none');
        // The outbox met the old module: the first row waits an hour.
        $this->connection->executeStatement("UPDATE outbox SET attempts = 1, last_error = ?, next_attempt_at = '2026-11-14 19:10:00' WHERE sequence = 1", [OutboxSync::JOURNAL_MODULE_UNAVAILABLE]);
        $this->connection->executeStatement("UPDATE outbox SET next_attempt_at = '2026-11-14 19:00:00', last_error = 'HTTP 500' WHERE sequence = 2");

        $this->answer(0, 0);
        $this->sync()->run(self::at('2026-11-14 18:20:00'));

        $due = $this->connection->fetchAllKeyValue("SELECT sequence, next_attempt_at FROM outbox ORDER BY sequence");
        $this->assertSame((new \DateTimeImmutable('2026-11-14 18:20:00'))->format('Y-m-d H:i:s'), $due[1], 'the module is there now');
        $this->assertSame('2026-11-14 19:00:00', $due[2], 'another wait keeps its backoff');
    }

    public function testAShopThatCannotBeReachedIsReportedAndChangesNothingStored(): void
    {
        $lines = $this->journal(1);
        $this->answer(1, 1, ['A26' => ['number' => $lines[0]['fiscal_record']['number'], 'hash' => $lines[0]['fiscal_record']['hash'], 'seq' => 1]]);
        $this->sync()->run(self::at());
        $before = $this->locations->primary()->readShopHead();
        $this->heads[] = ['status' => PsJournalClient::ERROR, 'head' => null, 'error' => 'The shop could not be reached: timeout'];

        $state = $this->sync()->run(self::at('2026-11-14 18:11:00'));

        $this->assertSame(FiscalJournalShopSync::UNREACHABLE, $state['status']);
        $this->assertSame('The shop could not be reached: timeout', $state['error']);
        $this->assertSame($before, $this->locations->primary()->readShopHead(), 'the last answer stays');
    }

    public function testALostJournalAsksTheShopForTheJournalTheDatabaseNamesAndQueuesNothing(): void
    {
        (new FiscalJournalIdentity($this->connection))->store(self::JOURNAL);
        $series = ['A26' => ['number' => 'A26000007', 'hash' => strtoupper(hash('sha256', 'doc7')), 'seq' => 7]];
        $this->answer(7, 7, $series);

        $state = $this->sync()->run(self::at());

        $this->assertSame([self::JOURNAL], $this->asked);
        $this->assertSame([], $this->rows());
        $this->assertSame(self::JOURNAL, $state['journal_id']);
        $this->assertSame($series, $this->locations->primary()->readShopHead()['heads'][self::JOURNAL]['series'], 'stored for the guard in a folder made for it');
        $this->assertNull($this->locations->primary()->journalId(), 'no journal is created');
    }

    public function testNoJournalAndNoSettingDoesNothing(): void
    {
        $this->assertNull($this->sync()->run(self::at()));
        $this->assertSame([], $this->asked);
    }

    public function testBothJournalsAreAskedForWhenTheSettingNamesAnother(): void
    {
        $this->journal(1);
        $other = '00000000-0000-4000-8000-000000000002';
        (new FiscalJournalIdentity($this->connection))->store($other);
        $this->answer(0, 0);
        $this->heads[] = ['status' => PsJournalClient::OK, 'head' => ['journal_id' => $other, 'last_seq' => 3, 'contiguous_seq' => 3, 'series' => []], 'error' => null];

        $this->sync()->run(self::at());

        $this->assertSame([self::JOURNAL, $other], $this->asked);
        $this->assertSame([self::JOURNAL, $other], array_keys($this->locations->primary()->readShopHead()['heads']));
    }
}
