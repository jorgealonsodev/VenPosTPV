<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version00000007AddCombinationAttributeTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add catalog_combination_attribute table for PS attribute labels';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('
            CREATE TABLE IF NOT EXISTS catalog_combination_attribute (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                combination_id INT UNSIGNED NOT NULL,
                group_name VARCHAR(128) NOT NULL,
                attribute_name VARCHAR(128) NOT NULL,
                INDEX idx_combination (combination_id),
                FOREIGN KEY (combination_id) REFERENCES catalog_combination(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('DROP TABLE IF EXISTS catalog_combination_attribute');
    }
}
