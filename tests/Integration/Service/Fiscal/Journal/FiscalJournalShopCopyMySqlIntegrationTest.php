<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Fiscal\Journal\CanonicalJson;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalAlerts;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalBehindException;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIdentity;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalReconciler;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalShopSync;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalState;
use VenPosTPV\Service\Mail\BackupAlertMailer;
use VenPosTPV\Service\Sync\Outbox;
use VenPosTPV\Service\Sync\OutboxSync;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\PrestaShop\PsJournalClient;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * The shop copy of the fiscal journal end to end (fiscal-journal S8, S9, S5, D6,
 * D7) on a real database, against the module's own JournalStore
 * (FakeJournalShop): tpv:cron's journal job (FiscalJournalReconciler with
 * FiscalJournalShopSync) then its upload (OutboxSync).
 */
final class FiscalJournalShopCopyMySqlIntegrationTest extends TestCase
{
    private ?Connection $connection = null;
    private JournalPathFixtures $fixtures;
    private JournalHarness $journal;
    private FakeJournalShop $shop;
    private string $journalId = '';
    private \DateTimeImmutable $clock;
    /** @var list<string> databases to drop */
    private array $restored = [];
    /** Lines FiscalJournalShopSync queues per run (small in the tests that need several runs). */
    private int $enqueueLimit = FiscalJournalShopSync::ENQUEUE_LIMIT;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->fixtures = new JournalPathFixtures($this->connection, fn (string $class) => $this->createMock($class));
        $this->fixtures->reset();
        $this->fixtures->install();
        $this->connection->executeStatement('DELETE FROM outbox');
        $this->connection->executeStatement('DELETE FROM app_setting WHERE name IN (?, ?, ?)', [FiscalJournalAlerts::SETTING, FiscalJournalAlerts::INCIDENT_SETTING, FiscalJournalIdentity::SETTING]);
        $this->journal = new JournalHarness($this->connection);
        $this->journalId = $this->journal->writer->createJournal();
        $this->shop = new FakeJournalShop(sys_get_temp_dir() . '/venpos-fake-shop-journal-' . bin2hex(random_bytes(5)) . '.json');
        // A little ahead of the rows the tests queue (stamped with the real time), so they are due.
        $this->clock = (new \DateTimeImmutable())->modify('+30 seconds');
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->connection->executeStatement('DELETE FROM outbox');
            $this->connection->executeStatement('DELETE FROM app_setting WHERE name IN (?, ?, ?)', [FiscalJournalAlerts::SETTING, FiscalJournalAlerts::INCIDENT_SETTING, FiscalJournalIdentity::SETTING]);
            $this->fixtures->reset();
            $this->connection->close();
            $this->journal->remove();
            @unlink($this->shop->store);
            foreach ($this->restored as $name) {
                JournalRestore::drop($name);
            }
        }
        $this->connection = null;
    }

    private function reconciler(?Connection $connection = null): FiscalJournalReconciler
    {
        $connection ??= $this->connection;
        $process = $this->journal->anotherProcess();
        $mailer = $this->createMock(BackupAlertMailer::class);
        $mailer->method('send')->willReturn(true);

        return new FiscalJournalReconciler(
            $connection,
            $process->locations,
            $process->intents,
            new FiscalJournalAlerts($connection, $mailer),
            null,
            null,
            new FiscalJournalShopSync($connection, $process->locations, $this->shop->client(), enqueueLimit: $this->enqueueLimit),
        );
    }

    /** One tpv:cron minute: the journal job, then the upload. */
    private function cron(): FiscalJournalState
    {
        $state = $this->reconciler()->run($this->clock);
        $this->upload();

        return $state;
    }

    /** @return array{uploaded: int, retried: int, incidents: int, recovered: int} */
    private function upload(): array
    {
        return (new OutboxSync($this->connection, $this->createMock(PsOrderClient::class), null, fn (): \DateTimeImmutable => $this->clock, journalClient: $this->shop->client()))->run();
    }

    /** @return list<string> */
    private function issue(int $count): array
    {
        $numbers = [];
        for ($i = 0; $i < $count; ++$i) {
            $numbers[] = JournalIssue::ticket($this->connection, $this->journal->intents, JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION);
        }
        $this->journal->writer->flushAfterIssue();

        return $numbers;
    }

    /** @return list<array<string, mixed>> */
    private function journalRows(): array
    {
        return $this->connection->fetchAllAssociative("SELECT * FROM outbox WHERE type = 'journal' ORDER BY id");
    }

    private function later(int $minutes): void
    {
        $this->clock = $this->clock->modify(sprintf('+%d minutes', $minutes));
    }

    private function guardState(?Connection $connection = null): string
    {
        return (new FiscalJournalGuard($connection ?? $this->connection, $this->journal->anotherProcess()->locations))->check()['state'];
    }

    /** @return array{0: int, 1: int} the shop's last_seq and contiguous_seq of this journal */
    private function shopPositions(): array
    {
        $head = $this->shop->client()->head($this->journalId)['head'];

        return [$head['last_seq'], $head['contiguous_seq']];
    }

    private function shopHeadAnswering(int $status, string $body): FiscalJournalShopSync
    {
        // The same answer to every question (the one for every journal, then this journal's head).
        $client = new PsJournalClient('https://tienda.tutienda.com', 'x', new MockHttpClient(static fn (): MockResponse => new MockResponse($body, ['http_code' => $status])));

        return new FiscalJournalShopSync($this->connection, $this->journal->anotherProcess()->locations, $client);
    }

    public function testOneCronMinuteGetsEveryLineToTheShopOnceInSeqOrderAndTheShopHeadMatchesTheJournal(): void
    {
        $numbers = $this->issue(3);

        $first = $this->cron();

        $this->assertSame([1, 2, 3], $this->shop->sentSeqs(), 'in seq order');
        $this->assertSame([201, 201, 201], array_column(array_filter($this->shop->requests, static fn (array $r): bool => $r['seq'] !== null), 'status'));
        $stored = $this->shop->rows();
        $this->assertSame([1, 2, 3], array_column($stored, 'seq'));
        $this->assertSame($numbers, array_column($stored, 'number'));
        $journal = $this->journal->lines();
        foreach ($journal as $i => $line) {
            $this->assertSame($line['line_hash'], $stored[$i]['line_hash']);
            $this->assertSame($line['line_hash'], CanonicalJson::lineHash(json_decode($stored[$i]['line'], true)), 'the shop holds the line itself');
        }
        $this->assertSame(['done', 'done', 'done'], array_column($this->journalRows(), 'status'));
        $this->assertSame(FiscalJournalShopSync::PENDING, $first->data['shop']['status'], 'the head was asked before the upload');

        // The next minute: the shop head is the journal head, nothing is sent again.
        $this->shop->requests = [];
        $second = $this->cron();
        $this->assertSame([], $this->shop->sentSeqs());
        $this->assertSame(['heads'], array_column($this->shop->requests, 'action'), 'one question a minute, for every journal');
        $shop = $second->data['shop'];
        $this->assertSame(FiscalJournalShopSync::OK, $shop['status']);
        $this->assertSame(['number' => $numbers[2], 'seq' => 3], $shop['last_document']);
        $this->assertSame([3, 3, 3], [$shop['journal_seq'], $shop['last_seq'], $shop['contiguous_seq']]);
        $this->assertSame($journal[2]['fiscal_record']['hash'], $this->journal->primary()->readShopHead()['heads'][$this->journalId]['series'][$journal[2]['series']]['hash'], 'stored for the guard');
        $this->assertSame(FiscalJournalGuard::OK, (new FiscalJournalGuard($this->connection, $this->journal->locations))->check()['state']);
    }

    public function testTheSameLineSentAgainIsStoredOnceAndIsSuccess(): void
    {
        $this->issue(2);
        $this->cron();
        $line = $this->journal->lines()[1];
        $this->connection->transactional(fn () => (new Outbox($this->connection))->enqueueJournal($this->journalId, $line));
        $this->shop->requests = [];

        $counts = $this->upload();

        $this->assertSame(1, $counts['uploaded']);
        $this->assertSame([['status' => 200, 'action' => null, 'seq' => 2, 'number' => $line['fiscal_record']['number']]], $this->shop->requests, 'answered as a duplicate');
        $this->assertCount(2, $this->shop->rows());
        $this->assertSame(['done', 'done', 'done'], array_column($this->journalRows(), 'status'));
    }

    public function testAShopWithAnOlderModuleNeverBlocksSellingAndTheLinesWaitThenUploadOnceItIsUpgraded(): void
    {
        $this->shop->mode = FakeJournalShop::OLD_MODULE;
        $this->issue(2);

        $state = $this->cron();

        $this->assertSame(FiscalJournalShopSync::UNAVAILABLE, $state->data['shop']['status']);
        $this->assertSame([1], $this->shop->sentSeqs(), 'one request, the later line waits behind it');
        $rows = $this->journalRows();
        $this->assertSame(['pending', 'pending'], array_column($rows, 'status'), 'no incident');
        $this->assertSame(OutboxSync::JOURNAL_MODULE_UNAVAILABLE, $rows[0]['last_error']);
        $this->assertSame($this->clock->modify('+60 minutes')->format('Y-m-d H:i:s'), $rows[0]['next_attempt_at']);
        $this->assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM outbox WHERE status = 'incident'"));
        $this->assertSame(0, $state->exitCode);

        // Minutes go by: no retry storm, and the till keeps selling.
        $this->shop->requests = [];
        foreach (range(1, 5) as $minute) {
            $this->later(1);
            $this->cron();
        }
        $this->assertSame([], $this->shop->sentSeqs(), 'nothing sent while the hour runs');
        $this->assertSame(array_merge(...array_fill(0, 5, ['heads', 'head'])), array_column($this->shop->requests, 'action'), 'every journal, then this one, once a minute');
        $this->issue(1);
        (new FiscalJournalGuard($this->connection, $this->journal->locations))->assertCanIssue();

        // The module is upgraded: the next minute sends everything, in order.
        $this->shop->mode = FakeJournalShop::MODULE;
        $this->shop->requests = [];
        $this->later(1);
        $this->cron();
        $this->assertSame([1, 2, 3], $this->shop->sentSeqs());
        $this->assertSame(['done', 'done', 'done'], array_column($this->journalRows(), 'status'));
        $this->assertSame([1, 2, 3], array_column($this->shop->rows(), 'seq'));
    }

    public function testAConflictIsAnIncidentThatIsNeverRetriedNorOverwrittenAndLaterLinesGoOn(): void
    {
        $this->issue(3);
        // The shop already holds another line at seq 2 of this journal.
        $other = $this->journal->lines()[1];
        $other['journaled_at'] = '2026-01-01T00:00:00+01:00';
        $other['line_hash'] = CanonicalJson::lineHash($other);
        $this->shop->setRows([['journal_id' => $this->journalId, 'seq' => 2, 'series' => $other['series'], 'number' => $other['fiscal_record']['number'], 'invoice_type' => 'F2', 'hash' => strtolower(hash('sha256', 'another document')), 'line_hash' => $other['line_hash'], 'line' => CanonicalJson::encode($other)]]);

        $this->cron();

        $rows = $this->journalRows();
        $this->assertSame(['done', 'incident', 'done'], array_column($rows, 'status'));
        $this->assertStringContainsString('La tienda ya tiene otra línea en la posición 2 del diario fiscal', (string) $rows[1]['last_error']);
        $this->assertStringContainsString('Another line is stored at this position', (string) $rows[1]['last_error']);
        $this->assertSame($other['line_hash'], array_column($this->shop->rows(), 'line_hash', 'seq')[2], 'never overwritten');

        $this->shop->requests = [];
        $this->later(90);
        $state = $this->cron();
        $this->assertSame([], $this->shop->sentSeqs(), 'never retried by itself, and the gap resends nothing');
        $this->assertSame(FiscalJournalShopSync::INCIDENTS, $state->data['shop']['status']);
        $this->assertSame(1, $state->data['shop']['incidents']);
    }

    public function testANumberTheModuleCannotStoreIsAnIncidentBeforeAnythingIsSentAndLaterLinesGoOn(): void
    {
        $this->issue(1);
        $this->cron();
        // O6: a coupon sale numbered by a shop with customised order references.
        $odd = $this->journal->lines()[0];
        $odd['seq'] = 2;
        $odd['kind'] = 'F2';
        $odd['series'] = 'PS';
        $odd['counter'] = null;
        $odd['fiscal_record']['number'] = 'PS 0001#';
        $odd['fiscal_record']['hash'] = strtoupper(hash('sha256', 'odd'));
        $odd['prev'] = $this->journal->lines()[0]['line_hash'];
        $odd['line_hash'] = CanonicalJson::lineHash($odd);
        $next = $this->journal->lines()[0];
        $next['seq'] = 3;
        $next['fiscal_record']['number'] = 'J26999999';
        $next['fiscal_record']['hash'] = strtoupper(hash('sha256', 'next'));
        $next['prev'] = $odd['line_hash'];
        $next['line_hash'] = CanonicalJson::lineHash($next);
        $outbox = new Outbox($this->connection);
        $this->connection->transactional(function () use ($outbox, $odd, $next): void {
            $outbox->enqueueJournal($this->journalId, $odd);
            $outbox->enqueueJournal($this->journalId, $next);
        });
        $this->shop->requests = [];

        $counts = $this->upload();

        $this->assertSame(1, $counts['incidents']);
        $this->assertSame([3], $this->shop->sentSeqs(), 'the odd one is never sent; the next one is');
        $incident = $this->connection->fetchAssociative("SELECT * FROM outbox WHERE type = 'journal' AND sequence = 2");
        $this->assertSame('incident', $incident['status']);
        $this->assertSame('El número "PS 0001#" no cabe en la copia de la tienda (solo letras, cifras y . _ / -, hasta 60): el documento queda solo en el diario del servidor.', $incident['last_error']);
    }

    public function testALineInBackoffHoldsTheLaterOnesBackUntilItIsSent(): void
    {
        $this->issue(2);
        $this->reconciler()->run($this->clock);
        $this->shop->requests = [];
        $this->shop->failNext = [503];

        $this->upload();

        $this->assertSame([1], $this->shop->sentSeqs(), 'seq 2 is not sent before seq 1');
        $this->assertSame(['pending', 'pending'], array_column($this->journalRows(), 'status'));
        $this->assertSame([503], array_column($this->shop->requests, 'status'));

        $this->later(2);
        $this->upload();
        $this->assertSame([1, 1, 2], $this->shop->sentSeqs());
        $this->assertSame([1, 2], array_column($this->shop->rows(), 'seq'));
    }

    public function testRowsLostInARestoreOfTheDatabaseAreQueuedAgainFromWhatTheShopLacks(): void
    {
        $this->issue(3);
        $this->shop->mode = FakeJournalShop::DOWN;
        $state = $this->cron();
        $this->assertSame(FiscalJournalShopSync::UNREACHABLE, $state->data['shop']['status']);
        $this->assertSame(FiscalJournalGuard::OK, (new FiscalJournalGuard($this->connection, $this->journal->locations))->check()['state'], 'a shop down is never a block');
        // The outbox rows are gone (the database came back from a backup without them).
        $this->connection->executeStatement("DELETE FROM outbox WHERE type = 'journal'");
        $this->shop->mode = FakeJournalShop::MODULE;
        $this->later(1);

        $this->cron();

        $this->assertSame([1, 2, 3], array_column($this->shop->rows(), 'seq'));
    }

    public function testARestoredDatabaseOnAServerThatLostItsJournalBlocksUntilTheShopAnswersThenOnlyIfTheShopHoldsMore(): void
    {
        if (!JournalRestore::available()) {
            $this->markTestSkipped('MySQL test container unavailable for the restore');
        }
        (new FiscalJournalIdentity($this->connection))->store($this->journalId);
        $this->issue(2);
        $this->cron();
        $dump = JournalRestore::dump($this->connection);
        try {
            $third = $this->issue(1)[0];
            $this->cron();
            $this->assertCount(3, $this->shop->rows(), 'the shop holds the ticket issued after the backup');

            $name = TestDatabase::settings()['name'] . '_shop_restore';
            $this->restored[] = $name;
            $restored = JournalRestore::restore($dump, $name);
        } finally {
            @unlink($dump);
        }
        // A new server: the database from the backup, no journal folders.
        $this->journal->remove();
        $guard = new FiscalJournalGuard($restored, $this->journal->locations);
        try {
            $guard->assertCanIssue();
            $this->fail('a lost journal must block until the shop answers');
        } catch (FiscalJournalBehindException $e) {
            $this->assertSame(FiscalJournalGuard::JOURNAL_MISSING, $e->state);
        }

        $state = $this->reconciler($restored)->run($this->clock);

        $this->assertSame(FiscalJournalState::JOURNAL_MISSING, $state->status());
        $this->assertSame($this->journalId, $state->data['journal_id']);
        $this->assertNull($this->journal->primary()->journalId(), 'the job never creates a journal over a lost one');
        $report = $guard->report();
        $this->assertSame(FiscalJournalGuard::BEHIND_SHOP, $report['state']);
        $this->assertSame($third, $report['missing']['number']);

        // The live database (not behind) on the same lost-journal server: the shop confirms it.
        $live = new FiscalJournalGuard($this->connection, $this->journal->locations);
        $live->assertCanIssue();
        $restored->close();
    }

    public function testAWrongSecretRetriesThroughTheBackoffLadderThenIsAnIncidentWhileLaterLinesGoOnOnceItIsFixed(): void
    {
        $this->issue(2);
        $this->reconciler()->run($this->clock);
        $this->shop->requests = [];
        // Every line is refused while the secret is wrong: the ladder of line 1, then line 2 once.
        $this->shop->failNext = array_fill(0, \count(OutboxSync::BACKOFF_MINUTES) + 1, 401);
        $waits = [];

        foreach (OutboxSync::BACKOFF_MINUTES as $ignored) {
            $this->upload();
            $first = $this->journalRows()[0];
            if ($first['status'] === 'pending') {
                $waits[] = (int) round((strtotime((string) $first['next_attempt_at']) - $this->clock->getTimestamp()) / 60);
                $this->clock = new \DateTimeImmutable($first['next_attempt_at'] . ' +1 second');
            }
        }

        $rows = $this->journalRows();
        $this->assertSame(\array_slice(OutboxSync::BACKOFF_MINUTES, 0, -1), $waits, 'the usual backoff ladder first');
        $this->assertSame(['incident', 'pending'], array_column($rows, 'status'), 'then the administrator sees it');
        $this->assertSame(\count(OutboxSync::BACKOFF_MINUTES), (int) $rows[0]['attempts']);
        $this->assertStringContainsString('401', (string) $rows[0]['last_error']);
        $this->assertStringContainsString('clave', (string) $rows[0]['last_error']);
        $this->assertStringNotContainsString(FakeJournalShop::SECRET, (string) $rows[0]['last_error']);
        $this->assertSame([1, 1, 1, 1, 1, 1, 2], $this->shop->sentSeqs(), 'seq 2 waited behind seq 1 while it was retried');
        $this->assertSame(1, (int) $rows[1]['attempts'], 'line 2 starts its own ladder');

        // The secret is fixed: the later line goes on by itself.
        $this->later(1);
        $this->shop->requests = [];
        $this->cron();
        $this->assertSame([2], $this->shop->sentSeqs());
        $this->assertSame(['incident', 'done'], array_column($this->journalRows(), 'status'));
    }

    public function testAfterARestoreOfTheDatabaseNothingTheShopHoldsIsSentAgain(): void
    {
        $this->issue(3);
        $this->cron();
        $this->later(1);
        $this->cron();
        $this->assertCount(3, $this->shop->rows());
        // The database comes back from a backup taken after the first line was queued.
        $this->connection->executeStatement("DELETE FROM outbox WHERE type = 'journal' AND sequence > 1");
        $this->issue(1);

        // The shop cannot be asked this minute: its head stored a minute ago is used.
        $this->later(1);
        $this->shop->mode = FakeJournalShop::DOWN;
        $this->shop->requests = [];
        $this->reconciler()->run($this->clock);
        $this->assertSame([1, 4], array_map('intval', array_column($this->journalRows(), 'sequence')), 'only the line the shop lacks is queued');

        $this->shop->mode = FakeJournalShop::MODULE;
        $this->later(1);
        $this->shop->requests = [];
        $this->cron();
        $this->later(1);
        $this->cron();

        $this->assertSame([4], $this->shop->sentSeqs(), 'nothing the shop holds is sent again');
        $this->assertSame([1, 2, 3, 4], array_column($this->shop->rows(), 'seq'));
        $this->assertSame(['done', 'done'], array_column($this->journalRows(), 'status'));
    }

    public function testAStaleStoredShopHeadIsNotTrustedAfterARestoreOfTheDatabase(): void
    {
        $this->issue(2);
        $this->cron();
        $this->connection->executeStatement("DELETE FROM outbox WHERE type = 'journal'");
        $this->later(FiscalJournalShopSync::HEAD_FRESH_SECONDS / 60 + 5);
        $this->shop->mode = FakeJournalShop::DOWN;

        $this->reconciler()->run($this->clock);

        $this->assertSame([1, 2], array_map('intval', array_column($this->journalRows(), 'sequence')), 'an old head may no longer hold: queued again, the shop dedupes');
    }

    public function testAShopRestoredFarBehindAnIncidentGapGetsEveryLineBackOverLaterRunsAndThenNothingLoops(): void
    {
        $this->enqueueLimit = 2;
        $this->issue(1);
        $this->cron();
        $this->issue(6);
        // Line 2 is refused for good: the shop's contiguous_seq stays at 1.
        $this->reconciler()->run($this->clock);
        $this->shop->failNext = [422];
        $this->upload();
        for ($minute = 0; $minute < 3; ++$minute) {
            $this->later(1);
            $this->cron();
        }
        $this->assertSame([1, 3, 4, 5, 6, 7], array_column($this->shop->rows(), 'seq'));
        $this->assertSame('incident', $this->journalRows()[1]['status']);

        // The shop comes back from its own backup holding only line 1.
        $this->shop->setRows(\array_slice($this->shop->rows(), 0, 1));
        $this->shop->requests = [];
        for ($minute = 0; $minute < 6; ++$minute) {
            $this->later(1);
            $this->cron();
        }

        $this->assertSame([1, 3, 4, 5, 6, 7], array_column($this->shop->rows(), 'seq'), 'every line past the gap is back, two queued per run');
        $this->assertSame([3, 4, 5, 6, 7], $this->shop->sentSeqs(), 'each once, the refused line never');

        // From now on: nothing re-queued, nothing re-sent, the incident stays visible.
        $rows = \count($this->journalRows());
        $this->shop->requests = [];
        for ($minute = 0; $minute < 10; ++$minute) {
            $this->later(7);
            $state = $this->cron();
        }
        $this->assertSame([], $this->shop->sentSeqs());
        $this->assertSame($rows, \count($this->journalRows()), 'the outbox does not grow');
        $this->assertSame(FiscalJournalShopSync::INCIDENTS, $state->data['shop']['status']);
        $this->assertSame(1, $state->data['shop']['contiguous_seq']);

        $this->issue(1);
        $this->later(1);
        $this->cron();
        $this->assertSame([8], $this->shop->sentSeqs(), 'later lines keep flowing');
    }

    public function testAfterARestoreOfTheDatabaseBehindAnIncidentGapTheRefusedLineIsTriedOnceAndNothingLoops(): void
    {
        $this->issue(1);
        $this->cron();
        $this->issue(3);
        $this->reconciler()->run($this->clock);
        $this->shop->failNext = [422];
        $this->upload();
        $this->later(1);
        $this->cron();
        $this->assertSame(['done', 'incident', 'done', 'done'], array_column($this->journalRows(), 'status'));
        // The database comes back from a backup holding only the first row.
        $this->connection->executeStatement("DELETE FROM outbox WHERE type = 'journal' AND sequence > 1");
        $this->shop->requests = [];

        for ($minute = 0; $minute < 10; ++$minute) {
            $this->later(3);
            $this->reconciler()->run($this->clock);
            // The shop still refuses line 2 when it is sent again.
            $this->shop->failNext = $minute === 0 ? [422] : [];
            $this->upload();
        }

        $this->assertSame([2], array_map('intval', array_column(array_filter($this->journalRows(), static fn (array $r): bool => $r['status'] === 'incident'), 'sequence')), 'the incident is visible again');
        $this->assertSame([2, 3, 4], $this->shop->sentSeqs(), 'the refused line is tried once again and the shop dedupes the rest, never in a loop');
        $this->assertSame(4, \count($this->journalRows()), 'one row per line, the outbox does not grow');
        $this->assertSame([1, 3, 4], array_column($this->shop->rows(), 'seq'));
    }

    public function testAShopHeadOfThisJournalThatCannotBeUsedNeverBlocks(): void
    {
        $this->issue(2);
        $this->cron();
        $this->assertSame(FiscalJournalGuard::OK, $this->guardState());
        $foreign = '11111111-2222-4333-8444-555555555555';
        $foreignHead = ['journal_id' => $foreign, 'fetched_at' => '2099-01-01T00:00:00+00:00', 'last_seq' => 9, 'contiguous_seq' => 9, 'series' => ['J26' => ['number' => 'J26999999', 'hash' => str_repeat('A', 64), 'seq' => 9]]];
        $stored = [
            'not JSON' => '{not json',
            'another shape' => json_encode(['v' => 1, 'heads' => [$this->journalId => ['series' => 'x']]]),
            'empty' => '',
        ];
        foreach ($stored as $why => $content) {
            foreach (['backups', 'var'] as $base) {
                file_put_contents($this->journal->root . '/' . $base . '/fiscal-journal/shop-head.json', (string) $content);
            }
            $this->assertSame(FiscalJournalGuard::OK, $this->guardState(), $why);
        }
        // Another journal holding a document the database lacks is evidence too (second correction round).
        foreach (['backups', 'var'] as $base) {
            file_put_contents($this->journal->root . '/' . $base . '/fiscal-journal/shop-head.json', (string) json_encode(['v' => 1, 'heads' => [$foreign => $foreignHead]]));
        }
        $this->assertSame(FiscalJournalGuard::BEHIND_SHOP, $this->guardState(), 'another journal');
        foreach (['backups', 'var'] as $base) {
            @unlink($this->journal->root . '/' . $base . '/fiscal-journal/shop-head.json');
        }
        $this->assertSame(FiscalJournalGuard::OK, $this->guardState(), 'no stored head');

        // A shop paired with another installation answers for another journal.
        $this->shopHeadAnswering(200, (string) json_encode($foreignHead))->run($this->clock);
        $this->assertSame(FiscalJournalGuard::OK, $this->guardState(), 'another journal id');
        foreach ([500, 503, 401, 404, 302] as $status) {
            $this->shopHeadAnswering($status, '{"error":"x"}')->run($this->clock);
            $this->assertSame(FiscalJournalGuard::OK, $this->guardState(), 'head answered ' . $status);
        }

        // A head of this journal naming a document the database lacks is evidence: it blocks.
        $this->shopHeadAnswering(200, (string) json_encode(['journal_id' => strtoupper($this->journalId), 'last_seq' => 3, 'contiguous_seq' => 3, 'series' => ['J26' => ['number' => 'J26999999', 'hash' => str_repeat('a', 64), 'seq' => 3]]]))->run($this->clock);
        $this->assertSame(FiscalJournalGuard::BEHIND_SHOP, $this->guardState());
    }

    public function testADocumentCommittedAfterAnotherConnectionsSnapshotNeverBlocksIt(): void
    {
        $this->issue(1);
        $this->cron();
        $other = TestDatabase::connection();
        $this->assertNotNull($other);
        $other->beginTransaction();
        try {
            $other->fetchOne('SELECT COUNT(*) FROM fiscal_record');
            $this->issue(1);
            $this->cron();
            $this->assertSame([2, 2], $this->shopPositions());

            $this->assertSame(FiscalJournalGuard::OK, (new FiscalJournalGuard($other, $this->journal->anotherProcess()->locations))->check()['state']);
        } finally {
            $other->rollBack();
            $other->close();
        }
    }

    public function testAShopRestoredBehindNeverBlocksAndGetsItsLinesBack(): void
    {
        $this->issue(5);
        $this->cron();
        $this->assertSame([5, 5], $this->shopPositions());

        $this->shop->setRows(\array_slice($this->shop->rows(), 0, 2));
        $this->later(1);
        $this->assertSame(FiscalJournalGuard::OK, $this->guardState());
        $this->cron();
        $this->assertSame(FiscalJournalGuard::OK, $this->guardState());
        $this->later(1);
        $this->cron();
        $this->assertSame([5, 5], $this->shopPositions());

        // Restored to an empty table.
        $this->shop->setRows([]);
        $this->later(1);
        $this->cron();
        $this->later(1);
        $this->cron();
        $this->assertSame([5, 5], $this->shopPositions(), 'an empty shop is filled again');
    }

    /**
     * After a reset the archived journal's documents are all in the database, so its
     * stored head never blocks; the guard only blocks for it when the shop holds a
     * document of it the database lacks (second correction round: the shop's evidence
     * is independent of the journal id, decision C).
     */
    public function testTheStoredHeadOfTheOldJournalNeverBlocksAfterAJournalResetWhileItsDocumentsAreInTheDatabase(): void
    {
        (new FiscalJournalIdentity($this->connection))->store($this->journalId);
        $this->issue(2);
        $this->cron();
        // The next minute's answer shows the shop holding both lines of this journal.
        $this->cron();
        $old = $this->journalId;
        $this->assertSame([2, 2], $this->shopPositions());

        // The reset: the journal files archived, a new journal id in the folders and the setting.
        foreach (['backups', 'var'] as $base) {
            $dir = $this->journal->root . '/' . $base . '/fiscal-journal';
            @mkdir($dir . '/archive/x', 0700, true);
            foreach (glob($dir . '/{journal*,head.json}', \GLOB_BRACE) ?: [] as $file) {
                rename($file, $dir . '/archive/x/' . basename($file));
            }
        }
        $fresh = (new JournalHarness($this->connection, $this->journal->root))->writer->createJournal();
        (new FiscalJournalIdentity($this->connection))->store($fresh);

        $this->assertNotSame($old, $fresh);
        $this->assertSame(FiscalJournalGuard::OK, $this->guardState(), 'every document of the old journal is in the database');

        // The stored head of the old journal names a document the database lacks: that is evidence, whatever the journal.
        foreach (['backups', 'var'] as $base) {
            $file = $this->journal->root . '/' . $base . '/fiscal-journal/shop-head.json';
            $head = json_decode((string) file_get_contents($file), true);
            $head['heads'][$old]['series']['ZZ'] = ['number' => 'Z26000001', 'hash' => str_repeat('b', 64), 'seq' => 99];
            file_put_contents($file, (string) json_encode($head));
        }
        $this->assertSame(FiscalJournalGuard::BEHIND_SHOP, $this->guardState());
    }
}
