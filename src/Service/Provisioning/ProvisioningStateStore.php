<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Provisioning;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;

/**
 * The last failure of each provisioning step, one app_setting row per step
 * («provisioning.<id>» = {"reason": "…", "attempted_at": "…"}), so two runs never
 * overwrite each other's step. A done step has no row. Before the migrations have
 * created app_setting nothing is remembered and nothing fails.
 */
class ProvisioningStateStore
{
    private const PREFIX = 'provisioning.';

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{reason: string, attempted_at: \DateTimeImmutable}|null */
    public function failure(string $stepId): ?array
    {
        try {
            $json = $this->connection->fetchOne('SELECT value FROM app_setting WHERE name = ?', [self::PREFIX . $stepId]);
        } catch (TableNotFoundException) {
            return null;
        }
        $data = \is_string($json) ? json_decode($json, true) : null;
        if (!\is_array($data) || !\is_string($data['reason'] ?? null) || !\is_string($data['attempted_at'] ?? null)) {
            return null;
        }
        try {
            $at = new \DateTimeImmutable($data['attempted_at']);
        } catch (\Exception) {
            return null;
        }

        return ['reason' => $data['reason'], 'attempted_at' => $at];
    }

    public function recordFailure(string $stepId, string $reason, \DateTimeImmutable $at): void
    {
        $value = json_encode(['reason' => $reason, 'attempted_at' => $at->format(\DATE_ATOM)], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        try {
            // Portable upsert (the unit tests run on SQLite).
            $this->connection->transactional(function (Connection $conn) use ($stepId, $value): void {
                $conn->executeStatement('DELETE FROM app_setting WHERE name = ?', [self::PREFIX . $stepId]);
                $conn->executeStatement('INSERT INTO app_setting (name, value) VALUES (?, ?)', [self::PREFIX . $stepId, $value]);
            });
        } catch (TableNotFoundException) {
        }
    }

    public function clear(string $stepId): void
    {
        try {
            $this->connection->executeStatement('DELETE FROM app_setting WHERE name = ?', [self::PREFIX . $stepId]);
        } catch (TableNotFoundException) {
        }
    }
}
