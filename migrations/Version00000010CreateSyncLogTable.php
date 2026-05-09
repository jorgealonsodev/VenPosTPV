<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version00000010CreateSyncLogTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sync_log table for tracking sync timestamps';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('
            CREATE TABLE IF NOT EXISTS sync_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type ENUM(\'catalog\', \'stock\', \'prices\') NOT NULL,
                direction ENUM(\'inbound\', \'outbound\') NOT NULL,
                synced_at DATETIME NOT NULL,
                row_count INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_type_direction (type, direction),
                INDEX idx_type_direction (type, direction),
                INDEX idx_synced_at (synced_at)
            ) ENGINE=InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('DROP TABLE IF EXISTS sync_log');
    }
}
