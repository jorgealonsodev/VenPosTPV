<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Numbering;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Invoice\InvoiceNumberer;
use VenPosTPV\Service\Numbering\NumberingSettings;
use VenPosTPV\Service\Refund\CreditNoteNumberer;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * S11, O4: Ajustes → Cajas shows and moves the counters of the same year the
 * numberers issue in at that moment (the year in Spain), so a «próximo número»
 * set at New Year is the one the next document takes.
 */
final class NumberingSettingsYearMySqlIntegrationTest extends TestCase
{
    use NumberingYearCases;

    private const SHOP = 9091;

    private ?Connection $connection = null;
    private ?int $terminalId = null;
    private string $letter = '';
    private int $lastChange = 0;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $used = $this->connection->fetchFirstColumn('SELECT ticket_letter FROM terminal WHERE ticket_letter IS NOT NULL');
        $free = array_values(array_diff(array_reverse(range('A', 'Z')), $used, ['F']));
        $this->assertNotSame([], $free, 'test database has a free terminal letter');
        $this->letter = $free[0];
        $this->connection->insert('terminal', ['ps_shop_id' => self::SHOP, 'name' => 'Numbering year test', 'ticket_letter' => $this->letter, 'active' => 1]);
        $this->terminalId = (int) $this->connection->lastInsertId();
        $this->lastChange = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM numbering_change');
    }

    protected function tearDown(): void
    {
        $this->restoreDefaultZone();
        if ($this->connection !== null) {
            $this->restoreCounters($this->connection);
            $this->connection->executeStatement('DELETE FROM numbering_change WHERE id > ?', [$this->lastChange]);
            if ($this->terminalId !== null) {
                $this->connection->executeStatement('DELETE FROM terminal WHERE id = ?', [$this->terminalId]);
            }
            $this->connection->close();
        }
    }

    #[DataProvider('newYearInstants')]
    #[DataProvider('madridServerInstants')]
    public function testTheOverviewAndTheChangesUseTheYearTheNumberersIssueIn(string $defaultZone, string $time, string $zone, int $year): void
    {
        \assert($this->connection !== null && $this->terminalId !== null);
        $this->useDefaultZone($defaultZone);
        $other = $year === 2027 ? 2026 : 2027;
        foreach ([$year, $other] as $y) {
            $this->saveCounter($this->connection, 'ticket_sequence', 'letter = ? AND year = ?', [$this->letter, $y]);
            $this->saveCounter($this->connection, 'credit_note_sequence', 'year = ?', [$y]);
            $this->saveCounter($this->connection, 'invoice_sequence', 'series = ? AND year = ?', [InvoiceNumberer::SERIES, $y]);
        }
        $settings = new NumberingSettings($this->connection);
        $at = self::instant($time, $zone);
        $yy = sprintf('%02d', $year % 100);

        $overview = $settings->overview(self::SHOP, $at);
        $this->assertSame($year, $overview['year']);
        $this->assertSame([$this->letter . $yy], array_column($overview['tills'], 'series'));
        $this->assertSame('R' . $year, $overview['credit_notes']['series']);
        $this->assertSame('F' . $yy, $overview['invoices']['series'] ?? null);

        $ticket = $settings->setTicketNext($this->terminalId, 500000, 'Prueba de cambio de año', null, $at);
        $creditNote = $settings->setCreditNoteNext(500000 + $this->creditNoteLast($year), 'Prueba de cambio de año', null, $at);
        $invoice = $settings->setInvoiceNext(500000 + $this->invoiceLast($year), 'Prueba de cambio de año', null, $at);

        $this->assertSame($this->letter . $yy, $ticket['series']);
        $this->assertSame('R' . $year, $creditNote['series']);
        $this->assertSame('F' . $yy, $invoice['series']);
        $this->assertSame(499999, (int) $this->connection->fetchOne('SELECT last_number FROM ticket_sequence WHERE letter = ? AND year = ?', [$this->letter, $year]));
        $this->assertFalse($this->connection->fetchOne('SELECT last_number FROM ticket_sequence WHERE letter = ? AND year = ?', [$this->letter, $other]), 'no counter row of the other year');
        $this->assertSame(
            [$this->letter . $yy, 'R' . $year, 'F' . $yy],
            $this->connection->fetchFirstColumn('SELECT series FROM numbering_change WHERE id > ? ORDER BY id', [$this->lastChange]),
            'the audit names the series of that year'
        );

        // The next documents issued at that same moment take exactly the numbers set.
        $tickets = new TicketNumberer($this->connection);
        $creditNotes = new CreditNoteNumberer($this->connection);
        $invoices = new InvoiceNumberer($this->connection);
        $this->assertSame($ticket['next_code'], $this->connection->transactional(fn (): string => $tickets->next($this->terminalId, $at)));
        $this->assertSame($creditNote['next_code'], $this->connection->transactional(fn (): string => $creditNotes->next($at)));
        $this->assertSame($invoice['next_code'], $this->connection->transactional(fn (): string => $invoices->next($at)['number']));
    }

    private function creditNoteLast(int $year): int
    {
        \assert($this->connection !== null);

        return (int) ($this->connection->fetchOne('SELECT last_number FROM credit_note_sequence WHERE year = ?', [$year]) ?: 0);
    }

    private function invoiceLast(int $year): int
    {
        \assert($this->connection !== null);

        return (int) ($this->connection->fetchOne('SELECT last_number FROM invoice_sequence WHERE series = ? AND year = ?', [InvoiceNumberer::SERIES, $year]) ?: 0);
    }
}
