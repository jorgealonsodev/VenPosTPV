<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Invoice;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Invoice\InvoiceNumberer;
use VenPosTPV\Tests\Integration\Service\Numbering\NumberingYearCases;
use VenPosTPV\Tests\Support\TestDatabase;

/** S11, O4: the year of a full-invoice number is the year in Spain at the moment of issue. */
final class InvoiceNumbererYearMySqlIntegrationTest extends TestCase
{
    use NumberingYearCases;

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
    }

    protected function tearDown(): void
    {
        $this->restoreDefaultZone();
        if ($this->connection !== null) {
            $this->restoreCounters($this->connection);
            $this->connection->close();
        }
    }

    #[DataProvider('newYearInstants')]
    #[DataProvider('madridServerInstants')]
    public function testTheInvoiceTakesTheYearAndTheCounterOfSpainAtThatMoment(string $defaultZone, string $time, string $zone, int $year): void
    {
        \assert($this->connection !== null);
        $this->useDefaultZone($defaultZone);
        $other = $year === 2027 ? 2026 : 2027;
        $where = 'series = ? AND year = ?';
        $before = (int) $this->saveCounter($this->connection, 'invoice_sequence', $where, [InvoiceNumberer::SERIES, $year]);
        $otherBefore = $this->saveCounter($this->connection, 'invoice_sequence', $where, [InvoiceNumberer::SERIES, $other]);
        $numberer = new InvoiceNumberer($this->connection);
        $at = self::instant($time, $zone);

        $numberer->prepareYear($at);
        $number = $this->connection->transactional(fn (): array => $numberer->next($at));

        $this->assertSame(['number' => sprintf('F%02d%06d', $year % 100, $before + 1), 'year' => $year, 'seq' => $before + 1], $number);
        $this->assertSame($before + 1, (int) $this->connection->fetchOne('SELECT last_number FROM invoice_sequence WHERE ' . $where, [InvoiceNumberer::SERIES, $year]), 'the counter of that year moved');
        $otherNow = $this->connection->fetchOne('SELECT last_number FROM invoice_sequence WHERE ' . $where, [InvoiceNumberer::SERIES, $other]);
        $this->assertSame($otherBefore, $otherNow === false ? null : (int) $otherNow, 'the counter of the other year did not move nor was created');
    }
}
