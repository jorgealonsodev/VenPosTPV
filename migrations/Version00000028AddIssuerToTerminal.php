<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issuer data a simplified invoice (ticket) must show: the legal name and NIF.
 */
final class Version00000028AddIssuerToTerminal extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add issuer legal_name and tax_id (NIF) to terminal';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        foreach (['legal_name' => 'VARCHAR(120) NULL AFTER store_address', 'tax_id' => 'VARCHAR(16) NULL AFTER legal_name'] as $column => $definition) {
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

        $this->addSql('ALTER TABLE terminal DROP COLUMN tax_id, DROP COLUMN legal_name');
    }
}
