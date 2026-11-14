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
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalWriter;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIntents;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplay;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplayResult;
use VenPosTPV\Service\Invoice\InvoiceIssuer;
use VenPosTPV\Service\PrestaShop\CouponCreationService;
use VenPosTPV\Tests\Integration\Service\Invoice\InvoiceFixtures;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use VenPosTPV\Service\Cart\CartService;
use VenPosTPV\Service\Checkout\CheckoutService;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Service\Sync\Outbox;
use VenPosTPV\Service\Sync\OutboxSyncTrigger;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * Regression tests of tpv:fiscal:replay (fiscal-journal S6, D8) from the
 * independent verification of T4: a refund pending at the backup and completed
 * after it together with an exchange retried after it (B1), and a session, two
 * movements and an outbox row taken in the restored database before the replay (B2).
 */
final class FiscalReplayRestoreRegressionMySqlIntegrationTest extends TestCase
{
    private const RESTORE_DB = 'venposjournal_replay_regression';
    private const TABLES = [
        'fiscal_record' => 'id', 'fiscal_chain' => 'id', 'order_meta' => 'id', 'sale_line' => 'id', 'movement' => 'id',
        'transfer_payment' => 'id', 'issued_coupon' => 'id', 'refund' => 'id', 'refund_line' => 'id', 'exchange' => 'id',
        'invoice' => 'id', 'outbox' => 'id', 'session' => 'id',
        'ticket_sequence' => 'letter, year', 'credit_note_sequence' => 'year', 'invoice_sequence' => 'series, year',
    ];

    private ?Connection $connection = null;
    private ?Connection $restored = null;
    private JournalPathFixtures $fixtures;
    private JournalHarness $journal;
    private string $dump = '';
    private bool $userAdded = false;

