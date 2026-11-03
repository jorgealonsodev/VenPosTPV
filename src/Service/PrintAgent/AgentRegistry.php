<?php

declare(strict_types=1);

namespace VenPosTPV\Service\PrintAgent;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Pairing and keys of the print agents (PRD E1-E7).
 *
 * - An administrator creates a 6-digit code for a till (10 minutes, one live code
 *   per till, E2). Only an HMAC of the code is stored, so a copy of the database
 *   does not reveal it.
 * - The agent sends the code once: in one transaction the code is marked used and
 *   the till's key (256 random bits) is created; only its SHA-256 is stored (E3).
 *   Pairing again replaces the previous key (E5). Failed attempts are counted
 *   across all IPs: 20 in 10 minutes void every live code of every till (E7); the
 *   per-IP limit (5 per minute, 429) is in AgentApiController.
 * - Each key belongs to one till (E4) and never expires (E6); revoking it cuts the
 *   agent at once (E5). Every authenticated call records the last use and IP (S1).
 */
class AgentRegistry
{
    public const CODE_TTL_SECONDS = 600;
    /** E7: failed pairing attempts from all IPs within FAILURE_WINDOW_SECONDS that void the live codes. */
    public const MAX_FAILURES = 20;
    public const FAILURE_WINDOW_SECONDS = 600;
    /** failed_attempts of a voided code. */
    private const VOIDED = 255;
    private const FAILURE_SCOPE = 'agent_pair_failure';
    public const CONNECTED_SECONDS = 90;
    private const KEY_PATTERN = '/\A[A-Za-z0-9_-]{43}\z/';

