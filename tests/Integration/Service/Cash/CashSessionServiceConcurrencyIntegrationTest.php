<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Cash;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\TerminalService;
use VenPosTPV\Tests\Support\SessionRequest;

/**
 * @group integration
 */
final class CashSessionServiceConcurrencyIntegrationTest extends TestCase
{
    private static ?Connection $connection = null;
    private static int $case = 0;

    public static function setUpBeforeClass(): void
    {
        if (getenv('TPV_CASH_CLOSE_TEST') !== '1') {
            self::markTestSkipped('Run through run-cash-close-atomicity.sh to use a disposable MariaDB.');
        }
        if (getenv('TPV_DB_HOST') !== '127.0.0.1' || !str_starts_with((string) getenv('TPV_DB_NAME'), 'cash_close_test_')) {
            self::fail('Cash-close concurrency tests require the loopback disposable database.');
        }

        self::$connection = DriverManager::getConnection(self::databaseConfig());
        self::$connection->executeStatement('CREATE TABLE terminal (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ps_shop_id INT UNSIGNED NOT NULL, name VARCHAR(64) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB');
        self::$connection->executeStatement('CREATE TABLE session (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, terminal_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, opened_at DATETIME NOT NULL, closed_at DATETIME NULL, opening_cash DECIMAL(10,2) NOT NULL, counted_cash DECIMAL(10,2) NULL, expected_cash DECIMAL(10,2) NULL, difference DECIMAL(10,2) NULL, INDEX idx_session_open (id, user_id, closed_at)) ENGINE=InnoDB');
        self::$connection->executeStatement("CREATE TABLE movement (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, session_id INT UNSIGNED NOT NULL, type ENUM('sale','refund','cash_in','cash_out') NOT NULL, amount DECIMAL(10,2) NOT NULL, concept VARCHAR(255) NULL, ps_order_id INT UNSIGNED NULL, created_at DATETIME NOT NULL, INDEX idx_session_type (session_id, type)) ENGINE=InnoDB");
        self::$connection->executeStatement('CREATE TABLE z_report (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, session_id INT UNSIGNED NOT NULL UNIQUE, generated_at DATETIME NOT NULL, totals JSON NOT NULL, signature_hash CHAR(64) NOT NULL) ENGINE=InnoDB');
    }

