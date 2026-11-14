<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Exchange\ExchangeService;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalBehindException;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Invoice\InvoiceIssuer;
use VenPosTPV\Service\PrestaShop\CouponCreationService;
use VenPosTPV\Tests\Integration\Service\Invoice\InvoiceFixtures;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * fiscal-journal D5 on MySQL: the guard inside FiscalRecorder::append, under the
 * chain lock. When the database falls behind the journal between the early check
 * and the issue, every issuing path is refused there: its transaction rolls back,
 * so nothing is numbered and no document is stored.
 */
final class FiscalJournalGuardInLockMySqlIntegrationTest extends TestCase
{
    private ?Connection $connection = null;
    private JournalPathFixtures $fixtures;
    private JournalHarness $journal;
    private FiscalJournalGuard $guard;

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
        $this->guard = new FiscalJournalGuard($this->connection, $this->journal->locations);
        $this->fixtures->guard = $this->guard;
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->fixtures->reset();
            (new InvoiceFixtures($this->connection))->reset();
            $this->connection->close();
            $this->journal->remove();
        }
        $this->connection = null;
    }

    /** @return array<string, mixed> */
    private function sell(): array
    {
        return $this->fixtures->checkout($this->fixtures->recorder($this->journal->intents))
            ->createOrder(1, $this->fixtures->cart() + ['cash_amount' => JournalPathFixtures::PRICE], 'cash', JournalPathFixtures::PRICE);
    }

    /** Journals everything, then makes the database lose its last document, as a restore does. */
    private function fallBehind(): void
    {
        $this->journal->writer->flush();
        $this->connection->executeStatement('DELETE FROM fiscal_record ORDER BY id DESC LIMIT 1');
        $head = $this->connection->fetchAssociative('SELECT id, hash FROM fiscal_record ORDER BY id DESC LIMIT 1') ?: ['id' => null, 'hash' => null];
        $this->connection->update('fiscal_chain', ['last_record_id' => $head['id'], 'last_hash' => $head['hash']], ['id' => 1]);
        $this->assertSame(FiscalJournalGuard::BEHIND_JOURNAL, $this->guard->check()['state']);
    }

    /** @return array<string, mixed> everything an issue numbers or stores */
    private function issued(): array
    {
        $c = $this->connection;

        // A counter's year row is prepared at 0 before the transaction (prepareYear): not a number.
        return [
            'fiscal_record' => $c->fetchAllAssociative('SELECT id, number FROM fiscal_record ORDER BY id'),
            'fiscal_chain' => $c->fetchAssociative('SELECT last_record_id, last_hash FROM fiscal_chain WHERE id = 1'),
            'ticket_sequence' => $c->fetchAllAssociative('SELECT * FROM ticket_sequence WHERE last_number > 0 ORDER BY letter, year'),
            'credit_note_sequence' => $c->fetchAllAssociative('SELECT * FROM credit_note_sequence WHERE last_number > 0 ORDER BY year'),
            'invoice_sequence' => $c->fetchAllAssociative('SELECT * FROM invoice_sequence WHERE last_number > 0 ORDER BY series, year'),
            'order_meta' => (int) $c->fetchOne('SELECT COUNT(*) FROM order_meta'),
            'sale_line' => (int) $c->fetchOne('SELECT COUNT(*) FROM sale_line'),
            'movement' => (int) $c->fetchOne('SELECT COUNT(*) FROM movement'),
            'refunds_done' => (int) $c->fetchOne("SELECT COUNT(*) FROM refund WHERE status = 'done'"),
            'invoice' => (int) $c->fetchOne('SELECT COUNT(*) FROM invoice'),
            'intents' => $this->journal->intentFiles(),
        ];
    }

    /** Runs the issue and asserts it was refused under the chain lock with nothing issued. */
    private function assertRefusedWithNothingIssued(\Closure $issue): void
    {
        $before = $this->issued();
        $this->guard->takeRefusal();
        $thrown = null;
        try {
            $issue();
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        $this->assertFalse($this->connection->isTransactionActive(), 'the issuing transaction was closed');
        $this->assertSame($before, $this->issued(), 'nothing numbered, nothing stored');
        $this->assertTrue($this->guard->takeRefusal(), 'refused by the guard (the response becomes the 423)');
        if ($thrown !== null) {
            $chain = [];
            for ($e = $thrown; $e !== null; $e = $e->getPrevious()) {
                $chain[] = $e::class;
            }
            $this->assertContains(FiscalJournalBehindException::class, $chain, $thrown->getMessage());
        }
    }

    public function testALocalSaleIsRefusedAndTheRefusalItselfReachesTheCaller(): void
    {
        $this->sell();
        $this->fallBehind();

        $this->assertRefusedWithNothingIssued(function (): void {
            try {
                $this->sell();
            } catch (FiscalJournalBehindException $e) {
                // createLocalOrder rethrows it as is (D7), never as localSaveFailed.
                throw $e;
            } catch (\Throwable $e) {
                $this->fail('expected the guard exception itself, got ' . $e::class);
            }
            $this->fail('the sale was not refused');
        });
    }

    public function testACouponSaleIsRefusedLocally(): void
    {
        $this->sell();
        $this->fallBehind();
        $client = $this->createMock(PsOrderClient::class);
        $client->method('verifyCouponQuote')->willReturn([
            'cart_id' => 17, 'customer_id' => 1, 'shop_id' => 1, 'currency_id' => 1, 'coupon_id' => 3, 'coupon_code' => 'SAVE2',
            'coupon_expires_at' => '2030-01-01 00:00:00', 'products' => [['id_product' => JournalPathFixtures::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 1]],
            'before_discount_total' => JournalPathFixtures::PRICE, 'discount_amount' => 2.10, 'total_paid' => 10.0, 'expires_at' => time() + 600,
        ]);
        $client->method('createCouponOrder')->willReturn(['order_id' => 947102, 'reference' => 'XKBKNABJL', 'total_paid' => 10.0]);
        $checkout = $this->fixtures->checkout($this->fixtures->recorder($this->journal->intents), $client, true);

        $this->assertRefusedWithNothingIssued(fn () => $checkout->createOrder(1, $this->fixtures->cart() + ['coupon' => ['code' => 'SAVE2', 'preview_cart_id' => 17, 'quote' => 'signed.quote'], 'cash_amount' => 10.0], 'cash', 10.0));
    }

    public function testACreditNoteOfALocalSaleIsRefused(): void
    {
        $sale = $this->sell();
        $this->sell();
        $this->fallBehind();
        $refunds = $this->fixtures->refunds($this->fixtures->recorder($this->journal->intents), null, $this->journal->intents);

        $this->assertRefusedWithNothingIssued(fn () => $refunds->processSaleRefund(1, $sale['order_id'], null, 'size'));
    }

    public function testACreditNoteCompletedThroughPrestaShopIsRefused(): void
    {
        $this->sell();
        $this->fallBehind();
        $refundId = $this->fixtures->legacyPendingRefund(947161, 10.0);
        $refunds = $this->fixtures->refunds($this->fixtures->recorder($this->journal->intents), $this->fixtures->slipClient(), $this->journal->intents);

        $this->assertRefusedWithNothingIssued(fn () => $refunds->retryPending(1, $refundId));
        $this->assertNotSame('done', $this->connection->fetchOne('SELECT status FROM refund WHERE id = ?', [$refundId]));
    }

    public function testAnExchangeIsRefused(): void
    {
        $sale = $this->sell();
        $this->sell();
        $this->fallBehind();
        $recorder = $this->fixtures->recorder($this->journal->intents);
        $exchange = new ExchangeService($this->fixtures->refunds($recorder, null, $this->journal->intents), $this->fixtures->checkout($recorder), $this->connection, $this->createMock(CouponCreationService::class), $this->fixtures->cash());

        $this->assertRefusedWithNothingIssued(fn () => $exchange->processExchange(1, 0, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size', '', $this->fixtures->cart(2), 'cash', 20.0, false, 0, $sale['order_id']));
    }

    public function testAFullInvoiceIsRefused(): void
    {
        $this->sell();
        $this->fallBehind();
        $invoices = new InvoiceFixtures($this->connection);
        $invoices->reset();
        $invoices->till();
        $customer = $invoices->customer();
        $orderId = $invoices->sale('A26000123', '2026-11-03 18:30:00', [['name' => 'Gafas', 'qty' => 1, 'price' => 12.10, 'price_net' => 10.00]]);
        $issuer = new InvoiceIssuer($this->connection, fiscal: $this->fixtures->recorder($this->journal->intents));

        $this->assertRefusedWithNothingIssued(fn () => $issuer->redeem($orderId, $customer, InvoiceFixtures::TILL, 1, false, null, InvoiceFixtures::now()));
    }
}
