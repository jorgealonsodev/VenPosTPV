<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ensures the `cashier` role exists. Only `admin` was ever created outside the
 * dev seed, so creating a cashier from Ajustes → Empleados failed with
 * "Rol 'cashier' no encontrado".
 *
 * Idempotent: INSERT IGNORE on the unique role name.
 */
final class Version00000041EnsureCashierRole extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ensure the cashier role exists';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql("INSERT IGNORE INTO role (name, permissions) VALUES ('cashier', JSON_OBJECT())");
    }

    public function down(Schema $schema): void
    {
        // Kept on purpose: employees may already reference the role.
    }
}
