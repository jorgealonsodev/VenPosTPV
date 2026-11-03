<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Which scan code tickets carry: 'barcode' (Code128, readable by 1D scanners),
 * 'qr' or 'both'. Defaults to 'barcode'.
 */
final class Version00000025AddTicketCodeToTerminal extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add ticket_code (barcode|qr|both, default barcode) to terminal";
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $hasColumn = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'terminal' AND COLUMN_NAME = 'ticket_code'"
        ) > 0;
        if (!$hasColumn) {
            $this->addSql("ALTER TABLE terminal ADD COLUMN ticket_code VARCHAR(10) NOT NULL DEFAULT 'barcode' AFTER ticket_width_px");
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('ALTER TABLE terminal DROP COLUMN ticket_code');
    }
}