    private readonly ClockInterface $clock;
    private readonly RateLimiter $failures;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $secret,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
        $this->failures = new RateLimiter($connection, $this->clock);
    }

    /**
     * A new code for the till; any previous unused code of the till is voided.
     *
     * @return array{code: string, expires_at: \DateTimeImmutable}
     */
    public function createPairingCode(int $terminalId, ?int $userId): array
    {
        $now = $this->clock->now();
        $expires = $now->modify(sprintf('+%d seconds', self::CODE_TTL_SECONDS));

        return $this->connection->transactional(function () use ($terminalId, $userId, $now, $expires): array {
            $this->connection->executeStatement('DELETE FROM agent_pairing WHERE terminal_id = ? AND used_at IS NULL', [$terminalId]);
            do {
                $code = str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT);
                $hash = $this->codeHash($code);
                // Two tills never share a live code.
                $taken = (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM agent_pairing WHERE code_hash = ? AND used_at IS NULL AND expires_at > ?',
                    [$hash, $now->format('Y-m-d H:i:s')]
                ) > 0;
            } while ($taken);

            $this->connection->insert('agent_pairing', [
                'code_hash' => $hash,
                'terminal_id' => $terminalId,
                'created_by_user_id' => $userId,
                'created_at' => $now->format('Y-m-d H:i:s'),
                'expires_at' => $expires->format('Y-m-d H:i:s'),
                'failed_attempts' => 0,
            ]);

            return ['code' => $code, 'expires_at' => $expires];
        });
    }

    /**
     * Consumes a code and creates the till's key.
     *
     * @return array{key: string, terminal_id: int, terminal_name: string}
     *
     * @throws PairingCodeInvalidException
     */
    public function pair(string $code, string $hostname, string $os, string $version, ?string $ip): array
    {
        if (preg_match('/\A\d{6}\z/', $code) !== 1) {
            $this->countFailure();
            throw new PairingCodeInvalidException();
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $result = $this->connection->transactional(function () use ($code, $hostname, $os, $version, $ip, $now): ?array {
            $pairing = $this->connection->fetchAssociative(
                'SELECT id, terminal_id, created_by_user_id FROM agent_pairing
                 WHERE code_hash = ? AND used_at IS NULL AND expires_at > ? AND failed_attempts < ?
                 ORDER BY id DESC LIMIT 1 FOR UPDATE',
                [$this->codeHash($code), $now, self::VOIDED]
            );
            if ($pairing === false) {
                return null;
            }
            $used = $this->connection->executeStatement(
                'UPDATE agent_pairing SET used_at = ? WHERE id = ? AND used_at IS NULL',
                [$now, (int) $pairing['id']]
            );
            if ((int) $used !== 1) {
                return null;
            }

            $terminalId = (int) $pairing['terminal_id'];
            $key = self::newKey();
            $this->connection->executeStatement(
                'INSERT INTO print_agent (terminal_id, key_hash, paired_at, paired_by_user_id, last_seen_at, last_ip, agent_version, hostname, os, printers, revoked_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)
                 ON DUPLICATE KEY UPDATE key_hash = VALUES(key_hash), paired_at = VALUES(paired_at), paired_by_user_id = VALUES(paired_by_user_id),
                     last_seen_at = VALUES(last_seen_at), last_ip = VALUES(last_ip), agent_version = VALUES(agent_version),
                     hostname = VALUES(hostname), os = VALUES(os), printers = NULL, revoked_at = NULL',
                [
                    $terminalId,
                    hash('sha256', $key),
                    $now,
                    $pairing['created_by_user_id'] !== null ? (int) $pairing['created_by_user_id'] : null,
                    $now,
                    self::clean($ip, 45),
                    self::clean($version, 32),
                    self::clean($hostname, 255),
                    self::clean($os, 64),
                ]
            );
            $name = $this->connection->fetchOne('SELECT name FROM terminal WHERE id = ?', [$terminalId]);

            return ['key' => $key, 'terminal_id' => $terminalId, 'terminal_name' => \is_string($name) ? $name : ''];
        });

        if ($result === null) {
            $this->countFailure();
            throw new PairingCodeInvalidException();
        }

        return $result;
    }

    /**
     * The agent behind a Bearer key, or null. Looked up by the key's SHA-256 and
     * compared in constant time; a revoked key is refused.
     *
     * @return array{terminal_id: int, agent_version: string|null, hostname: string|null}|null
     */
    public function authenticate(?string $key): ?array
    {
        if ($key === null || preg_match(self::KEY_PATTERN, $key) !== 1) {
            return null;
        }
        $hash = hash('sha256', $key);
        $row = $this->connection->fetchAssociative(
            'SELECT terminal_id, key_hash, agent_version, hostname FROM print_agent WHERE key_hash = ? AND revoked_at IS NULL',
            [$hash]
        );
        if ($row === false || !hash_equals((string) $row['key_hash'], $hash)) {
            return null;
        }

        return [
            'terminal_id' => (int) $row['terminal_id'],
            'agent_version' => $row['agent_version'] !== null ? (string) $row['agent_version'] : null,
            'hostname' => $row['hostname'] !== null ? (string) $row['hostname'] : null,
        ];
    }

    /** Still the till's live key (checked again while a long poll waits). */
    public function isActive(int $terminalId, string $key): bool
    {
        $agent = $this->authenticate($key);

        return $agent !== null && $agent['terminal_id'] === $terminalId;
    }

    /** S1: every agent call records its last use. */
    public function touch(int $terminalId, ?string $ip): void
    {
        $this->connection->executeStatement(
            'UPDATE print_agent SET last_seen_at = ?, last_ip = ? WHERE terminal_id = ? AND revoked_at IS NULL',
            [$this->clock->now()->format('Y-m-d H:i:s'), self::clean($ip, 45), $terminalId]
        );
    }

    /**
     * S1, S5: what the agent reports about itself and its printers.
     *
     * @param list<array{target: string, name: string, state: string, detail: string|null}> $printers
     */
    public function updateStatus(int $terminalId, ?string $version, ?string $hostname, array $printers): void
    {
        $this->connection->executeStatement(
            'UPDATE print_agent SET agent_version = COALESCE(?, agent_version), hostname = COALESCE(?, hostname), printers = ? WHERE terminal_id = ? AND revoked_at IS NULL',
            [self::clean($version, 32), self::clean($hostname, 255), json_encode($printers, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), $terminalId]
        );
    }

    /** E5: disconnecting in Ajustes invalidates the key at once (and any live code). */
    public function revoke(int $terminalId): bool
    {
        return $this->connection->transactional(function () use ($terminalId): bool {
            $this->connection->executeStatement('DELETE FROM agent_pairing WHERE terminal_id = ? AND used_at IS NULL', [$terminalId]);

            return (int) $this->connection->executeStatement(
                'UPDATE print_agent SET revoked_at = ? WHERE terminal_id = ? AND revoked_at IS NULL',
                [$this->clock->now()->format('Y-m-d H:i:s'), $terminalId]
            ) > 0;
        });
    }

    /**
     * What Ajustes shows for a till's agent (E6, S3).
     *
     * @return array{paired: bool, connected: bool, last_seen: string|null, last_ip: string|null, version: string|null, hostname: string|null, os: string|null, paired_at: string|null, printers: list<mixed>}
     */
    public function statusOf(int $terminalId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT paired_at, last_seen_at, last_ip, agent_version, hostname, os, printers FROM print_agent WHERE terminal_id = ? AND revoked_at IS NULL',
            [$terminalId]
        );
        if ($row === false) {
            return ['paired' => false, 'connected' => false, 'last_seen' => null, 'last_ip' => null, 'version' => null, 'hostname' => null, 'os' => null, 'paired_at' => null, 'printers' => []];
        }

        $now = $this->clock->now();
        $lastSeen = $row['last_seen_at'] !== null ? new \DateTimeImmutable((string) $row['last_seen_at'], $now->getTimezone()) : null;
        $printers = \is_string($row['printers']) ? json_decode($row['printers'], true) : null;

        return [
            'paired' => true,
            'connected' => $lastSeen !== null && $now->getTimestamp() - $lastSeen->getTimestamp() < self::CONNECTED_SECONDS,
            'last_seen' => $lastSeen?->format(\DATE_ATOM),
            'last_ip' => $row['last_ip'] !== null ? (string) $row['last_ip'] : null,
            'version' => $row['agent_version'] !== null ? (string) $row['agent_version'] : null,
            'hostname' => $row['hostname'] !== null ? (string) $row['hostname'] : null,
            'os' => $row['os'] !== null ? (string) $row['os'] : null,
            'paired_at' => (new \DateTimeImmutable((string) $row['paired_at'], $now->getTimezone()))->format(\DATE_ATOM),
            'printers' => \is_array($printers) ? array_values($printers) : [],
        ];
    }

    /**
     * E7: failed attempts are counted across all IPs; at 20 within 10 minutes every
     * live code of every till is voided (an administrator makes a new one).
     */
    private function countFailure(): void
    {
        $this->failures->hit(self::FAILURE_SCOPE, 'all');
        if ($this->failures->countWithin(self::FAILURE_SCOPE, 'all', self::FAILURE_WINDOW_SECONDS) < self::MAX_FAILURES) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE agent_pairing SET failed_attempts = ? WHERE used_at IS NULL AND expires_at > ?',
            [self::VOIDED, $this->clock->now()->format('Y-m-d H:i:s')]
        );
    }

    private function codeHash(string $code): string
    {
        return hash_hmac('sha256', 'print-agent-pairing:' . $code, $this->secret);
    }

    /** 256 random bits, base64url without padding (43 characters). */
    private static function newKey(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function clean(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
