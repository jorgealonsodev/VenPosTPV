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

    /**
     * Whether this version sends the fiscal records to the AEAT. Until it does, the
     * switch stays off: a «VERI*FACTU» ticket whose record the AEAT never receives
     * breaks RRSIF art. 16.1 («remitir efectivamente») and fails the cotejo.
     */
    public const SUBMISSION_AVAILABLE = false;

    public const UNAVAILABLE_MESSAGE = 'VERI*FACTU no se puede activar todavía: esta versión del TPV aún no envía los registros a la AEAT';

    private const CREATE_TABLE = 'CREATE TABLE IF NOT EXISTS app_setting (
        name VARCHAR(64) NOT NULL PRIMARY KEY,
        value TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function canEnable(): bool
    {
        return self::SUBMISSION_AVAILABLE;
    }

    public function isEnabled(): bool
    {
        if (!$this->canEnable()) {
            return false;
        }
        try {
            return $this->connection->fetchOne('SELECT value FROM app_setting WHERE name = ?', [self::NAME]) === '1';
        } catch (TableNotFoundException) {
            return false;
        }
    }

    /** @throws \DomainException when turning it on before the records can be sent */
    public function setEnabled(bool $enabled): void
    {
        if ($enabled && !$this->canEnable()) {
            throw new \DomainException(self::UNAVAILABLE_MESSAGE);
        }
        // A host where migrations are run by hand may not have the table yet.
        $this->connection->executeStatement(self::CREATE_TABLE);
        $this->connection->executeStatement(
            'INSERT INTO app_setting (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [self::NAME, $enabled ? '1' : '0']
        );
    }
}
