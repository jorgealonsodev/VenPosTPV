<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version00000016CreateParkedTicketTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create parked_ticket table for persistent parked sales across terminals and sessions';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('
            CREATE TABLE IF NOT EXISTS parked_ticket (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                client_ref VARCHAR(64) NOT NULL,
                terminal_id INT UNSIGNED NOT NULL DEFAULT 1,
                user_id INT UNSIGNED NULL,
                cashier_name VARCHAR(128) NOT NULL DEFAULT \'Cajero\',
                note VARCHAR(120) NULL,
                customer JSON NULL,
                lines JSON NOT NULL,
                discount JSON NULL,
                total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_parked_ticket_client_ref (client_ref),
                INDEX idx_parked_ticket_created (created_at),
                INDEX idx_parked_ticket_terminal (terminal_id)
            ) ENGINE=InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('DROP TABLE IF EXISTS parked_ticket');
    }
}
