<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ticket layout of each till (Ajustes → Cajas): left and right margins in mm
 * (0-10, NULL = 2 mm) and the logo width as a percentage of the printable width
 * (20-100, NULL = the whole width). Literal values only, and every step guarded
 * through information_schema so it can run again.
 */
final class Version00000045TicketMarginsAndLogo extends AbstractMigration
{
    private const COLUMNS = [
        'ticket_margin_left_mm' => 'DECIMAL(3,1) NULL',
        'ticket_margin_right_mm' => 'DECIMAL(3,1) NULL',
        'ticket_logo_width_pct' => 'TINYINT UNSIGNED NULL',
    ];

    public function getDescription(): string
    {
        return 'Add ticket_margin_left_mm, ticket_margin_right_mm and ticket_logo_width_pct to terminal';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        foreach (self::COLUMNS as $column => $definition) {
            if (!$this->hasColumn($column)) {
                $this->addSql(sprintf('ALTER TABLE terminal ADD COLUMN %s %s', $column, $definition));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        foreach (array_reverse(array_keys(self::COLUMNS)) as $column) {
            if ($this->hasColumn($column)) {
                $this->addSql(sprintf('ALTER TABLE terminal DROP COLUMN %s', $column));
            }
        }
    }

    private function hasColumn(string $column): bool
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'terminal' AND COLUMN_NAME = ?",
            [$column]
        ) > 0;
    }
}
