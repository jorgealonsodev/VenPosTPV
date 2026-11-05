<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Invoice;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\FiscalChainVerifier;
use VenPosTPV\Service\Invoice\InvoiceNumberer;
use VenPosTPV\Tests\Support\TestDatabase;

/** docs/prd/factura-completa.md F4 and criteria 7-8: one gapless F series per year. */
final class InvoiceNumbererMySqlIntegrationTest extends TestCase
{
    private ?Connection $connection = null;
    private InvoiceFixtures $fixtures;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->fixtures = new InvoiceFixtures($this->connection);
        $this->fixtures->reset();
        $this->fixtures->till();
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->fixtures->reset();
            $this->connection->close();
        }
    }

    public function testNumbersAreConsecutiveAndRestartEachYear(): void
    {
        \assert($this->connection !== null);
        $numberer = new InvoiceNumberer($this->connection);
        $take = function (string $date) use ($numberer): string {
            $at = new \DateTimeImmutable($date);
            $numberer->prepareYear($at);

            return $this->connection->transactional(fn (): string => $numberer->next($at)['number']);
        };

        self::assertSame(['F26000001', 'F26000002', 'F27000001'], [$take('2026-03-01'), $take('2026-12-31'), $take('2027-01-01')]);
    }

    public function testARolledBackNumberIsGivenBack(): void
    {
        \assert($this->connection !== null);
        $numberer = new InvoiceNumberer($this->connection);
        $at = new \DateTimeImmutable('2026-05-01');
        $numberer->prepareYear($at);
        $this->connection->beginTransaction();
        self::assertSame('F26000001', $numberer->next($at)['number']);
        $this->connection->rollBack();

        $this->connection->beginTransaction();
        self::assertSame(['number' => 'F26000001', 'year' => 2026, 'seq' => 1], $numberer->next($at));
        $this->connection->commit();
    }

    public function testNumberingOutsideATransactionIsRefused(): void
    {
        \assert($this->connection !== null);
        $this->expectException(\LogicException::class);
        (new InvoiceNumberer($this->connection))->next(new \DateTimeImmutable());
    }

    // Criterion 7: two connections issue 50 invoices each at the same time.
    public function testTwoTillsIssuingAtOnceGetNumbersOneToAHundredAndAValidChain(): void
    {
        \assert($this->connection !== null);
        $workers = [$this->startWorker(50, 100000), $this->startWorker(50, 200000)];
        $numbers = [];
        foreach ($workers as $worker) {
            $result = $this->waitForWorker($worker);
            self::assertTrue($result['ok'], (string) ($result['message'] ?? ''));
            array_push($numbers, ...$result['numbers']);
        }

        sort($numbers);
        self::assertSame(array_map(static fn (int $n): string => sprintf('F26%06d', $n), range(1, 100)), $numbers, 'no gaps nor duplicates');
        $check = (new FiscalChainVerifier($this->connection))->verify();
        self::assertTrue($check['ok'], (string) $check['reason']);
        self::assertSame(100, $check['records']);
    }

    /** @return array{resource, resource, resource} */
    private function startWorker(int $count, int $invoiceIdBase): array
    {
        $process = proc_open([
            PHP_BINARY,
            __DIR__ . '/fixtures/invoice_worker.php',
            (string) InvoiceFixtures::TILL,
            '2026-06-15 12:00:00',
            (string) $count,
            (string) $invoiceIdBase,
        ], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            self::fail('Unable to start invoice worker.');
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
        $deadline = microtime(true) + 60;
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
                self::assertSame('', $errors);

                return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::fail('Invoice worker did not finish within 60 seconds.');
    }
}
