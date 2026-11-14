<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Sync;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Refund\PsRestockClient;
use VenPosTPV\Service\Sync\OutboxSync;

/**
 * Concurrent uploaders deadlock each other in MySQL (1213). The statements that
 * change outbox rows outside a transaction, and the transaction that closes a row,
 * are tried again a few times instead of failing the run or sending the row again.
 */
final class OutboxSyncDeadlockTest extends TestCase
{
    private static function deadlock(): DeadlockException
    {
        return new DeadlockException(PdoDriverException::new(new \PDOException('Deadlock found when trying to get lock; try restarting transaction')), null);
    }

    public function testRecoveringStuckRowsSurvivesADeadlock(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('fetchFirstColumn')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, "status = 'processing'") ? [7, 8] : []
        );
        $deadlocks = 2;
        $recovered = [];
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []) use (&$deadlocks, &$recovered): int {
            if (str_contains($sql, 'The upload was interrupted')) {
                if ($deadlocks-- > 0) {
                    throw self::deadlock();
                }
                $recovered[] = end($params);

                return 1;
            }

            return 0;
        });

        $counts = (new OutboxSync($connection, $this->createMock(PsOrderClient::class)))->run();

        self::assertSame(2, $counts['recovered']);
    }

    public function testRecoveringStuckRowsNeverFailsTheRunWhenTheDeadlockPersists(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('fetchFirstColumn')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, "status = 'processing'") ? [7] : []
        );
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql): int {
            if (str_contains($sql, 'The upload was interrupted')) {
                throw self::deadlock();
            }

            return 0;
        });

        $counts = (new OutboxSync($connection, $this->createMock(PsOrderClient::class)))->run();

        self::assertSame(0, $counts['recovered'], 'the row is recovered by a later run');
    }

    public function testClosingARowAfterADeadlockIsTriedAgainWithoutSendingItTwice(): void
    {
        $row = ['id' => 5, 'type' => 'restock', 'order_id' => null, 'sequence' => 0, 'request_id' => 'restock-1', 'attempts' => 1, 'claim_token' => 'token',
            'payload' => (string) json_encode(['request_id' => 'restock-1', 'lines' => [], '_local' => ['refund_id' => 3]])];
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $claims = 1;
        $connection->method('fetchFirstColumn')->willReturnCallback(static function (string $sql) use (&$claims): array {
            return str_contains($sql, 'ORDER BY o.id LIMIT 10') && $claims-- > 0 ? [5] : [];
        });
        $connection->method('fetchAssociative')->willReturn($row);
        $statements = [];
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$statements): int {
            $statements[] = $sql;

            return str_contains($sql, "status = 'processing'") || str_contains($sql, "status = 'done'") ? 1 : 0;
        });
        $deadlocks = 1;
        $connection->method('transactional')->willReturnCallback(static function (\Closure $work) use (&$deadlocks): mixed {
            if ($deadlocks-- > 0) {
                throw self::deadlock();
            }

            return $work();
        });
        $restock = $this->createMock(PsRestockClient::class);
        $restock->expects(self::once())->method('restock')->willReturn(['restocked_lines' => [], 'restock_failed' => [], 'replayed' => false]);

        $counts = (new OutboxSync($connection, $this->createMock(PsOrderClient::class), restockClient: $restock))->run();

        self::assertSame(1, $counts['uploaded']);
        self::assertSame(0, $counts['retried'], 'no backoff after the deadlock');
        self::assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'record_failures = record_failures + 1'))), 'not counted as a failure recording the answer');
    }
}
