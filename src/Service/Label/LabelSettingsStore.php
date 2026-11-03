<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Label;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\InvalidFieldNameException;

/**
 * Label settings stored on each till (`terminal.label_height_mm`,
 * `terminal.label_cut_each`, migration Version00000038LabelSettings). Until that
 * migration runs the columns are missing: reads answer the defaults and writes are
 * refused, so the TPV keeps working.
 */
class LabelSettingsStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function forTerminal(int $terminalId): LabelSettings
    {
        try {
            $row = $this->connection->fetchAssociative('SELECT label_height_mm, label_cut_each FROM terminal WHERE id = ?', [$terminalId]);
        } catch (InvalidFieldNameException) {
            return LabelSettings::defaults();
        }

        return $row === false ? LabelSettings::defaults() : LabelSettings::fromStored($row['label_height_mm'] ?? null, $row['label_cut_each'] ?? false);
    }

    public function isAvailable(): bool
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'terminal' AND COLUMN_NAME IN ('label_height_mm', 'label_cut_each')"
        ) === 2;
    }
}
