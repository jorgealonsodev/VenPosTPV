<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;

/**
 * The VERI*FACTU switch, off until the shop turns it on in Ajustes. Off, every
 * ticket and credit note still gets its chained fiscal record; the switch only
 * adds the tax QR and the «VERI*FACTU» legend to the printed tickets (and, later,
 * the submission of the records to the AEAT).
 */
class VerifactuSettings
{
    private const NAME = 'verifactu.enabled';

    private const CREATE_TABLE = 'CREATE TABLE IF NOT EXISTS app_setting (
        name VARCHAR(64) NOT NULL PRIMARY KEY,
        value TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function isEnabled(): bool
    {
        try {
            return $this->connection->fetchOne('SELECT value FROM app_setting WHERE name = ?', [self::NAME]) === '1';
        } catch (TableNotFoundException) {
            return false;
        }
    }

    public function setEnabled(bool $enabled): void
    {
        // A host where migrations are run by hand may not have the table yet.
        $this->connection->executeStatement(self::CREATE_TABLE);
        $this->connection->executeStatement(
            'INSERT INTO app_setting (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [self::NAME, $enabled ? '1' : '0']
        );
    }
}
