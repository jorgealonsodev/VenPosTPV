<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * numbering_change: the audit of every change to where a ticket or credit-note
 * series continues (Ajustes → Cajas), with its reason and who made it. The app only
 * ever inserts into it.
 */
final class Version00000044NumberingChange extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create numbering_change (audit of next ticket / credit-note number changes)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('CREATE TABLE IF NOT EXISTS numbering_change (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            series VARCHAR(16) NOT NULL,
            previous_last INT UNSIGNED NOT NULL,
            new_last INT UNSIGNED NOT NULL,
            reason VARCHAR(255) NOT NULL,
            user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_numbering_change_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE numbering_change');
    }
}
