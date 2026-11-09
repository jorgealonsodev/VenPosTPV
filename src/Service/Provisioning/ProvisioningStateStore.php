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
 *
 * A blocked failure (ProvisioningBlockedException) also has "blocked": true, the
 * "block_key" it belongs to and "reported_at", the last time the minute task
 * reported it instead of retrying it.
 */
class ProvisioningStateStore
{
    private const PREFIX = 'provisioning.';

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{reason: string, attempted_at: \DateTimeImmutable, blocked: bool, block_key: ?string, reported_at: ?\DateTimeImmutable}|null */
    public function failure(string $stepId): ?array
    {
        $data = $this->read($stepId);
        if ($data === null || !\is_string($data['reason'] ?? null) || !\is_string($data['attempted_at'] ?? null)) {
            return null;
        }
        $at = self::date($data['attempted_at']);
        if ($at === null) {
            return null;
        }
        $blocked = ($data['blocked'] ?? false) === true;

        return [
            'reason' => $data['reason'],
            'attempted_at' => $at,
            'blocked' => $blocked,
            'block_key' => $blocked && \is_string($data['block_key'] ?? null) ? $data['block_key'] : null,
            'reported_at' => $blocked && \is_string($data['reported_at'] ?? null) ? self::date($data['reported_at']) : null,
        ];
    }

    /** @param string|null $blockKey set for a blocked failure (the minute task stops retrying it) */
    public function recordFailure(string $stepId, string $reason, \DateTimeImmutable $at, ?string $blockKey = null): void
    {
        $data = ['reason' => $reason, 'attempted_at' => $at->format(\DATE_ATOM)];
        if ($blockKey !== null) {
            $data += ['blocked' => true, 'block_key' => $blockKey, 'reported_at' => $at->format(\DATE_ATOM)];
        }
        $this->write($stepId, $data);
    }

    /** The minute task has reported the blocked step at $at instead of retrying it. */
    public function markReported(string $stepId, \DateTimeImmutable $at): void
    {
        $data = $this->read($stepId);
        if ($data === null || ($data['blocked'] ?? false) !== true) {
            return;
        }
        $data['reported_at'] = $at->format(\DATE_ATOM);
        $this->write($stepId, $data);
    }

    public function clear(string $stepId): void
    {
        try {
            $this->connection->executeStatement('DELETE FROM app_setting WHERE name = ?', [self::PREFIX . $stepId]);
        } catch (TableNotFoundException) {
        }
    }

    /** @return array<string, mixed>|null */
    private function read(string $stepId): ?array
    {
        try {
            $json = $this->connection->fetchOne('SELECT value FROM app_setting WHERE name = ?', [self::PREFIX . $stepId]);
        } catch (TableNotFoundException) {
            return null;
        }
        $data = \is_string($json) ? json_decode($json, true) : null;

        return \is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $data */
    private function write(string $stepId, array $data): void
    {
        $value = json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        try {
            // Portable upsert (the unit tests run on SQLite).
            $this->connection->transactional(function (Connection $conn) use ($stepId, $value): void {
                $conn->executeStatement('DELETE FROM app_setting WHERE name = ?', [self::PREFIX . $stepId]);
                $conn->executeStatement('INSERT INTO app_setting (name, value) VALUES (?, ?)', [self::PREFIX . $stepId, $value]);
            });
        } catch (TableNotFoundException) {
        }
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
