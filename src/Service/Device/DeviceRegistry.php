<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Device;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Which computer works on which till (S10).
 *
 * A device is identified by a random 256-bit token the server generates; only its
 * SHA-256 is stored (terminal_device.token_hash), so a database dump cannot be used
 * to impersonate a till. One device per till (unique terminal_id).
 */
class DeviceRegistry
{
    /** last_seen_at is refreshed at most this often, not on every request. */
    private const SEEN_EVERY_MINUTES = 5;

    public function __construct(private readonly Connection $connection)
    {
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/', $token) === 1;
    }

    /** The active till linked to this token, or null. */
    public function terminalForToken(?string $token): ?int
    {
        if ($token === null || !self::isWellFormed($token)) {
            return null;
        }

        $hash = self::hashToken($token);
        $row = $this->connection->fetchAssociative(
            'SELECT d.token_hash, d.terminal_id FROM terminal_device d
             INNER JOIN terminal t ON t.id = d.terminal_id AND t.active = 1
             WHERE d.token_hash = ?',
            [$hash]
        );
        if ($row === false || !hash_equals((string) $row['token_hash'], $hash)) {
            return null;
        }

        $this->connection->executeStatement(
            'UPDATE terminal_device SET last_seen_at = NOW()
             WHERE token_hash = ? AND (last_seen_at IS NULL OR last_seen_at < NOW() - INTERVAL ' . self::SEEN_EVERY_MINUTES . ' MINUTE)',
            [$hash]
        );

        return (int) $row['terminal_id'];
    }

    /**
     * Links this computer to the till and returns its new token (a fresh one, never
     * one the browser proposed). A till on another computer is only taken with
     * $moveFromOtherDevice ("Pasar la caja a este equipo"), which unlinks that
     * computer. The computer's previous till, if any, is released.
     *
     * @throws TillHasDeviceException
     * @throws UnknownTillException
     */
    public function link(int $terminalId, int $userId, bool $moveFromOtherDevice, ?string $currentToken): string
    {
        $token = self::generateToken();
        $currentHash = $currentToken !== null && self::isWellFormed($currentToken) ? self::hashToken($currentToken) : null;

        $this->connection->transactional(function () use ($terminalId, $userId, $moveFromOtherDevice, $currentHash, $token): void {
            if ($this->connection->fetchOne('SELECT id FROM terminal WHERE id = ? AND active = 1 FOR UPDATE', [$terminalId]) === false) {
                throw new UnknownTillException();
            }

            $holder = $this->connection->fetchOne('SELECT token_hash FROM terminal_device WHERE terminal_id = ? FOR UPDATE', [$terminalId]);
            $heldByThisDevice = $holder !== false && $currentHash !== null && hash_equals((string) $holder, $currentHash);
            if ($holder !== false && !$heldByThisDevice && !$moveFromOtherDevice) {
                throw new TillHasDeviceException();
            }

            $this->connection->executeStatement('DELETE FROM terminal_device WHERE terminal_id = ?', [$terminalId]);
            if ($currentHash !== null) {
                $this->connection->executeStatement('DELETE FROM terminal_device WHERE token_hash = ?', [$currentHash]);
            }
            $this->connection->insert('terminal_device', [
                'token_hash' => self::hashToken($token),
                'terminal_id' => $terminalId,
                'linked_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'last_seen_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'linked_by_user_id' => $userId > 0 ? $userId : null,
            ]);
        });

        return $token;
    }

    /**
     * "First device adopts the only till": with exactly one active till in the shop
     * and no computer linked to it, this computer takes it. Keeps single-till
     * installs working right after the deploy; a second till always needs an admin.
     *
     * @return array{terminal_id: int, token: string}|null
     */
    public function adoptOnlyTill(int $shopId, int $userId): ?array
    {
        $ids = $this->connection->fetchFirstColumn('SELECT id FROM terminal WHERE ps_shop_id = ? AND active = 1', [$shopId]);
        if (\count($ids) !== 1) {
            return null;
        }

        $terminalId = (int) $ids[0];
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM terminal_device WHERE terminal_id = ?', [$terminalId]) > 0) {
            return null;
        }

        $token = self::generateToken();
        try {
            $this->connection->insert('terminal_device', [
                'token_hash' => self::hashToken($token),
                'terminal_id' => $terminalId,
                'linked_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'last_seen_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'linked_by_user_id' => $userId > 0 ? $userId : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another computer adopted it at the same moment.
            return null;
        }

        return ['terminal_id' => $terminalId, 'token' => $token];
    }

    /**
     * Active tills of the shop with their linked computer (never its token hash).
     *
     * @return list<array{id: int, name: string, ticket_letter: ?string, printer_type: string, dataphone_mode: string, device: ?array{linked_at: string, last_seen_at: ?string}, this_device: bool}>
     */
    public function listTills(int $shopId, ?string $currentToken): array
    {
        $currentHash = $currentToken !== null && self::isWellFormed($currentToken) ? self::hashToken($currentToken) : null;
        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.id, t.name, t.ticket_letter, t.printer_type, t.dataphone_mode, d.token_hash, d.linked_at, d.last_seen_at
             FROM terminal t LEFT JOIN terminal_device d ON d.terminal_id = t.id
             WHERE t.ps_shop_id = ? AND t.active = 1
             ORDER BY t.id',
            [$shopId]
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'ticket_letter' => $row['ticket_letter'] !== null ? (string) $row['ticket_letter'] : null,
            'printer_type' => (string) $row['printer_type'],
            'dataphone_mode' => (string) $row['dataphone_mode'],
            'device' => $row['token_hash'] === null ? null : [
                'linked_at' => (string) $row['linked_at'],
                'last_seen_at' => $row['last_seen_at'] !== null ? (string) $row['last_seen_at'] : null,
            ],
            'this_device' => $row['token_hash'] !== null && $currentHash !== null && hash_equals((string) $row['token_hash'], $currentHash),
        ], $rows);
    }
}
