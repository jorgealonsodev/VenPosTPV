<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Exchange\ExchangeService;
use VenPosTPV\Service\Fiscal\Journal\CanonicalJson;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalFlushResult;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\JournalLocation;
use VenPosTPV\Service\Invoice\InvoiceIssuer;
use VenPosTPV\Service\PrestaShop\CouponCreationService;
use VenPosTPV\Tests\Integration\Service\Invoice\InvoiceFixtures;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * S1: after each issuing path the journal holds exactly one line per document, in
 * chain order, whose fiscal_record equals the database row, with the rows of D2.
 */
final class FiscalJournalIssuingPathsMySqlIntegrationTest extends TestCase
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
        // Every path issues through the in-lock guard (D5), which must never refuse here.
        $this->fixtures->guard = new FiscalJournalGuard($this->connection, $this->journal->locations);
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->fixtures->reset();
            (new InvoiceFixtures($this->connection))->reset();
            $this->connection->close();
            $this->journal->remove();
        }
        $this->connection = null;
    }

    /** @return array<string, mixed> */
    private function sell(int $qty = 1): array
    {
        $recorder = $this->fixtures->recorder($this->journal->intents);

        return $this->fixtures->checkout($recorder)->createOrder(1, $this->fixtures->cart($qty) + ['cash_amount' => round(JournalPathFixtures::PRICE * $qty, 2)], 'cash', round(JournalPathFixtures::PRICE * $qty, 2));
    }

    private function flush(int $expected): void
    {
        $this->assertTrue($this->journal->intents->issuedHere());
        $result = $this->journal->writer->flushAfterIssue();
        $this->assertSame(FiscalJournalFlushResult::OK, $result->status);
        $this->assertSame($expected, $result->written);
        $this->assertSame([], $this->journal->intentFiles(), 'every intent is resolved once its line is written');
    }

    /**
     * One line per fiscal record, in chain order, each fiscal_record verbatim, the
     * seq gapless and every line chained to the previous one.
     *
     * @return list<array<string, mixed>>
     */
    private function assertJournalMatchesDatabase(): array
    {
        $records = $this->connection->fetchAllAssociative('SELECT * FROM fiscal_record ORDER BY id');
        $lines = $this->journal->lines();
        $this->assertCount(\count($records), $lines);
        $previous = '';
        foreach ($lines as $index => $line) {
            $this->assertSame($records[$index], $line['fiscal_record']);
            $this->assertSame($index + 1, $line['seq']);
            $this->assertSame($previous, $line['prev']);
            $this->assertSame(CanonicalJson::lineHash($line), $line['line_hash']);
            $this->assertTrue(JournalLocation::selfValid($line));
            $this->assertSame(1, $line['v']);
            $this->assertFalse($line['backfill']);
            $this->assertSame($records[$index]['invoice_type'], $line['kind']);
            $previous = $line['line_hash'];
        }
        $head = $this->journal->primary()->readHead();
        $last = end($lines);
        $this->assertSame($last['line_hash'], $head['line_hash']);
        $this->assertSame($last['fiscal_record']['hash'], $head['hash']);

        return $lines;
    }

    /** The movement id the intent of the credit note noted when it was issued. */
    private function intentMovementId(): ?int
    {
        foreach (glob($this->journal->root . '/backups/fiscal-journal/intents/*.json') ?: [] as $file) {
            $intent = json_decode((string) file_get_contents($file), true);
            if (($intent['fiscal_record']['invoice_type'] ?? null) === 'R5') {
                return $intent['movement_id'] ?? null;
            }
        }

        return null;
    }

    public function testLocalSale(): void
    {
        $sale = $this->sell(2);

        $this->flush(1);

        [$line] = $this->assertJournalMatchesDatabase();
        $year = (int) date('Y');
        $this->assertSame(JournalPathFixtures::LETTER . date('y'), $line['series']);
        $this->assertSame(['table' => 'ticket_sequence', 'letter' => JournalPathFixtures::LETTER, 'year' => $year, 'number' => 1], $line['counter']);
        $this->assertSame($sale['reference'], $line['fiscal_record']['number']);
        $rows = $line['rows'];
        $this->assertSame($this->connection->fetchAssociative('SELECT * FROM order_meta WHERE id = ?', [$sale['order_id']]), $rows['order_meta']);
        $this->assertSame($this->connection->fetchAllAssociative('SELECT * FROM sale_line WHERE order_id = ? ORDER BY id', [$sale['order_id']]), $rows['sale_line']);
        $this->assertCount(1, $rows['sale_line']);
        $this->assertSame($this->connection->fetchAllAssociative('SELECT * FROM movement WHERE order_id = ?', [$sale['order_id']]), $rows['movement']);
        $this->assertCount(1, $rows['movement']);
        $this->assertSame($this->connection->fetchAllAssociative("SELECT * FROM outbox WHERE type = 'sale' AND order_id = ?", [$sale['order_id']]), $rows['outbox']);
        $this->assertCount(1, $rows['outbox']);
        $this->assertSame(JournalPathFixtures::SESSION, (int) $rows['session']['id']);
        $this->assertNull($rows['transfer_payment']);
        $this->assertNull($rows['exchange']);
        $this->assertSame(['order_meta', 'sale_line', 'movement', 'transfer_payment', 'outbox', 'session', 'exchange'], array_keys($rows));
    }

    public function testCouponSaleIsNumberedByTheShopWithoutACounter(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->method('verifyCouponQuote')->willReturn([
            'cart_id' => 17, 'customer_id' => 1, 'shop_id' => 1, 'currency_id' => 1, 'coupon_id' => 3, 'coupon_code' => 'SAVE2',
            'coupon_expires_at' => '2030-01-01 00:00:00', 'products' => [['id_product' => JournalPathFixtures::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 1]],
            'before_discount_total' => JournalPathFixtures::PRICE, 'discount_amount' => 2.10, 'total_paid' => 10.0, 'expires_at' => time() + 600,
        ]);
        $client->method('createCouponOrder')->willReturn(['order_id' => 947101, 'reference' => 'XKBKNABJK', 'total_paid' => 10.0]);
        $checkout = $this->fixtures->checkout($this->fixtures->recorder($this->journal->intents), $client, true);

        $result = $checkout->createOrder(1, $this->fixtures->cart() + ['coupon' => ['code' => 'SAVE2', 'preview_cart_id' => 17, 'quote' => 'signed.quote'], 'cash_amount' => 10.0], 'cash', 10.0);
        $this->assertFalse($result['saga_error']);

        $this->flush(1);

        [$line] = $this->assertJournalMatchesDatabase();
        $this->assertSame('XKBKNABJK', $line['fiscal_record']['number']);
        $this->assertSame('PS', $line['series']);
        $this->assertNull($line['counter']);
        $rows = $line['rows'];
        $this->assertSame((int) $result['order_id'], (int) $rows['order_meta']['id']);
        $this->assertSame($this->connection->fetchAllAssociative('SELECT * FROM movement WHERE ps_order_id = ?', [947101]), $rows['movement']);
        $this->assertCount(1, $rows['movement']);
        $this->assertArrayNotHasKey('outbox', $rows, 'a coupon sale is never uploaded');
        $this->assertArrayHasKey('issued_coupon', $rows);
        $this->assertNull($rows['issued_coupon']);
    }

    public function testCreditNoteOfALocalSaleStoresItsCashMovementVerbatim(): void
    {
        $sale = $this->sell();
        $refund = $this->fixtures->refunds($this->fixtures->recorder($this->journal->intents), null, $this->journal->intents)
            ->processSaleRefund(1, $sale['order_id'], null, 'size');
        $this->assertSame((int) $this->fixtures->refundMovements()[0]['id'], $this->intentMovementId(), 'captured when the movement was booked');

        $this->flush(2);

        [, $line] = $this->assertJournalMatchesDatabase();
        $year = (int) date('Y');
        $this->assertSame('R' . $year, $line['series']);
        $this->assertSame(['table' => 'credit_note_sequence', 'year' => $year, 'number' => 1], $line['counter']);
        $rows = $line['rows'];
        $this->assertSame($this->connection->fetchAssociative('SELECT * FROM refund WHERE id = ?', [$refund['refund_id']]), $rows['refund']);
        $this->assertCount(1, $rows['refund_line']);
        $this->assertSame($this->fixtures->refundMovements(), $rows['movement']);
        $this->assertSame('Devolución ticket ' . $sale['reference'], $rows['movement'][0]['concept']);
        $this->assertSame($this->connection->fetchAllAssociative('SELECT * FROM outbox WHERE request_id = ?', [$rows['refund']['request_id']]), $rows['outbox']);
        $this->assertCount(1, $rows['outbox']);
        $this->assertSame(['order_meta_id' => $rows['refund']['order_id'], 'value' => $this->connection->fetchOne('SELECT refunded_amount FROM order_meta WHERE id = ?', [$sale['order_id']])], $rows['order_meta_refunded_amount']);
        $this->assertSame(JournalPathFixtures::SESSION, (int) $rows['session']['id']);
        $this->assertNull($rows['exchange']);
    }

    public function testCreditNoteCompletedThroughPrestaShopStoresItsCashMovementVerbatim(): void
    {
        $refundId = $this->fixtures->legacyPendingRefund(947160, 10.0);
        $this->fixtures->refunds($this->fixtures->recorder($this->journal->intents), $this->fixtures->slipClient(), $this->journal->intents)->retryPending(1, $refundId);
        $this->assertSame((int) $this->fixtures->refundMovements()[0]['id'], $this->intentMovementId(), 'captured when the movement was booked');

        $this->flush(1);

        [$line] = $this->assertJournalMatchesDatabase();
        $this->assertSame($this->fixtures->refundMovements(), $line['rows']['movement']);
        $this->assertSame('Devolución pedido #947160', $line['rows']['movement'][0]['concept']);
        $this->assertNull($line['rows']['movement'][0]['order_id']);
        $this->assertSame(77, (int) $line['rows']['movement'][0]['ps_order_slip_id']);
    }

    public function testAnotherProcessFindsTheRefundMovementOnlyWhenItIsTheOnlyCandidate(): void
    {
        $refunds = $this->fixtures->refunds($this->fixtures->recorder());
        $lines = [['product_id' => JournalPathFixtures::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 1, 'restock' => false]];
        $first = $refunds->processNoTicketRefund(1, 1, $lines, 'cash', 'size');

        $this->journal->anotherProcess()->writer->flush();

        [$line] = $this->journal->lines();
        $this->assertSame($this->fixtures->refundMovements(), $line['rows']['movement'], 'the only refund movement of that session, amount and second');

        // A second identical refund in the same second: the movement is never guessed.
        $second = $refunds->processNoTicketRefund(1, 1, $lines, 'cash', 'size');
        $completedAt = $this->connection->fetchOne('SELECT completed_at FROM refund WHERE id = ?', [$first['refund_id']]);
        $this->connection->update('refund', ['completed_at' => $completedAt], ['id' => $second['refund_id']]);
        $this->connection->executeStatement("UPDATE movement SET created_at = ? WHERE session_id = ? AND type = 'refund'", [$completedAt, JournalPathFixtures::SESSION]);

        $this->journal->anotherProcess()->writer->flush();

        $this->assertSame([], $this->journal->lines()[1]['rows']['movement']);
    }

    public function testFullInvoice(): void
    {
        $invoices = new InvoiceFixtures($this->connection);
        $invoices->reset();
        $invoices->till();
        $customer = $invoices->customer();
        $orderId = $invoices->sale('A26000123', '2026-11-03 18:30:00', [['name' => 'Gafas', 'qty' => 1, 'price' => 12.10, 'price_net' => 10.00]]);
        $issuer = new InvoiceIssuer($this->connection, fiscal: $this->fixtures->recorder($this->journal->intents));

        $issuer->redeem($orderId, $customer, InvoiceFixtures::TILL, 1, false, null, InvoiceFixtures::now());

        $this->flush(2);

        [$ticket, $line] = $this->assertJournalMatchesDatabase();
        $this->assertSame(['table' => 'ticket_sequence', 'letter' => 'A', 'year' => 2026, 'number' => 123], $ticket['counter'], 'no counter row: 2000 + the two digits');
        $this->assertSame('F3', $line['kind']);
        $this->assertSame('F26', $line['series']);
        $this->assertSame(['table' => 'invoice_sequence', 'series' => 'F', 'year' => 2026, 'number' => 1], $line['counter']);
        $this->assertSame($invoices->invoiceRow($orderId), $line['rows']['invoice']);
    }

    public function testExchangeJournalsItsCreditNoteAndItsNewSale(): void
    {
        $sale = $this->sell();
        $recorder = $this->fixtures->recorder($this->journal->intents);
        $refunds = $this->fixtures->refunds($recorder, null, $this->journal->intents);
        $checkout = $this->fixtures->checkout($recorder);
        $exchange = new ExchangeService($refunds, $checkout, $this->connection, $this->createMock(CouponCreationService::class), $this->fixtures->cash());

        $result = $exchange->processExchange(1, 0, [['line_index' => 0, 'quantity' => 1, 'restock' => true]], 'size', '', $this->fixtures->cart(2), 'cash', 20.0, false, 0, $sale['order_id']);
        $this->assertSame('done', $result['status']);

        $this->flush(3);

        [, $credit, $newSale] = $this->assertJournalMatchesDatabase();
        $this->assertSame('R5', $credit['kind']);
        $this->assertSame((int) $result['refund']['refund_id'], (int) $credit['rows']['exchange']['refund_id']);
        $this->assertSame('F2', $newSale['kind']);
        $this->assertSame((int) $result['sale']['order_id'], (int) $newSale['rows']['order_meta']['id']);
        $this->assertSame(2, $newSale['counter']['number']);
    }
}
