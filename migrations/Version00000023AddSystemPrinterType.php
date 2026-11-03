<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the 'system' printer type: a printer installed on the machine running the
 * TPV (e.g. a USB receipt printer), printed to as raw ESC/POS.
 */
final class Version00000023AddSystemPrinterType extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Allow printer_type 'system' on terminal";
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $columnType = (string) $this->connection->fetchOne(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'terminal' AND COLUMN_NAME = 'printer_type'"
        );
        if (!str_contains($columnType, "'system'")) {
            $this->addSql("ALTER TABLE terminal MODIFY printer_type ENUM('none','pdf','escpos','system') NOT NULL DEFAULT 'pdf'");
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql("UPDATE terminal SET printer_type = 'pdf' WHERE printer_type = 'system'");
        $this->addSql("ALTER TABLE terminal MODIFY printer_type ENUM('none','pdf','escpos') NOT NULL DEFAULT 'pdf'");
    }
}