    private function preClean(): void
    {
        $c = $this->connection;
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->executeStatement('DELETE FROM movement WHERE session_id IN (SELECT id FROM session WHERE terminal_id = ? AND id <> ?)', [JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION]);
        $c->executeStatement('DELETE FROM session WHERE terminal_id = ? AND id <> ?', [JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null || !JournalRestore::available()) {
            $this->markTestSkipped('MySQL test database (and its container for dump/restore) unavailable');
        }
        $this->fixtures = new JournalPathFixtures($this->connection, fn (string $class) => $this->createMock($class));
        $this->preClean();
        $this->fixtures->reset();
        (new InvoiceFixtures($this->connection))->reset();
        $this->connection->executeStatement('DELETE FROM user WHERE id = 2');
        $this->fixtures->install();
        $this->connection->update('terminal', ['legal_name' => 'Tienda Ejemplo SL', 'store_address' => 'Calle Mayor 1, 28001 Madrid'], ['id' => JournalPathFixtures::TERMINAL]);
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
        if ($this->connection === null) {
            return;
        }
        $this->restored?->close();
        $this->restored = null;
        JournalRestore::drop(self::RESTORE_DB);
        $this->preClean();
        $this->fixtures->reset();
        (new InvoiceFixtures($this->connection))->reset();
        $this->connection->executeStatement('DELETE FROM user WHERE id = 2');
        if ($this->userAdded) {
            $this->connection->executeStatement('DELETE FROM user WHERE id = 1');
        }
        $this->connection->close();
        $this->journal->remove();
        if ($this->dump !== '' && is_file($this->dump)) {
            unlink($this->dump);
        }
    }

    private function sell(string $method = 'cash', int $user = 1, ?JournalPathFixtures $fixtures = null, ?FiscalJournalIntents $intents = null): array
    {
        $f = $fixtures ?? $this->fixtures;
        $total = JournalPathFixtures::PRICE;

        return $f->checkout($f->recorder($intents ?? $this->journal->intents))
            ->createOrder($user, $f->cart() + ($method === 'cash' ? ['cash_amount' => $total] : []), $method, $method === 'cash' ? $total : 0.0);
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

    private function replay(Connection $connection, ?\Closure $after = null): FiscalReplay
    {
        return new FiscalReplay($connection, $this->journal->locations, $this->journal->root . '/var/fiscal-replay.lock', afterDocument: $after);
    }

    private static function tables(Connection $c): array
    {
        $t = [];
        foreach (self::TABLES as $table => $order) {
            $rows = $c->fetchAllAssociative(sprintf('SELECT * FROM `%s` ORDER BY %s', $table, $order));
            if ($table === 'outbox') {
                $rows = array_map(static fn (array $r): array => array_diff_key($r, array_flip(['next_attempt_at', 'updated_at'])), $rows);
            }
            $t[$table] = $rows;
        }

        return $t;
    }

    /** Every table of the database, row by row. */
    private static function everything(Connection $c): array
    {
        $all = [];
        foreach ($c->fetchFirstColumn("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") as $table) {
            $all[$table] = $c->fetchAllAssociative(sprintf('SELECT * FROM `%s`', $table));
            sort($all[$table]);
        }

        return $all;
    }

    private function assertSameTables(array $expected, array $actual, string $why): void
    {
        foreach ($expected as $table => $rows) {
            $this->assertSame($rows, $actual[$table], $why . ': ' . $table);
        }
    }

    private function scenario(bool $onlineRefund = true): void
    {
        foreach (range(1, 4) as $ignored) {
            $sold = $this->sell();
        }
        $beforeBackup = (int) $sold['order_id'];
        $this->flush();
        $this->backup();

        $sale = $this->sell();
        $this->fixtures->refunds($this->fixtures->recorder($this->journal->intents), null, $this->journal->intents)->processSaleRefund(1, $sale['order_id'], null, 'size');
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
        if ($onlineRefund) {
            $refundId = $this->fixtures->legacyPendingRefund(947160, 10.0);
            $this->fixtures->refunds($this->fixtures->recorder($this->journal->intents), $this->fixtures->slipClient(), $this->journal->intents)->retryPending(1, $refundId);
        }
        $recorder = $this->fixtures->recorder($this->journal->intents);
        $exchange = new ExchangeService($this->fixtures->refunds($recorder, null, $this->journal->intents), $this->fixtures->checkout($recorder), $this->connection, $this->createMock(CouponCreationService::class), $this->fixtures->cash());
        $result = $exchange->processExchange(1, 0, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size', '', $this->fixtures->cart(2), 'cash', 20.0, false, 0, $beforeBackup);
        $this->assertSame('done', $result['status']);
        $this->sell();
        $this->flush();
    }

    private function checkoutFailingOnce(): CheckoutService
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        return new class($this->createMock(CartService::class), $this->createMock(PsOrderClient::class), $this->connection, $this->fixtures->cash(), $bus, $this->createMock(PrinterService::class), false, null, new TicketNumberer($this->connection), new Outbox($this->connection), new OutboxSyncTrigger(), fiscalRecorder: $this->fixtures->recorder($this->journal->intents)) extends CheckoutService {
            public int $failures = 1;
            public function createOrder(int $userId, array $cartData, string $paymentMethod, float $amountTendered, float $exchangeCredit = 0.0, ?string $requestId = null): array
            {
                if ($this->failures-- > 0) { throw new \RuntimeException('timeout'); }
                return parent::createOrder($userId, $cartData, $paymentMethod, $amountTendered, $exchangeCredit, $requestId);
            }
        };
    }

    private function tillOk(Connection $c): void
    {
        $chain = (new FiscalChainVerifier($c))->verify();
        $this->assertTrue($chain['ok'], (string) ($chain['reason'] ?? ''));
        $this->assertSame('ok', (new FiscalJournalGuard($c, $this->journal->locations))->check()['state']);
    }

    /** B1: refund pending at backup completed after it + exchange credit_open at backup retried after it, in one restore. */
    public function testARefundCompletedAndAnExchangeRetriedAfterTheBackupAreReplayedTogether(): void
    {
        $s0 = $this->sell();
        $refundId = $this->fixtures->legacyPendingRefund(947160, 10.0);
        $recorder = $this->fixtures->recorder($this->journal->intents);
        $refunds = $this->fixtures->refunds($recorder, null, $this->journal->intents);
        $first = (new ExchangeService($refunds, $this->checkoutFailingOnce(), $this->connection, $this->createMock(CouponCreationService::class), $this->fixtures->cash()))
            ->processExchange(1, 0, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size', '', $this->fixtures->cart(2), 'cash', 20.0, false, 0, $s0['order_id']);
        $this->assertSame('credit_open', $first['status']);
        $this->flush();
        $this->backup();
        $this->fixtures->refunds($this->fixtures->recorder($this->journal->intents), $this->fixtures->slipClient(), $this->journal->intents)->retryPending(1, $refundId);
        $retried = (new ExchangeService($refunds, $this->fixtures->checkout($recorder), $this->connection, $this->createMock(CouponCreationService::class), $this->fixtures->cash()))->retry(1, $first['exchange_id']);
        $this->assertSame('done', $retried['status']);
        $this->sell();
        $this->flush();
        $live = self::tables($this->connection);
        $restored = $this->restore();
        $this->assertSame('pending', $restored->fetchOne('SELECT status FROM refund WHERE id = ?', [$refundId]));
        $this->assertSame('credit_open', $restored->fetchOne('SELECT status FROM exchange WHERE id = ?', [$first['exchange_id']]));
        $this->assertSame('behind_journal', (new FiscalJournalGuard($restored, $this->journal->locations))->check()['state']);
        $dry = $this->replay($restored)->run(false);
        $this->assertSame(FiscalReplayResult::PENDING, $dry->status, (string) $dry->message);
        $r = $this->replay($restored)->run(true);
        $this->assertSame(FiscalReplayResult::DONE, $r->status, (string) $r->message);
        $this->assertSameTables($live, self::tables($restored), 'B1 restored = live');
        $this->tillOk($restored);
        $after = self::everything($restored);
        $again = $this->replay($restored)->run(true);
        $this->assertSame(FiscalReplayResult::NOTHING, $again->status);
        $this->assertSame($after, self::everything($restored), 'second run changes nothing');
    }

    /** B2: session, two movements and an outbox row taken in the restored database before the replay, at once. */
    public function testRowsTakenInTheRestoredDatabaseBeforeTheReplayAreKeptAndTheReplayedOnesMoved(): void
    {
        $this->sell();
        $this->flush();
        $this->backup();
        $this->connection->executeStatement('UPDATE session SET closed_at = NOW(), counted_cash = 100 WHERE id = ?', [JournalPathFixtures::SESSION]);
        $this->connection->insert('session', ['terminal_id' => JournalPathFixtures::TERMINAL, 'user_id' => 1, 'opened_at' => '2026-11-14 12:00:00', 'opening_cash' => 50]);
        $newSession = (int) $this->connection->lastInsertId();
        $_SESSION['cash_session_id'] = $newSession;
        $sale = $this->sell();
        $this->fixtures->refunds($this->fixtures->recorder($this->journal->intents), null, $this->journal->intents)->processSaleRefund(1, $sale['order_id'], null, 'size');
        $sale2 = $this->sell();
        $this->flush();
        $live = self::tables($this->connection);
        $liveMov = $this->connection->fetchAllAssociative('SELECT * FROM movement WHERE session_id = ? ORDER BY id', [$newSession]);
        $liveOutbox = $this->connection->fetchAllAssociative('SELECT id, type, request_id, order_id, depends_on FROM outbox ORDER BY id');
        $restored = $this->restore();
        // unguarded actions on the restored database
        $restored->executeStatement('UPDATE session SET closed_at = NOW(), counted_cash = 100 WHERE id = ?', [JournalPathFixtures::SESSION]);
        $restored->insert('session', ['terminal_id' => JournalPathFixtures::TERMINAL, 'user_id' => 1, 'opened_at' => '2026-11-14 13:00:00', 'opening_cash' => 7]);
        $takenSession = (int) $restored->lastInsertId();
        $this->assertSame($newSession, $takenSession);
        $userRows = [];
        foreach ([1, 2] as $k) {
            $restored->insert('movement', ['session_id' => $takenSession, 'type' => 'cash_in', 'amount' => 5 + $k, 'concept' => 'Cambio ' . $k, 'created_at' => date('Y-m-d H:i:s')]);
            $userRows['movement'][] = (int) $restored->lastInsertId();
        }
        $restored->insert('outbox', ['type' => 'customer', 'sequence' => 0, 'request_id' => 'customer-' . bin2hex(random_bytes(4)), 'payload' => '{}']);
        $userRows['outbox'][] = (int) $restored->lastInsertId();
        $userSnap = ['session' => $restored->fetchAssociative('SELECT * FROM session WHERE id = ?', [$takenSession]),
            'movement' => $restored->fetchAllAssociative('SELECT * FROM movement WHERE id IN (?, ?) ORDER BY id', $userRows['movement']),
            'outbox' => $restored->fetchAssociative('SELECT * FROM outbox WHERE id = ?', [$userRows['outbox'][0]])];
        $taken = array_values(array_intersect(array_column($liveMov, 'id'), array_map('strval', $userRows['movement'])));
        $r = $this->replay($restored)->run(true);
        $this->assertSame(FiscalReplayResult::DONE, $r->status, (string) $r->message);
        // user rows untouched
        $this->assertSame($userSnap['session'], $restored->fetchAssociative('SELECT * FROM session WHERE id = ?', [$takenSession]));
        $this->assertSame($userSnap['movement'], $restored->fetchAllAssociative('SELECT * FROM movement WHERE id IN (?, ?) ORDER BY id', $userRows['movement']));
        $this->assertSame($userSnap['outbox'], $restored->fetchAssociative('SELECT * FROM outbox WHERE id = ?', [$userRows['outbox'][0]]));
        $map = [];
        foreach ($r->remaps as $m) { $map[$m['table']][$m['from']] = $m['to']; }
        $this->assertArrayHasKey($newSession, $map['session'] ?? []);
        $fresh = $map['session'][$newSession];
        // fiscal tables identical (ids, numbers, hashes)
        foreach (['fiscal_record', 'fiscal_chain', 'ticket_sequence', 'credit_note_sequence', 'invoice_sequence', 'transfer_payment', 'refund_line', 'invoice', 'issued_coupon'] as $t) {
            $this->assertSame($live[$t], self::tables($restored)[$t], $t);
        }
        // remapped rows: same content as live apart from the remapped ids
        $sub = static function (array $row) use ($map): array {
            if (isset($row['session_id'], $map['session'][(int) $row['session_id']])) { $row['session_id'] = is_int($row['session_id']) ? $map['session'][(int) $row['session_id']] : (string) $map['session'][(int) $row['session_id']]; }
            if (isset($row['voucher_session_id'], $map['session'][(int) $row['voucher_session_id']])) { $row['voucher_session_id'] = is_int($row['voucher_session_id']) ? $map['session'][(int) $row['voucher_session_id']] : (string) $map['session'][(int) $row['voucher_session_id']]; }
            if (isset($row['depends_on'], $map['outbox'][(int) $row['depends_on']])) { $row['depends_on'] = is_int($row['depends_on']) ? $map['outbox'][(int) $row['depends_on']] : (string) $map['outbox'][(int) $row['depends_on']]; }
            return $row;
        };
        foreach (['order_meta', 'sale_line', 'refund', 'exchange'] as $t) {
            $this->assertSame(array_map($sub, $live[$t]), self::tables($restored)[$t], 'references rewritten: ' . $t);
        }
        foreach ($liveMov as $mv) {
            $id = isset($map['movement'][(int) $mv['id']]) ? $map['movement'][(int) $mv['id']] : (int) $mv['id'];
            $got = $restored->fetchAssociative('SELECT * FROM movement WHERE id = ?', [$id]);
            $exp = $sub($mv); $exp['id'] = is_int($mv['id']) ? $id : (string) $id;
            $this->assertSame($exp, $got, 'journaled movement ' . $mv['id']);
        }
        foreach ($live['outbox'] as $ob) {
            $id = isset($map['outbox'][(int) $ob['id']]) ? $map['outbox'][(int) $ob['id']] : (int) $ob['id'];
            $got = $restored->fetchAssociative('SELECT type, request_id, order_id, depends_on FROM outbox WHERE id = ?', [$id]);
            $this->assertSame([$ob['type'], $ob['request_id'], $ob['order_id']], [$got['type'], $got['request_id'], $got['order_id']], 'outbox ' . $ob['id']);
        }
        // no dangling reference
        $this->assertSame(0, (int) $restored->fetchOne('SELECT COUNT(*) FROM outbox o LEFT JOIN outbox d ON d.id = o.depends_on WHERE o.depends_on IS NOT NULL AND d.id IS NULL'), 'dangling depends_on');
        $this->assertSame(0, (int) $restored->fetchOne('SELECT COUNT(*) FROM order_meta o LEFT JOIN session s ON s.id = o.session_id WHERE s.id IS NULL'));
        $this->assertSame(0, (int) $restored->fetchOne('SELECT COUNT(*) FROM movement o LEFT JOIN session s ON s.id = o.session_id WHERE s.id IS NULL'));
        $this->assertSame(0, (int) $restored->fetchOne('SELECT COUNT(*) FROM order_meta WHERE session_id = ?', [$takenSession]), 'no replayed sale in the user session');
        $this->assertSame(0, (int) $restored->fetchOne('SELECT COUNT(*) FROM movement WHERE session_id = ? AND type <> ?', [$takenSession, 'cash_in']));
        $this->tillOk($restored);
        $after = self::everything($restored);
        $again = $this->replay($restored)->run(true);
        $this->assertSame(FiscalReplayResult::NOTHING, $again->status);
        $this->assertSame($after, self::everything($restored), 'second run changes nothing');
    }
}
