<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Exchange\ExchangeService;
use VenPosTPV\Service\Fiscal\FiscalChainVerifier;
use VenPosTPV\Service\Fiscal\Journal\CanonicalJson;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalFlushResult;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIntents;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalWriter;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplay;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplayResult;
use VenPosTPV\Service\Fiscal\Journal\JournalLocation;
use VenPosTPV\Service\Invoice\InvoiceIssuer;
use VenPosTPV\Service\PrestaShop\CouponCreationService;
use VenPosTPV\Tests\Integration\Service\Invoice\InvoiceFixtures;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * fiscal-journal S6, S7, S10, S11 on MySQL with a real dump and restore: the
 * documents issued after the backup are put back from the journal with their
 * original ids, hashes and rows; the chain verifies, the counters go on, the guard
 * lets the till sell again and the next number follows the last replayed one.
 */
final class FiscalReplayMySqlIntegrationTest extends TestCase
{
    private const RESTORE_DB = 'venposjournal_replay_restore';

    /** The tables the replay writes (stock, Z reports and the like are not restored, D8.4). */
    private const TABLES = [
        'fiscal_record' => 'id', 'fiscal_chain' => 'id', 'order_meta' => 'id', 'sale_line' => 'id', 'movement' => 'id',
        'transfer_payment' => 'id', 'issued_coupon' => 'id', 'refund' => 'id', 'refund_line' => 'id', 'exchange' => 'id',
        'invoice' => 'id', 'outbox' => 'id', 'session' => 'id',
        'ticket_sequence' => 'letter, year', 'credit_note_sequence' => 'year', 'invoice_sequence' => 'series, year',
    ];

    /** Re-queued rows: when to try again is set by the replay. */
    private const OUTBOX_TIMES = ['next_attempt_at', 'updated_at'];

