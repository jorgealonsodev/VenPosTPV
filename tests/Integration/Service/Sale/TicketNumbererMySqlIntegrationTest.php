<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Sale;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Tests\Support\TestDatabase;

final class TicketNumbererMySqlIntegrationTest extends TestCase
{
    private const YEARS = [2091, 2092];

    private ?Connection $connection = null;

    /** @var list<int> */
    private array $terminalIds = [];

    /** @var list<string> */
    private array $letters = [];

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            foreach ($this->letters as $letter) {
                $this->connection->executeStatement('DELETE FROM ticket_sequence WHERE letter = ? AND year IN (2091, 2092)', [$letter]);
            }
            foreach ($this->terminalIds as $id) {
                $this->connection->executeStatement('DELETE FROM terminal WHERE id = ?', [$id]);
            }
            $this->connection->close();
        }
    }

    public function testNumbersAreConsecutivePerLetterAndRestartEachYear(): void
    {
        $a = $this->terminal();
        $b = $this->terminal();
        [$letterA, $letterB] = $this->letters;
        $numberer = new TicketNumberer($this->connection);
        $y1 = new \DateTimeImmutable('2091-12-31 23:59:00');
        $y2 = new \DateTimeImmutable('2092-01-01 00:01:00');

        $this->assertSame($letterA . '91000001', $this->take($numberer, $a, $y1));
        $this->assertSame($letterA . '91000002', $this->take($numberer, $a, $y1));
        $this->assertSame($letterB . '91000001', $this->take($numberer, $b, $y1), 'each letter has its own series');
        $this->assertSame($letterA . '92000001', $this->take($numberer, $a, $y2), 'a new year restarts at 1');
        $this->assertSame($letterA . '91000003', $this->take($numberer, $a, $y1));
    }

    public function testRolledBackNumberIsGivenBack(): void
    {
        $id = $this->terminal();
        $letter = $this->letters[0];
        $numberer = new TicketNumberer($this->connection);
        $date = new \DateTimeImmutable('2091-03-01');

        $first = $this->take($numberer, $id, $date);
        $numberer->prepareYear($id, $date);
        $this->connection->beginTransaction();
        $numberer->next($id, $date);
        $this->connection->rollBack();
        $second = $this->take($numberer, $id, $date);

        $this->assertSame($letter . '91000001', $first);
        $this->assertSame($letter . '91000002', $second, 'a rolled-back sale leaves no gap');
    }

    public function testYearRowIsPreparedOutsideTheTransactionIdempotently(): void
    {
        $id = $this->terminal();
        $numberer = new TicketNumberer($this->connection);
        $date = new \DateTimeImmutable('2091-03-01');

        $numberer->prepareYear($id, $date);
        $numberer->prepareYear($id, $date);

        $this->assertSame('0', (string) $this->connection->fetchOne(
            'SELECT last_number FROM ticket_sequence WHERE letter = ? AND year = 2091',
            [$this->letters[0]]
        ));
    }

    public function testTerminalWithoutLetterIsRefused(): void
    {
        $id = $this->terminal(withLetter: false);
        $numberer = new TicketNumberer($this->connection);

        $this->connection->beginTransaction();
        try {
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('La caja no tiene letra de ticket');
            $numberer->next($id, new \DateTimeImmutable('2091-03-01'));
        } finally {
            $this->connection->rollBack();
        }
    }

    public function testYearPastSixDigitsIsRefusedAndKeepsTheCounter(): void
    {
        $id = $this->terminal();
        $letter = $this->letters[0];
        $this->connection->insert('ticket_sequence', ['letter' => $letter, 'year' => 2091, 'last_number' => 999999]);
        $numberer = new TicketNumberer($this->connection);

        $this->connection->beginTransaction();
        try {
            $numberer->next($id, new \DateTimeImmutable('2091-03-01'));
            $this->fail('the series must refuse number 1000000');
        } catch (\OverflowException) {
            $this->connection->rollBack();
        }

        $this->assertSame('999999', (string) $this->connection->fetchOne(
            'SELECT last_number FROM ticket_sequence WHERE letter = ? AND year = 2091',
            [$letter]
        ));
    }

    public function testNumberingOutsideATransactionIsRefused(): void
    {
        $id = $this->terminal();

        $this->expectException(\LogicException::class);
        (new TicketNumberer($this->connection))->next($id, new \DateTimeImmutable('2091-03-01'));
    }

    public function testConcurrentWorkersGetUniqueGaplessNumbers(): void
    {
        $id = $this->terminal();
        $letter = $this->letters[0];
        $workers = 6;
        $perWorker = 30;
        $rollbackEvery = 5;

        $processes = [];
        for ($i = 0; $i < $workers; ++$i) {
            $processes[] = $this->startWorker($id, '2091-06-15', $perWorker, $rollbackEvery);
        }

        $numbers = [];
        foreach ($processes as $process) {
            $result = $this->waitForWorker($process);
            $this->assertTrue($result['ok'], (string) ($result['message'] ?? ''));
            array_push($numbers, ...$result['numbers']);
        }

        $committed = $workers * ($perWorker - intdiv($perWorker, $rollbackEvery));
        $this->assertCount($committed, $numbers);
        $this->assertCount($committed, array_unique($numbers), 'no duplicates');
        sort($numbers);
        $expected = array_map(static fn (int $n): string => sprintf('%s91%06d', $letter, $n), range(1, $committed));
        $this->assertSame($expected, $numbers, 'no gaps');
    }

    private function take(TicketNumberer $numberer, int $terminalId, \DateTimeImmutable $date): string
    {
        $numberer->prepareYear($terminalId, $date);

        return $this->connection->transactional(fn (): string => $numberer->next($terminalId, $date));
    }

    private function terminal(bool $withLetter = true): int
    {
        $letter = null;
        if ($withLetter) {
            $used = $this->connection->fetchFirstColumn('SELECT ticket_letter FROM terminal WHERE ticket_letter IS NOT NULL');
            foreach (array_reverse(range('A', 'Z')) as $candidate) {
                if (!\in_array($candidate, $used, true)) {
                    $letter = $candidate;
                    break;
                }
            }
            $this->assertNotNull($letter, 'test database has a free terminal letter');
            $this->letters[] = $letter;
            foreach (self::YEARS as $year) {
                $this->connection->executeStatement('DELETE FROM ticket_sequence WHERE letter = ? AND year = ?', [$letter, $year]);
            }
        }

        $this->connection->insert('terminal', ['ps_shop_id' => 1, 'name' => 'Ticket numbering test', 'ticket_letter' => $letter]);
        $id = (int) $this->connection->lastInsertId();
        $this->terminalIds[] = $id;

        return $id;
    }

    /** @return array{resource, resource, resource} */
    private function startWorker(int $terminalId, string $date, int $count, int $rollbackEvery): array
    {
        $process = proc_open([
            PHP_BINARY,
            __DIR__ . '/fixtures/ticket_number_worker.php',
            (string) $terminalId,
            $date,
            (string) $count,
            (string) $rollbackEvery,
        ], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            self::fail('Unable to start ticket number worker.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$process, $pipes[1], $pipes[2]];
    }

    /**
     * @param array{resource, resource, resource} $worker
     *
     * @return array<string, mixed>
     */
    private function waitForWorker(array $worker): array
    {
        [$process, $stdout, $stderr] = $worker;
        $output = '';
        $errors = '';
        $deadline = microtime(true) + 30;
        do {
            $output .= (string) stream_get_contents($stdout);
            $errors .= (string) stream_get_contents($stderr);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $output .= (string) stream_get_contents($stdout);
                $errors .= (string) stream_get_contents($stderr);
                fclose($stdout);
                fclose($stderr);
                proc_close($process);
                $this->assertSame('', $errors);

                return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::fail('Ticket number worker did not finish within 30 seconds.');
    }
}
