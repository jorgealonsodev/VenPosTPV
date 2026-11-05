<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Shop-wide settings (one row per name), first the outgoing mail account used to
 * send tickets by email. Secrets stored here are encrypted by the service.
 */
final class Version00000040AppSetting extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create app_setting (shop-wide name/value settings)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('CREATE TABLE IF NOT EXISTS app_setting (
            name VARCHAR(64) NOT NULL PRIMARY KEY,
            value TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS app_setting');
    }
}