    public function testMovementAcceptedWhileCloseStartsIsIncludedInZReport(): void
    {
        [$userId, $shopId, $sessionId] = $this->openSession();
        $barrier = $this->barrierDirectory();
        $movement = $this->startWorker('movement', $userId, $shopId, $sessionId, $barrier);
        $this->waitForFile($barrier . '/movement-at-insert');
        $close = $this->startWorker('close', $userId, $shopId, $sessionId);

        // A vulnerable implementation completes close here. A serialized close waits on the session row.
        usleep(300_000);
        file_put_contents($barrier . '/allow-movement', 'continue', LOCK_EX);

        $movementResult = $this->waitForWorker($movement);
        $closeResult = $this->waitForWorker($close);
        $this->assertTrue($movementResult['ok']);
        $this->assertTrue($closeResult['ok']);
        $this->assertSame(125.0, $closeResult['result']['expected_cash']);

        $totals = json_decode((string) self::$connection->fetchOne('SELECT totals FROM z_report WHERE session_id = ?', [$sessionId]), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(25.0, (float) $totals['cash_in_total']);
        $this->assertSame(1, (int) $totals['counts']['cash_in']);
    }

    public function testCompletedCloseRejectsLaterMovementWithoutEffects(): void
    {
        [$userId, $shopId, $sessionId] = $this->openSession();
        $close = $this->waitForWorker($this->startWorker('close', $userId, $shopId, $sessionId));
        $movement = $this->waitForWorker($this->startWorker('movement', $userId, $shopId, $sessionId));

        $this->assertTrue($close['ok']);
        $this->assertFalse($movement['ok']);
        $this->assertSame(0, (int) self::$connection->fetchOne('SELECT COUNT(*) FROM movement WHERE session_id = ?', [$sessionId]));
        $this->assertSame(100.0, (float) self::$connection->fetchOne('SELECT expected_cash FROM session WHERE id = ?', [$sessionId]));
    }

    public function testCloseRejectsAnOlderOuterTransactionWithoutEffects(): void
    {
        $outer = DriverManager::getConnection(self::databaseConfig());
        try {
            $outer->beginTransaction();
            $outer->fetchOne('SELECT COUNT(*) FROM session');
            [$userId, $shopId, $sessionId] = $this->openSession();
            $stack = new RequestStack();
            $stack->push(SessionRequest::create([], [
                'shop_id' => $shopId,
                'cash_session_id' => $sessionId,
            ]));
            $service = new CashSessionService($outer, new TerminalService($outer), $stack);

            try {
                $service->closeSession($userId, 100.0);
                self::fail('Expected closeSession to reject the caller transaction.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Cash session mutations cannot run inside an existing transaction', $e->getMessage());
            }
            $this->assertTrue($outer->isTransactionActive());
            $outer->rollBack();

            $current = $this->service($shopId, $sessionId)->getCurrentSession($userId);
            $this->assertNotNull($current);
            $this->assertSame(100.0, $current['expected_cash']);
            $this->assertSame(100.0, $this->service($shopId, $sessionId)->getXReport($userId)['expected_cash']);
            $this->assertSame(0, $current['movements']['cash_in']);
            $this->assertSame(0, (int) self::$connection->fetchOne('SELECT COUNT(*) FROM z_report WHERE session_id = ?', [$sessionId]));
        } finally {
            if ($outer->isTransactionActive()) {
                $outer->rollBack();
            }
            $outer->close();
        }
    }

    public function testMovementRejectsAnOuterTransactionWithoutEffects(): void
    {
        [$userId, $shopId, $sessionId] = $this->openSession();
        $outer = DriverManager::getConnection(self::databaseConfig());
        try {
            $outer->beginTransaction();
            $outer->fetchOne('SELECT COUNT(*) FROM terminal');
            $stack = new RequestStack();
            $stack->push(SessionRequest::create([], [
                'shop_id' => $shopId,
                'cash_session_id' => $sessionId,
            ]));
            $service = new CashSessionService($outer, new TerminalService($outer), $stack);

            try {
                $service->addMovement($userId, 'cash_in', 25.0, 'External transaction');
                self::fail('Expected addMovement to reject the caller transaction.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Cash session mutations cannot run inside an existing transaction', $e->getMessage());
            }
            $this->assertTrue($outer->isTransactionActive());
            $outer->rollBack();

            $report = $this->service($shopId, $sessionId)->getXReport($userId);
            $this->assertSame(100.0, $report['expected_cash']);
            $this->assertSame(0, $report['movements']['cash_in']);
            $this->assertSame(0, (int) self::$connection->fetchOne('SELECT COUNT(*) FROM movement WHERE session_id = ?', [$sessionId]));
        } finally {
            if ($outer->isTransactionActive()) {
                $outer->rollBack();
            }
            $outer->close();
        }
    }

    public function testCloseRollsBackWhenZReportWriteFails(): void
    {
        [$userId, $shopId, $sessionId] = $this->openSession();
        self::$connection->insert('z_report', [
            'session_id' => $sessionId,
            'generated_at' => '2026-01-01 00:00:00',
            'totals' => '{}',
            'signature_hash' => str_repeat('0', 64),
        ]);

        $this->expectException(\Throwable::class);
        try {
            $this->service($shopId, $sessionId)->closeSession($userId, 100.0);
        } finally {
            $this->assertSame(1, (int) self::$connection->fetchOne('SELECT COUNT(*) FROM session WHERE id = ? AND closed_at IS NULL', [$sessionId]));
            $this->assertSame(100.0, $this->service($shopId, $sessionId)->getXReport($userId)['expected_cash']);
        }
    }

    /** @return array{int, int, int} */
    private function openSession(): array
    {
        $id = ++self::$case;
        $userId = 810000 + $id;
        $shopId = 820000 + $id;
        $opened = $this->service($shopId)->openSession($userId, 100.0);

        return [$userId, $shopId, (int) $opened['session_id']];
    }

    private function service(int $shopId, ?int $sessionId = null): CashSessionService
    {
        $stack = new RequestStack();
        $data = ['shop_id' => $shopId];
        if ($sessionId !== null) {
            $data['cash_session_id'] = $sessionId;
        }
        $stack->push(SessionRequest::create([], $data));

        return new CashSessionService(self::$connection, new TerminalService(self::$connection), $stack);
    }

    private function barrierDirectory(): string
    {
        $path = dirname(__DIR__, 4) . '/var/cash-close-tests/' . uniqid('case-', true);
        mkdir($path, 0700, true);

        return $path;
    }

    /** @return resource */
    private function startWorker(string $mode, int $userId, int $shopId, int $sessionId, ?string $barrier = null)
    {
        $environment = $barrier === null ? null : ['TPV_CASH_CLOSE_BARRIER' => $barrier] + $_ENV;
        $process = proc_open([
            PHP_BINARY,
            __DIR__ . '/fixtures/cash_session_worker.php',
            $mode,
            (string) $userId,
            (string) $shopId,
            (string) $sessionId,
        ], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $environment);
        if (!is_resource($process)) {
            self::fail('Unable to start cash session worker.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$process, $pipes[1], $pipes[2]];
    }

    /** @param array{resource, resource, resource} $worker @return array<string, mixed> */
    private function waitForWorker(array $worker): array
    {
        [$process, $stdout, $stderr] = $worker;
        $deadline = microtime(true) + 12;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $output = stream_get_contents($stdout);
                $errors = stream_get_contents($stderr);
                fclose($stdout);
                fclose($stderr);
                proc_close($process);
                $this->assertSame('', $errors);

                return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::fail('Cash session worker did not finish within 12 seconds.');
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 8;
        while (!file_exists($path) && microtime(true) < $deadline) {
            usleep(50_000);
        }
        $this->assertFileExists($path);
    }

    /** @return array<string, mixed> */
    private static function databaseConfig(): array
    {
        return [
            'driver' => 'pdo_mysql',
            'host' => (string) getenv('TPV_DB_HOST'),
            'port' => (int) getenv('TPV_DB_PORT'),
            'dbname' => (string) getenv('TPV_DB_NAME'),
            'user' => (string) getenv('TPV_DB_USER'),
            'password' => (string) getenv('TPV_DB_PASSWORD'),
            'charset' => 'utf8mb4',
        ];
    }
}
