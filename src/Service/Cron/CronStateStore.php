<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Cron;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;

/**
 * What the scheduled task remembers, one app_setting row per job («cron.<id>»,
 * JSON) plus «cron.heartbeat», the start of the last run. Before the migrations
 * have created app_setting nothing is remembered and nothing fails.
 */
class CronStateStore
{
    private const PREFIX = 'cron.';
    private const HEARTBEAT = 'heartbeat';

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array<string, mixed> empty when the job never ran */
    public function job(string $id): array
    {
        return $this->read($id) ?? [];
    }

    /** @param array<string, mixed> $data */
    public function saveJob(string $id, array $data): void
    {
        $this->write($id, $data);
    }

    public function lastRun(): ?\DateTimeImmutable
    {
        $at = $this->read(self::HEARTBEAT)['at'] ?? null;
        if (!\is_string($at)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($at);
        } catch (\Exception) {
            return null;
        }
    }

    public function recordRun(\DateTimeImmutable $at): void
    {
        $this->write(self::HEARTBEAT, ['at' => $at->format(\DATE_ATOM)]);
    }

    /** @return array<string, mixed>|null */
    private function read(string $key): ?array
    {
        try {
            $json = $this->connection->fetchOne('SELECT value FROM app_setting WHERE name = ?', [self::PREFIX . $key]);
        } catch (TableNotFoundException) {
            return null;
        }
        $data = \is_string($json) ? json_decode($json, true) : null;

        return \is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $data */
    private function write(string $key, array $data): void
    {
        $value = json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        try {
            // Portable upsert (the unit tests run on SQLite).
            $this->connection->transactional(function (Connection $conn) use ($key, $value): void {
                $conn->executeStatement('DELETE FROM app_setting WHERE name = ?', [self::PREFIX . $key]);
                $conn->executeStatement('INSERT INTO app_setting (name, value) VALUES (?, ?)', [self::PREFIX . $key, $value]);
            });
        } catch (TableNotFoundException) {
        }
    }
}
