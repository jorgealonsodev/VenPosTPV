<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Invoice\InvoiceIssuer;
use VenPosTPV\Tests\Integration\Service\Invoice\InvoiceFixtures;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * T1b finding (6): a journal line stores the rows of its document as issued, not as
 * they are when the flush runs. The values later statements change are captured in
 * the intent inside the issuing transaction; the rows that cannot be captured that
 * way are named in the line's `as_of_flush`, so the replay never trusts them.
 */
final class FiscalJournalIssuedRowsMySqlIntegrationTest extends TestCase
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
    private function sell(): array
    {
        return $this->fixtures->checkout($this->fixtures->recorder($this->journal->intents))
            ->createOrder(1, $this->fixtures->cart() + ['cash_amount' => JournalPathFixtures::PRICE], 'cash', JournalPathFixtures::PRICE);
    }

    public function testASaleKeepsItsOrderRowAsIssuedWhenItChangesBeforeTheFlush(): void
    {
        $sale = $this->sell();
        $issued = $this->connection->fetchAssociative('SELECT * FROM order_meta WHERE id = ?', [$sale['order_id']]);
        // Printed, uploaded and partly refunded before the flush ran (another till's flush, or a late one).
        $this->connection->executeStatement(
            "UPDATE order_meta SET printed = 1, printed_at = NOW(), print_attempts = 1, ps_order_id = 947150, ps_reference = 'LATERREF', synced = 1, refunded_amount = 5.00 WHERE id = ?",
            [$sale['order_id']]
        );

        $this->journal->anotherProcess()->writer->flush();

        [$line] = $this->journal->lines();
        $this->assertSame($issued, $line['rows']['order_meta']);
        $this->assertSame(['movement', 'transfer_payment', 'outbox', 'session', 'exchange'], $line['as_of_flush']);
    }

    public function testACreditNoteKeepsItsRefundAndTheRefundedAmountAsIssued(): void
    {
        $sale = $this->sell();
        $refund = $this->fixtures->refunds($this->fixtures->recorder($this->journal->intents), null, $this->journal->intents)
            ->processSaleRefund(1, $sale['order_id'], null, 'size');
        $issuedRefund = $this->connection->fetchAssociative('SELECT * FROM refund WHERE id = ?', [$refund['refund_id']]);
        $issuedAmount = $this->connection->fetchOne('SELECT refunded_amount FROM order_meta WHERE id = ?', [$sale['order_id']]);
        // The upload links the slip, and a later refund of the same sale adds to the amount.
        $this->connection->executeStatement('UPDATE refund SET ps_order_slip_id = 77, ps_order_id = 947151 WHERE id = ?', [$refund['refund_id']]);
        $this->connection->executeStatement('UPDATE order_meta SET refunded_amount = refunded_amount + 3.00 WHERE id = ?', [$sale['order_id']]);

        $this->journal->anotherProcess()->writer->flush();

        [, $line] = $this->journal->lines();
        $this->assertSame('R5', $line['kind']);
        $this->assertSame($issuedRefund, $line['rows']['refund']);
        $this->assertSame(['order_meta_id' => $issuedRefund['order_id'], 'value' => $issuedAmount], $line['rows']['order_meta_refunded_amount']);
        $this->assertSame(['refund_line', 'movement', 'outbox', 'session', 'exchange'], $line['as_of_flush']);
    }

    public function testAnInvoiceKeepsItsRowAsIssued(): void
    {
        $invoices = new InvoiceFixtures($this->connection);
        $invoices->reset();
        $invoices->till();
        $customer = $invoices->customer();
        $orderId = $invoices->sale('A26000123', '2026-11-03 18:30:00', [['name' => 'Gafas', 'qty' => 1, 'price' => 12.10, 'price_net' => 10.00]]);
        (new InvoiceIssuer($this->connection, fiscal: $this->fixtures->recorder($this->journal->intents)))
            ->redeem($orderId, $customer, InvoiceFixtures::TILL, 1, false, null, InvoiceFixtures::now());
        $issued = $invoices->invoiceRow($orderId);
        $this->connection->executeStatement('UPDATE invoice SET email_consent_at = NOW() WHERE sale_order_id = ?', [$orderId]);

        $this->journal->anotherProcess()->writer->flush();

        $line = $this->journal->lines()[1];
        $this->assertSame('F3', $line['kind']);
        $this->assertSame($issued, $line['rows']['invoice']);
        $this->assertSame([], $line['as_of_flush']);
    }

    public function testWithoutItsIntentTheLineSaysTheRowsAreAsOfFlush(): void
    {
        $sale = $this->sell();
        foreach (glob($this->journal->root . '/*/fiscal-journal/intents/*.json') ?: [] as $file) {
            unlink($file);
        }

        $this->journal->anotherProcess()->writer->flush();

        [$line] = $this->journal->lines();
        $this->assertSame((int) $sale['order_id'], (int) $line['rows']['order_meta']['id']);
        $this->assertSame(['order_meta', 'movement', 'transfer_payment', 'outbox', 'session', 'exchange'], $line['as_of_flush']);
    }
}
