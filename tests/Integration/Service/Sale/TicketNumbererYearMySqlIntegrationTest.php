<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Sale;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Tests\Integration\Service\Numbering\NumberingYearCases;
use VenPosTPV\Tests\Support\TestDatabase;

/** S11, O4: the year of a ticket number is the year in Spain at the moment of issue. */
final class TicketNumbererYearMySqlIntegrationTest extends TestCase
{
    use NumberingYearCases;

    private ?Connection $connection = null;
    private ?int $terminalId = null;
    private string $letter = '';

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
        $this->connection->insert('terminal', ['ps_shop_id' => 1, 'name' => 'Ticket year test', 'ticket_letter' => $this->letter]);
        $this->terminalId = (int) $this->connection->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->restoreDefaultZone();
        if ($this->connection !== null) {
            $this->restoreCounters($this->connection);
            if ($this->terminalId !== null) {
                $this->connection->executeStatement('DELETE FROM terminal WHERE id = ?', [$this->terminalId]);
            }
            $this->connection->close();
        }
    }

    #[DataProvider('newYearInstants')]
    #[DataProvider('madridServerInstants')]
    public function testTheTicketTakesTheYearAndTheCounterOfSpainAtThatMoment(string $defaultZone, string $time, string $zone, int $year): void
    {
        \assert($this->connection !== null && $this->terminalId !== null);
        $this->useDefaultZone($defaultZone);
        $other = $year === 2027 ? 2026 : 2027;
        $before = (int) $this->saveCounter($this->connection, 'ticket_sequence', 'letter = ? AND year = ?', [$this->letter, $year]);
        $otherBefore = $this->saveCounter($this->connection, 'ticket_sequence', 'letter = ? AND year = ?', [$this->letter, $other]);
        $numberer = new TicketNumberer($this->connection);
        $at = self::instant($time, $zone);

        $numberer->prepareYear($this->terminalId, $at);
        $number = $this->connection->transactional(fn (): string => $numberer->next($this->terminalId, $at));

        $this->assertSame(TicketNumberer::format($this->letter, $year, $before + 1), $number);
        $this->assertSame(sprintf('%s%02d', $this->letter, $year % 100), substr($number, 0, 3));
        $this->assertSame($before + 1, (int) $this->connection->fetchOne('SELECT last_number FROM ticket_sequence WHERE letter = ? AND year = ?', [$this->letter, $year]), 'the counter of that year moved');
        $this->assertSame($otherBefore, $this->otherCounter($other), 'the counter of the other year did not move nor was created');
    }

    private function otherCounter(int $year): ?int
    {
        \assert($this->connection !== null);
        $last = $this->connection->fetchOne('SELECT last_number FROM ticket_sequence WHERE letter = ? AND year = ?', [$this->letter, $year]);

        return $last === false ? null : (int) $last;
    }
}
