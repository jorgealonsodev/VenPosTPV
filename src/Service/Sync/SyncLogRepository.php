<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Sync;

use Doctrine\DBAL\Connection;

class SyncLogRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function upsert(string $type, string $direction, \DateTimeInterface $syncedAt, ?int $rowCount): void
    {
        $sql = <<<'SQL'
            INSERT INTO `sync_log` (type, direction, synced_at, row_count, created_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                synced_at = VALUES(synced_at),
                row_count = VALUES(row_count)
            SQL;

        $this->connection->executeStatement($sql, [
            $type,
            $direction,
            $syncedAt->format('Y-m-d H:i:s'),
            $rowCount,
        ]);
    }

    public function getLastSyncByDirection(string $direction): ?SyncLogEntry
    {
        $sql = <<<'SQL'
            SELECT id, type, direction, synced_at, row_count, created_at
            FROM `sync_log`
            WHERE direction = ?
            ORDER BY synced_at DESC
            LIMIT 1
            SQL;

        $result = $this->connection->executeQuery($sql, [$direction]);
        $row = $result->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return new SyncLogEntry(
            (int) $row['id'],
            (string) $row['type'],
            (string) $row['direction'],
            new \DateTimeImmutable((string) $row['synced_at']),
            $row['row_count'] !== null ? (int) $row['row_count'] : null,
            new \DateTimeImmutable((string) $row['created_at']),
        );
    }

    /**
     * @return array<string, SyncLogEntry>
     */
    public function getLastSyncsByDirection(string $direction): array
    {
        $sql = <<<'SQL'
            SELECT id, type, direction, synced_at, row_count, created_at
            FROM `sync_log` s1
            WHERE direction = ?
              AND synced_at = (
                  SELECT MAX(synced_at)
                  FROM `sync_log` s2
                  WHERE s2.type = s1.type AND s2.direction = s1.direction
              )
            SQL;

        $result = $this->connection->executeQuery($sql, [$direction]);
        $rows = $result->fetchAllAssociative();

        $entries = [];
        foreach ($rows as $row) {
            $entries[(string) $row['type']] = new SyncLogEntry(
                (int) $row['id'],
                (string) $row['type'],
                (string) $row['direction'],
                new \DateTimeImmutable((string) $row['synced_at']),
                $row['row_count'] !== null ? (int) $row['row_count'] : null,
                new \DateTimeImmutable((string) $row['created_at']),
            );
        }

        return $entries;
    }
}
