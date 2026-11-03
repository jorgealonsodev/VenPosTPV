<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Store name printed at the top of tickets, set in Settings instead of the .env default.
 */
final class Version00000029AddStoreNameToTerminal extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add store_name to terminal';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        foreach (['store_name' => 'VARCHAR(120) NULL AFTER printer_type'] as $column => $definition) {
            $hasColumn = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'terminal' AND COLUMN_NAME = ?",
                [$column]
            ) > 0;
            if (!$hasColumn) {
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

        $this->addSql('ALTER TABLE terminal DROP COLUMN store_name');
    }
}
