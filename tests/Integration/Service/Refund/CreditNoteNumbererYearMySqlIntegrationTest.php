<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Refund;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Refund\CreditNoteNumberer;
use VenPosTPV\Tests\Integration\Service\Numbering\NumberingYearCases;
use VenPosTPV\Tests\Support\TestDatabase;

/** S11, O4: the year of a credit-note number is the year in Spain at the moment of issue. */
final class CreditNoteNumbererYearMySqlIntegrationTest extends TestCase
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
    public function testTheCreditNoteTakesTheYearAndTheCounterOfSpainAtThatMoment(string $defaultZone, string $time, string $zone, int $year): void
    {
        \assert($this->connection !== null);
        $this->useDefaultZone($defaultZone);
        $other = $year === 2027 ? 2026 : 2027;
        $before = (int) $this->saveCounter($this->connection, 'credit_note_sequence', 'year = ?', [$year]);
        $otherBefore = $this->saveCounter($this->connection, 'credit_note_sequence', 'year = ?', [$other]);
        $numberer = new CreditNoteNumberer($this->connection);
        $at = self::instant($time, $zone);

        $numberer->prepareYear($at);
        $number = $this->connection->transactional(fn (): string => $numberer->next($at));

        $this->assertSame(sprintf('R%d-%06d', $year, $before + 1), $number);
        $this->assertSame($before + 1, (int) $this->connection->fetchOne('SELECT last_number FROM credit_note_sequence WHERE year = ?', [$year]), 'the counter of that year moved');
        $otherNow = $this->connection->fetchOne('SELECT last_number FROM credit_note_sequence WHERE year = ?', [$other]);
        $this->assertSame($otherBefore, $otherNow === false ? null : (int) $otherNow, 'the counter of the other year did not move nor was created');
    }
}
