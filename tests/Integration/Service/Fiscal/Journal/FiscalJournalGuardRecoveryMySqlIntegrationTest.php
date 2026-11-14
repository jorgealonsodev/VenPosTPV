<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use VenPosTPV\EventListener\FiscalJournalFlushListener;
use VenPosTPV\EventListener\FiscalJournalGuardListener;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Exchange\ExchangeService;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\PrestaShop\CouponCreationService;
use VenPosTPV\Service\Refund\PsRefundClient;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * How the issuing paths recover after FiscalJournalGuard refuses under the issuing
 * lock (an exchange split between its credit note and its sale, an online refund,
 * a coupon sale PrestaShop already created), that the guard never blocks when the
 * journal is only behind, unreadable, missing or the database errs, and that the
 * after-response flush merges a long secondary copy in bounded steps.
 */
final class FiscalJournalGuardRecoveryMySqlIntegrationTest extends TestCase
{
    private ?Connection $connection = null;
    private JournalPathFixtures $fixtures;
    private JournalHarness $journal;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->fixtures = new JournalPathFixtures($this->connection, fn (string $class) => $this->createMock($class));
        $this->fixtures->reset();
        $this->fixtures->install();
        $this->journal = new JournalHarness($this->connection);
        $this->journal->writer->createJournal();
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            @chmod($this->journal->primary()->directory(), 0700);
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->fixtures->reset();
            $this->connection->close();
            $this->journal->remove();
        }
    }

    /** A guard refusing on the given in-lock call numbers (1-based), else the real check. */
    private function guardRefusingOn(array $calls): FiscalJournalGuard
    {
        return new class($this->connection, $this->journal->locations, $calls) extends FiscalJournalGuard {
            public int $n = 0;
            public bool $forceOk = false;

            public function __construct(Connection $c, FiscalJournalLocations $l, private array $calls)
            {
                parent::__construct($c, $l);
            }

            public function check(): array
            {
                ++$this->n;
                if (!$this->forceOk && \in_array($this->n, $this->calls, true)) {
                    return ['state' => self::BEHIND_JOURNAL, 'missing' => ['number' => 'X', 'invoice_type' => 'F2']];
                }

                return parent::check();
            }
        };
    }

    private function sell(?FiscalJournalGuard $guard = null): array
    {
        $this->fixtures->guard = $guard;

        return $this->fixtures->checkout($this->fixtures->recorder($this->journal->intents))
            ->createOrder(1, $this->fixtures->cart() + ['cash_amount' => JournalPathFixtures::PRICE], 'cash', JournalPathFixtures::PRICE);
    }

    private function listenerAnswer(FiscalJournalGuard $guard, int $status): int
    {
        $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, new JsonResponse(['data' => 1], $status));
        (new FiscalJournalGuardListener($guard))->onResponse($event);

        return $event->getResponse()->getStatusCode();
    }

    private function assertGapless(): void
    {
        $this->journal->writer->flush();
        $lines = $this->journal->lines();
        $this->assertSame(range(1, \count($lines)), array_column($lines, 'seq'));
        $db = $this->connection->fetchFirstColumn('SELECT hash FROM fiscal_record ORDER BY id');
        $this->assertSame($db, array_map(static fn (array $l): string => $l['fiscal_record']['hash'], $lines), 'journal = database, in chain order');
    }

    /** An exchange whose credit note was issued before its sale was refused stays credit_open and its retry issues only the sale. */
    public function testAnExchangeWhoseSaleIsRefusedAfterItsCreditNoteStaysOpenAndItsRetryIssuesOnlyTheSale(): void
    {
        $sale = $this->sell();
        $this->journal->writer->flush();
        $guard = $this->guardRefusingOn([2]); // 1 = R5 under the lock, 2 = the new sale under the lock
        $this->fixtures->guard = $guard;
        $recorder = $this->fixtures->recorder($this->journal->intents);
        $exchange = new ExchangeService($this->fixtures->refunds($recorder, null, $this->journal->intents), $this->fixtures->checkout($recorder), $this->connection, $this->createMock(CouponCreationService::class), $this->fixtures->cash());
        $orders = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM order_meta');

        $result = $exchange->processExchange(1, 0, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size', '', $this->fixtures->cart(), 'cash', 0.0, false, 0, $sale['order_id']);

        $this->assertSame('credit_open', $result['status']);
        $this->assertSame(200, $this->listenerAnswer($guard, 200), 'a successful answer is kept');
        $this->assertSame($orders, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM order_meta'), 'no new sale');
        $ex = $this->connection->fetchAssociative('SELECT status, error_message FROM exchange WHERE id = ?', [$result['exchange_id']]);
        $this->assertSame('credit_open', $ex['status']);
        $this->assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fiscal_record'), 'sale + R5');
        $this->assertGapless();

        $guard->forceOk = true;
        $retried = $exchange->retry(1, $result['exchange_id']);
        $this->assertSame('done', $retried['status']);
        $this->assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fiscal_record'));
        $this->assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM fiscal_record WHERE invoice_type = 'R5'"), 'credit note once');
        $this->assertGapless();
    }

    /** An online refund refused under the lock stays pending and its retry replays the same PrestaShop slip, issuing one R5. */
    public function testAnOnlineRefundRefusedUnderTheLockIsRecoveredByItsRetry(): void
    {
        $this->sell();
        $this->journal->writer->flush();
        $refundId = $this->fixtures->legacyPendingRefund(947161, 10.0);
        $payloads = [];
        $client = $this->createMock(PsRefundClient::class);
        $client->method('createRefund')->willReturnCallback(static function (array $p) use (&$payloads): array {
            $payloads[] = $p['request_id'] ?? null;

            return ['order_slip_id' => 77, 'refunded_amount' => 0.0, 'refunded_lines' => [], 'restock_failed' => [], 'replayed' => \count($payloads) > 1];
        });
        $guard = $this->guardRefusingOn([1]);
        $this->fixtures->guard = $guard;
        $refunds = $this->fixtures->refunds($this->fixtures->recorder($this->journal->intents), $client, $this->journal->intents);

        $r = $refunds->retryPending(1, $refundId);
        $this->assertTrue($guard->takeRefusal());
        $this->assertSame('pending', $this->connection->fetchOne('SELECT status FROM refund WHERE id = ?', [$refundId]));
        $this->assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM fiscal_record WHERE invoice_type = 'R5'"));

        $guard->forceOk = true;
        $r2 = $refunds->retryPending(1, $refundId);
        $this->assertFalse($r2['saga_error']);
        $this->assertSame('done', $this->connection->fetchOne('SELECT status FROM refund WHERE id = ?', [$refundId]));
        $this->assertCount(2, $payloads);
        $this->assertSame($payloads[0], $payloads[1], 'same request_id: the shop replays the slip');
        $this->assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM fiscal_record WHERE invoice_type = 'R5'"));
        $this->assertGapless();
    }

    /** A coupon sale refused under the lock after PrestaShop created the order is a saga error, and the same request_id later records it once. */
    public function testACouponSaleRefusedUnderTheLockIsASagaErrorRecordedOnceByTheSameRequest(): void
    {
        $this->sell();
        $this->journal->writer->flush();
        $client = $this->createMock(PsOrderClient::class);
        $client->method('verifyCouponQuote')->willReturn([
            'cart_id' => 17, 'customer_id' => 1, 'shop_id' => 1, 'currency_id' => 1, 'coupon_id' => 3, 'coupon_code' => 'SAVE2',
            'coupon_expires_at' => '2030-01-01 00:00:00', 'products' => [['id_product' => JournalPathFixtures::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 1]],
            'before_discount_total' => JournalPathFixtures::PRICE, 'discount_amount' => 2.10, 'total_paid' => 10.0, 'expires_at' => time() + 600,
        ]);
        $client->expects($this->exactly(2))->method('createCouponOrder')->willReturn(['order_id' => 947102, 'reference' => 'XKBKNABJL', 'total_paid' => 10.0]);
        $guard = $this->guardRefusingOn([1]);
        $this->fixtures->guard = $guard;
        $checkout = $this->fixtures->checkout($this->fixtures->recorder($this->journal->intents), $client, true);
        $cart = $this->fixtures->cart() + ['coupon' => ['code' => 'SAVE2', 'preview_cart_id' => 17, 'quote' => 'signed.quote'], 'cash_amount' => 10.0];

        $r = $checkout->createOrder(1, $cart, 'cash', 10.0, 0.0, 'coupon-req-1');
                $this->assertTrue($r['saga_error']);
        $this->assertSame(200, $this->listenerAnswer($guard, 200), 'saga answer (200) is not rewritten; the POS locks reconciliation on saga_error');
        $this->assertFalse((bool) $this->connection->fetchOne('SELECT 1 FROM order_meta WHERE ps_order_id = 947102'));

        // The same request_id once the database is resolved: the shop answers the same order, recorded once.
        $guard->forceOk = true;
        $r2 = $checkout->createOrder(1, $cart, 'cash', 10.0, 0.0, 'coupon-req-1');
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM order_meta WHERE ps_order_id = 947102'));
        $this->assertGapless();
    }

    /** The guard answers ok when the journal is behind, unreadable, missing or damaged, or the database errs. */
    public function testTheGuardNeverBlocksWhenTheJournalIsBehindUnreadableOrMissingOrTheDatabaseErrs(): void
    {
        $guard = new FiscalJournalGuard($this->connection, $this->journal->locations);
        foreach (range(1, 5) as $i) {
            JournalIssue::ticket($this->connection, $this->journal->intents, JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION);
        }
        $this->assertSame('ok', $guard->check()['state'], 'journal behind the database');
        $this->journal->writer->flush();
        // A rolled-back issue then a committed one in the same second.
        $at = new \DateTimeImmutable();
        JournalIssue::ticket($this->connection, $this->journal->intents, JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION, false, $at);
        JournalIssue::ticket($this->connection, $this->journal->intents, JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION, true, $at);
        $this->journal->writer->flushAfterIssue();
        $this->assertSame('ok', $guard->check()['state'], 'rollback + issue same second');
        $this->journal->writer->flush();
        $this->assertSame('ok', $guard->check()['state']);
        $this->assertGapless();

        // Unreadable journal (0000 folders) and a missing one.
        $p = $this->journal->primary()->directory();
        chmod($p, 0000);
        $this->assertSame('ok', $guard->check()['state'], 'unreadable primary');
        chmod($p, 0700);
        $copy = $this->journal->root . '-moved';
        rename($this->journal->root, $copy);
        $this->assertSame('ok', $guard->check()['state'], 'missing journal');
        rename($copy, $this->journal->root);

        // Garbage in head.json and a garbage confirmed intent.
        file_put_contents($p . '/head.json', '{not json');
        $this->assertSame('ok', $guard->check()['state'], 'garbage head.json');
        $this->journal->writer->flush();

        // Database error inside the guard, inside an issuing-like transaction.
        $bad = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'port' => 1, 'user' => 'x', 'password' => 'y', 'dbname' => 'x']);
        $this->assertSame('ok', (new FiscalJournalGuard($bad, $this->journal->locations))->check()['state'], 'database unreachable');
        $this->connection->beginTransaction();
        $this->connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        $guardInTx = new FiscalJournalGuard($this->connection, $this->journal->locations);
        $this->assertSame('ok', $guardInTx->check()['state'], 'in a transaction (LOCK IN SHARE MODE)');
        $this->connection->rollBack();
    }

    /** 30 lines held only in the secondary copy are merged into the primary 20 at a time, verbatim, before the database's own documents. */
    public function testThirtySecondaryLinesAreMergedInBoundedStepsBeforeTheDatabaseDocuments(): void
    {
        foreach (range(1, 2) as $i) {
            JournalIssue::ticket($this->connection, $this->journal->intents, JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION);
        }
        $this->journal->writer->flush();
        chmod($this->journal->primary()->directory(), 0500);
        foreach (range(1, 30) as $i) {
            JournalIssue::ticket($this->connection, $this->journal->intents, JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION);
            $this->journal->writer->flushAfterIssue(FiscalJournalFlushListener::AFTER_RESPONSE_LIMIT, FiscalJournalFlushListener::LOCK_WAIT_SECONDS);
        }
        $this->assertSame(range(3, 32), array_column($this->journal->lines($this->journal->secondary()), 'seq'));
        chmod($this->journal->primary()->directory(), 0700);
        // Plus 5 documents only in the database.
        foreach (range(1, 5) as $i) {
            JournalIssue::ticket($this->connection, $this->journal->intents, JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION);
        }
        $secondaryBefore = $this->journal->lines($this->journal->secondary());

        $r1 = $this->journal->writer->flushAfterIssue(FiscalJournalFlushListener::AFTER_RESPONSE_LIMIT, FiscalJournalFlushListener::LOCK_WAIT_SECONDS);
        $this->assertSame(20, $r1->written);
        $this->assertSame(range(1, 22), array_column($this->journal->lines(), 'seq'));
        $this->assertSame($secondaryBefore, $this->journal->lines($this->journal->secondary()), 'secondary kept whole until merged');
        $merged = \array_slice($this->journal->lines(), 2);
        $this->assertSame(\array_slice($secondaryBefore, 0, 20), $merged, 'merged verbatim');

        $r2 = $this->journal->writer->flushAfterIssue(FiscalJournalFlushListener::AFTER_RESPONSE_LIMIT, FiscalJournalFlushListener::LOCK_WAIT_SECONDS);
        $this->assertSame(15, $r2->written, '10 merged + 5 from the database');
        $this->assertSame([], $this->journal->lines($this->journal->secondary()), 'secondary emptied once fully merged');
        $this->assertSame(range(1, 37), array_column($this->journal->lines(), 'seq'));
        $this->assertGapless();
    }
}