    private ?Connection $connection = null;
    private ?Connection $restored = null;
    private JournalPathFixtures $fixtures;
    private JournalHarness $journal;
    private string $dump = '';
    private bool $userAdded = false;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        if (!JournalRestore::available()) {
            $this->markTestSkipped('MySQL test container unavailable for the restore');
        }
        $this->fixtures = new JournalPathFixtures($this->connection, fn (string $class) => $this->createMock($class));
        $this->fixtures->reset();
        (new InvoiceFixtures($this->connection))->reset();
        $this->fixtures->install();
        // A full invoice needs the shop's legal name and address.
        $this->connection->update('terminal', ['legal_name' => 'Tienda Ejemplo SL', 'store_address' => 'Calle Mayor 1, 28001 Madrid'], ['id' => JournalPathFixtures::TERMINAL]);
        // The replay checks that every user it names exists (D8.1); the fixtures sell as user 1.
        if ($this->connection->fetchOne('SELECT 1 FROM user WHERE id = 1') === false) {
            $this->connection->insert('user', ['id' => 1, 'role_id' => (int) $this->connection->fetchOne('SELECT MIN(id) FROM role'), 'name' => 'Replay cashier', 'pin_hash' => 'x', 'active' => 1]);
            $this->userAdded = true;
        }
        $this->journal = new JournalHarness($this->connection);
        $this->journal->writer->createJournal();
        $this->fixtures->guard = new FiscalJournalGuard($this->connection, $this->journal->locations);
    }

    protected function tearDown(): void
    {
        $this->restored?->close();
        $this->restored = null;
        if ($this->connection !== null) {
            JournalRestore::drop(self::RESTORE_DB);
            $this->fixtures->reset();
            (new InvoiceFixtures($this->connection))->reset();
            if ($this->userAdded) {
                $this->connection->executeStatement('DELETE FROM user WHERE id = 1');
            }
            $this->connection->close();
            $this->journal->remove();
        }
        if ($this->dump !== '' && is_file($this->dump)) {
            unlink($this->dump);
        }
        $this->connection = null;
    }

    /** @return array<string, mixed> */
    private function sell(string $method = 'cash'): array
    {
        $total = JournalPathFixtures::PRICE;

        return $this->fixtures->checkout($this->fixtures->recorder($this->journal->intents))
            ->createOrder(1, $this->fixtures->cart() + ($method === 'cash' ? ['cash_amount' => $total] : []), $method, $method === 'cash' ? $total : 0.0);
    }

    private function flush(): void
    {
        $this->assertSame(FiscalJournalFlushResult::OK, $this->journal->writer->flush()->status);
    }

    private function backup(): void
    {
        $this->dump = JournalRestore::dump($this->connection);
    }

    private function restore(): Connection
    {
        return $this->restored = JournalRestore::restore($this->dump, self::RESTORE_DB);
    }

    private function replay(Connection $connection, ?\Closure $afterDocument = null): FiscalReplay
    {
        return new FiscalReplay($connection, $this->journal->locations, $this->journal->root . '/var/fiscal-replay.lock', afterDocument: $afterDocument);
    }

    /**
     * The rows of the tables the replay writes, outbox rows without the times of
     * their next attempt.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function tables(Connection $connection): array
    {
        $tables = [];
        foreach (self::TABLES as $table => $order) {
            $rows = $connection->fetchAllAssociative(sprintf('SELECT * FROM `%s` ORDER BY %s', $table, $order));
            if ($table === 'outbox') {
                $rows = array_map(static fn (array $row): array => array_diff_key($row, array_flip(self::OUTBOX_TIMES)), $rows);
            }
            $tables[$table] = $rows;
        }

        return $tables;
    }

    /** @return list<string> */
    private static function numbers(Connection $connection, int $afterId = 0): array
    {
        return array_map('strval', $connection->fetchFirstColumn('SELECT number FROM fiscal_record WHERE id > ? ORDER BY id', [$afterId]));
    }

    /**
     * Ten sales before the backup; after it a sale, its credit note, a coupon sale, a
     * sale paid by transfer, a full invoice of it, an exchange of a sale from before
     * the backup (credit note + sale) and three sales: documents 11 to 20.
     */
    private function twentyDocumentsWithABackupAtTen(): void
    {
        foreach (range(1, 10) as $ignored) {
            $sold = $this->sell();
        }
        $beforeBackup = (int) $sold['order_id'];
        $this->flush();
        $this->backup();

        $sale = $this->sell();
        $this->fixtures->refunds($this->fixtures->recorder($this->journal->intents), null, $this->journal->intents)
            ->processSaleRefund(1, $sale['order_id'], null, 'size');

        $client = $this->createMock(PsOrderClient::class);
        $client->method('verifyCouponQuote')->willReturn([
            'cart_id' => 17, 'customer_id' => 1, 'shop_id' => 1, 'currency_id' => 1, 'coupon_id' => 3, 'coupon_code' => 'SAVE2',
            'coupon_expires_at' => '2030-01-01 00:00:00', 'products' => [['id_product' => JournalPathFixtures::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 1]],
            'before_discount_total' => JournalPathFixtures::PRICE, 'discount_amount' => 2.10, 'total_paid' => 10.0, 'expires_at' => time() + 600,
        ]);
        $client->method('createCouponOrder')->willReturn(['order_id' => 947101, 'reference' => 'XKBKNABJK', 'total_paid' => 10.0]);
        $coupon = $this->fixtures->checkout($this->fixtures->recorder($this->journal->intents), $client, true)
            ->createOrder(1, $this->fixtures->cart() + ['coupon' => ['code' => 'SAVE2', 'preview_cart_id' => 17, 'quote' => 'signed.quote'], 'cash_amount' => 10.0], 'cash', 10.0);
        $this->assertFalse($coupon['saga_error']);

        $transfer = $this->sell('transfer');
        $customer = (new InvoiceFixtures($this->connection))->customer();
        (new InvoiceIssuer($this->connection, fiscal: $this->fixtures->recorder($this->journal->intents)))
            ->redeem((int) $transfer['order_id'], $customer, JournalPathFixtures::TERMINAL, 1, false, null, new \DateTimeImmutable());

        $recorder = $this->fixtures->recorder($this->journal->intents);
        $exchange = new ExchangeService($this->fixtures->refunds($recorder, null, $this->journal->intents), $this->fixtures->checkout($recorder), $this->connection, $this->createMock(CouponCreationService::class), $this->fixtures->cash());
        $result = $exchange->processExchange(1, 0, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size', '', $this->fixtures->cart(2), 'cash', 20.0, false, 0, $beforeBackup);
        $this->assertSame('done', $result['status']);

        foreach (range(1, 3) as $ignored) {
            $this->sell();
        }
        $this->flush();
        $this->assertSame(20, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fiscal_record'));
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM transfer_payment WHERE order_id = ?', [$transfer['order_id']]));
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM exchange'));
    }

    public function testDocumentsIssuedAfterTheBackupAreReplayedVerbatimAndTheTillGoesOn(): void
    {
        $this->twentyDocumentsWithABackupAtTen();
        $live = self::tables($this->connection);
        $issuedAfter = self::numbers($this->connection, 10);
        $restored = $this->restore();
        $this->assertSame(10, (int) $restored->fetchOne('SELECT COUNT(*) FROM fiscal_record'));
        $this->assertSame(FiscalJournalGuard::BEHIND_JOURNAL, (new FiscalJournalGuard($restored, $this->journal->locations))->check()['state']);
        $before = self::tables($restored);

        // Default: a dry run that lists the documents and writes nothing.
        $dry = $this->replay($restored)->run(false);

        $this->assertSame(FiscalReplayResult::PENDING, $dry->status, (string) $dry->message);
        $this->assertSame(2, $dry->exitCode());
        $this->assertSame($issuedAfter, array_column($dry->documents, 'number'));
        $this->assertSame(range(11, 20), array_column($dry->documents, 'seq'));
        $this->assertSame($before, self::tables($restored), 'a dry run writes nothing');

        $done = $this->replay($restored)->run(true);

        $this->assertSame(FiscalReplayResult::DONE, $done->status, (string) $done->message);
        $this->assertSame(0, $done->exitCode());
        $this->assertSame($issuedAfter, array_column($done->documents, 'number'));
        $after = self::tables($restored);
        foreach ($live as $table => $rows) {
            $this->assertSame($rows, $after[$table], $table . ': the rows of the documents with their original ids');
        }
        $chain = (new FiscalChainVerifier($restored))->verify();
        $this->assertTrue($chain['ok'], (string) $chain['reason']);
        $this->assertSame(20, $chain['records']);
        $this->assertSame(FiscalJournalGuard::OK, (new FiscalJournalGuard($restored, $this->journal->locations))->check()['state'], 'the till may issue again');
        $this->assertNotSame([], $restored->fetchFirstColumn("SELECT id FROM outbox WHERE status = 'pending'"), 'uploads re-queued');

        // Idempotent: a second run finds nothing and changes nothing.
        $again = $this->replay($restored)->run(true);
        $this->assertSame(FiscalReplayResult::NOTHING, $again->status, (string) $again->message);
        $this->assertSame(0, $again->exitCode());
        $this->assertSame($after, self::tables($restored));

        // The till goes on: the next ticket follows the last replayed one, new ids follow the restored ones.
        $lastTicket = (int) $restored->fetchOne('SELECT last_number FROM ticket_sequence WHERE letter = ?', [JournalPathFixtures::LETTER]);
        $maxOrder = (int) $restored->fetchOne('SELECT MAX(id) FROM order_meta');
        $intents = new FiscalJournalIntents($this->journal->locations);
        $number = JournalIssue::ticket($restored, $intents, JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION);
        $this->assertSame(sprintf('%s%s%06d', JournalPathFixtures::LETTER, date('y'), $lastTicket + 1), $number);
        $this->assertGreaterThan($maxOrder, (int) $restored->fetchOne('SELECT id FROM order_meta WHERE reference = ?', [$number]));
        $this->assertSame(21, (int) $restored->fetchOne('SELECT id FROM fiscal_record WHERE number = ?', [$number]));
        $flushed = (new FiscalJournalWriter($restored, $this->journal->locations, $intents))->flush();
        $this->assertSame(FiscalJournalFlushResult::OK, $flushed->status);
        $this->assertSame(1, $flushed->written);
        $lines = $this->journal->lines();
        $this->assertCount(21, $lines);
        $this->assertSame($number, $lines[20]['fiscal_record']['number']);
    }

    public function testAReplayKilledInTheMiddleLeavesTheDatabaseAsItWasAndTheNextRunEndsIdentical(): void
    {
        $this->twentyDocumentsWithABackupAtTen();
        $live = self::tables($this->connection);
        $restored = $this->restore();
        $before = self::tables($restored);

        // Killed (SIGKILL) right after document 15 was written in its transaction.
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/replay_worker.php', $this->journal->root, self::RESTORE_DB, '5'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $this->assertStringContainsString('killing after 5', $output);
        $this->assertNotSame(0, $status, $output);

        $restored->close();
        $restored = $this->restored = JournalRestore::connect(self::RESTORE_DB);
        $this->assertSame($before, self::tables($restored), 'the killed run left nothing');
        $this->assertSame(FiscalJournalGuard::BEHIND_JOURNAL, (new FiscalJournalGuard($restored, $this->journal->locations))->check()['state']);

        $done = $this->replay($restored)->run(true);

        $this->assertSame(FiscalReplayResult::DONE, $done->status, (string) $done->message);
        $this->assertCount(10, $done->documents);
        $this->assertSame($live, self::tables($restored));
        $this->assertTrue((new FiscalChainVerifier($restored))->verify()['ok']);
    }

    public function testAFailureInTheMiddleRollsEverythingBack(): void
    {
        $this->twentyDocumentsWithABackupAtTen();
        $restored = $this->restore();
        $before = self::tables($restored);

        $failed = $this->replay($restored, static function (array $line, int $done): void {
            if ($done === 5) {
                throw new \RuntimeException('disk gone');
            }
        })->run(true);

        $this->assertSame(FiscalReplayResult::ERROR, $failed->status);
        $this->assertSame(1, $failed->exitCode());
        $this->assertStringContainsString('disk gone', (string) $failed->message);
        $this->assertSame($before, self::tables($restored));
    }

    public function testABackupOlderThanTheJournalIsRefusedWithExitThreeAndTheGuardStays(): void
    {
        foreach (range(1, 2) as $ignored) {
            $this->sell();
        }
        $this->backup();
        foreach (range(1, 3) as $ignored) {
            $this->sell();
        }
        $this->flush();
        // The journal kept starts at document 4, as one started anew (A6) after document 3.
        $this->keepOnlyLinesFrom(4);
        $restored = $this->restore();
        $before = self::tables($restored);
        $first = $this->journal->lines()[0]['fiscal_record'];

        $result = $this->replay($restored)->run(true);

        $this->assertSame(FiscalReplayResult::TOO_OLD, $result->status, (string) $result->message);
        $this->assertSame(3, $result->exitCode());
        $date = (new \DateTimeImmutable((string) $first['generated_at']))->format('d/m/Y');
        $this->assertSame(sprintf('La copia restaurada es anterior al diario conservado (desde %s). Restaura una copia más reciente.', $date), $result->message);
        $this->assertSame($before, self::tables($restored));
        $this->assertSame(FiscalJournalGuard::BEHIND_JOURNAL, (new FiscalJournalGuard($restored, $this->journal->locations))->check()['state'], 'the guard stays active');
    }

    public function testTheCounterYearOfTheLineIsRestoredNotOneDerivedFromDates(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $this->backup();
            // 23:30 UTC on 31 December: the counter year is 2025 (PHP zone), the record is dated 1 January 2026 in Madrid.
            $number = JournalIssue::ticket($this->connection, $this->journal->intents, JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION, true, new \DateTimeImmutable('2025-12-31 23:30:00', new \DateTimeZone('UTC')));
            $this->flush();
            $restored = $this->restore();

            $done = $this->replay($restored)->run(true);
        } finally {
            date_default_timezone_set($zone);
        }

        $this->assertSame(FiscalReplayResult::DONE, $done->status, (string) $done->message);
        $this->assertSame(JournalPathFixtures::LETTER . '25000001', $number);
        $this->assertSame('01-01-2026', $restored->fetchOne('SELECT issue_date FROM fiscal_record WHERE number = ?', [$number]));
        $this->assertSame(
            [['letter' => JournalPathFixtures::LETTER, 'year' => 2025, 'last_number' => 1]],
            array_map(static fn (array $row): array => ['letter' => $row['letter'], 'year' => (int) $row['year'], 'last_number' => (int) $row['last_number']], $restored->fetchAllAssociative('SELECT * FROM ticket_sequence WHERE letter = ? ORDER BY year', [JournalPathFixtures::LETTER]))
        );
    }

    public function testACounterAheadOfTheJournalIsNeverLowered(): void
    {
        $this->sell();
        $this->backup();
        $this->sell();
        $this->flush();
        $restored = $this->restore();
        $restored->executeStatement('UPDATE ticket_sequence SET last_number = 40 WHERE letter = ?', [JournalPathFixtures::LETTER]);

        $done = $this->replay($restored)->run(true);

        $this->assertSame(FiscalReplayResult::DONE, $done->status, (string) $done->message);
        $this->assertSame(40, (int) $restored->fetchOne('SELECT last_number FROM ticket_sequence WHERE letter = ?', [JournalPathFixtures::LETTER]));
    }

    public function testAnIdTakenByAnotherRowIsAConflictAndNothingIsWritten(): void
    {
        $this->sell();
        $this->backup();
        $sale = $this->sell();
        $this->flush();
        $restored = $this->restore();
        // Something else took the id of the sale issued after the backup.
        $restored->insert('order_meta', ['id' => $sale['order_id'], 'reference' => 'Z99000001', 'terminal_id' => JournalPathFixtures::TERMINAL, 'session_id' => JournalPathFixtures::SESSION, 'user_id' => 1, 'payment_breakdown' => '{}', 'created_at' => '2026-11-14 10:00:00']);
        $before = self::tables($restored);

        foreach ([false, true] as $apply) {
            $result = $this->replay($restored)->run($apply);

            $this->assertSame(FiscalReplayResult::CONFLICT, $result->status, (string) $result->message);
            $this->assertSame(4, $result->exitCode());
            $this->assertStringContainsString(sprintf('order_meta id %d', $sale['order_id']), (string) $result->message);
            $this->assertSame($before, self::tables($restored));
        }
    }

    public function testADamagedJournalIsRefused(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->sell();
        }
        $this->flush();
        $file = $this->journal->primary()->journalFiles()[0];
        $lines = file($file);
        $lines[1] = str_replace('"seq":2', '"seq":7', $lines[1]);
        file_put_contents($file, implode('', $lines));
        $before = self::tables($this->connection);

        $result = $this->replay($this->connection)->run(true);

        $this->assertSame(FiscalReplayResult::ERROR, $result->status);
        $this->assertStringContainsString('El diario fiscal está dañado', (string) $result->message);
        $this->assertStringContainsString('línea 2', (string) $result->message);
        $this->assertSame($before, self::tables($this->connection));
    }

    public function testWithTheDatabaseUpToDateThereIsNothingToDo(): void
    {
        $this->sell();
        $this->flush();

        $result = $this->replay($this->connection)->run(false);

        $this->assertSame(FiscalReplayResult::NOTHING, $result->status, (string) $result->message);
        $this->assertSame(0, $result->exitCode());
    }

    /**
     * Rewrites the primary journal so it holds only the documents from fiscal record
     * $firstId on, numbered from seq 1, as a journal started anew after a lost one.
     */
    private function keepOnlyLinesFrom(int $firstId): void
    {
        $primary = $this->journal->primary();
        $kept = array_values(array_filter($this->journal->lines(), static fn (array $line): bool => (int) $line['fiscal_record']['id'] >= $firstId));
        $primary->clearLines();
        $previous = '';
        foreach ($kept as $index => $line) {
            $line['seq'] = $index + 1;
            $line['prev'] = $previous;
            unset($line['line_hash']);
            $line['line_hash'] = CanonicalJson::lineHash($line);
            $primary->append($line);
            $previous = $line['line_hash'];
        }
        $primary->writeHead($line);
        $this->assertTrue(JournalLocation::selfValid($line));
    }
}
