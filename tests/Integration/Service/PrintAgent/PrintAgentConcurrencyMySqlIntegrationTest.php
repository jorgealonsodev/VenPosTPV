<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\PrintAgent;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\PrintAgent\AgentRegistry;
use VenPosTPV\Service\PrintAgent\PrintJobQueue;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * docs/prd/agente-impresion.md T4, E3 with real parallel processes on MySQL: two
 * agents (or two requests) claiming the same till's jobs never get the same job,
 * and a pairing code is consumed by one agent only.
 */
final class PrintAgentConcurrencyMySqlIntegrationTest extends TestCase
{
    use PrintAgentFixtures;

    private const WORKERS = 4;

    private ?Connection $connection = null;
    private string $scratch = '';

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->cleanPrintAgentFixtures($this->connection);
        $this->scratch = sys_get_temp_dir() . '/print-agent-race-' . bin2hex(random_bytes(6));
        mkdir($this->scratch);
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->cleanPrintAgentFixtures($this->connection);
            $this->connection->close();
        }
        if ($this->scratch !== '' && is_dir($this->scratch)) {
            array_map('unlink', glob($this->scratch . '/*') ?: []);
            rmdir($this->scratch);
        }
    }

    public function testParallelClaimsNeverHandOutTheSameJobTwice(): void
    {
        $till = $this->till($this->connection, 'Caja carrera');
        $other = $this->till($this->connection, 'Caja ajena');
        $queue = new PrintJobQueue($this->connection);
        $all = [];
        for ($i = 0; $i < 120; ++$i) {
            $all[] = $queue->enqueue($till, $i % 3 === 0 ? 'label' : 'ticket', 'job ' . $i, 'test');
        }
        $foreign = $queue->enqueue($other, 'ticket', 'other till', 'test');

        $results = $this->race('claim', array_fill(0, self::WORKERS, (string) $till));

        $claimed = array_merge(...array_map(static fn (array $r): array => $r['ids'], $results));
        $this->assertSame(\count($claimed), \count(array_unique($claimed)), 'no job claimed twice');
        sort($claimed);
        $this->assertSame($all, $claimed, 'every job claimed once');
        $this->assertNotContains($foreign, $claimed, 'never another till\'s job');
        $this->assertGreaterThan(1, \count(array_filter($results, static fn (array $r): bool => $r['ids'] !== [])), 'the workers really competed');
        $this->assertSame(\count($all), (int) $this->connection->fetchOne("SELECT COUNT(*) FROM print_job WHERE terminal_id = ? AND status = 'claimed'", [$till]));
        $this->assertSame('pending', $this->connection->fetchOne('SELECT status FROM print_job WHERE id = ?', [$foreign]));
    }

    public function testAPairingCodeIsConsumedByOneAgentOnly(): void
    {
        $till = $this->till($this->connection, 'Caja emparejar');
        $code = (new AgentRegistry($this->connection, 'concurrency-test-secret'))->createPairingCode($till, null)['code'];

        $results = $this->race('pair', array_fill(0, self::WORKERS, $code));

        $winners = array_values(array_filter($results, static fn (array $r): bool => $r['paired']));
        $this->assertCount(1, $winners, 'one agent gets the key');
        $this->assertSame($winners[0]['key_hash'], $this->connection->fetchOne('SELECT key_hash FROM print_agent WHERE terminal_id = ?', [$till]));
        $this->assertNotNull($this->connection->fetchOne('SELECT used_at FROM agent_pairing WHERE terminal_id = ?', [$till]));
    }

    /**
     * @param list<string> $arguments one per worker
     *
     * @return list<array<string, mixed>>
     */
    private function race(string $mode, array $arguments): array
    {
        $start = $this->scratch . '/start';
        $worker = __DIR__ . '/fixtures/print-agent-worker.php';
        $processes = [];
        foreach ($arguments as $i => $argument) {
            $command = [\PHP_BINARY, '-d', 'variables_order=EGPCS', $worker, $mode, $start, $argument];
            $pipes = [];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, self::workerEnvironment());
            $this->assertIsResource($process);
            $processes[$i] = [$process, $pipes];
        }
        usleep(300_000);
        touch($start);

        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);
            $this->assertSame(0, $code, 'worker failed: ' . $err);
            $results[] = json_decode((string) $out, true, 512, JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    /** @return array<string, string> */
    private static function workerEnvironment(): array
    {
        $env = getenv();
        $settings = TestDatabase::settings();
        $env['TPV_DB_HOST'] = $settings['host'];
        $env['TPV_DB_PORT'] = (string) $settings['port'];
        $env['TPV_DB_USER'] = $settings['user'];
        $env['TPV_DB_PASSWORD'] = $settings['password'];
        $env['TPV_DB_TEST_NAME'] = $settings['name'];

        return $env;
    }
}
