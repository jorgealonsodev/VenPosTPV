<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * user.full_name: the employee's «Nombre y apellidos» (Ajustes → Empleados), printed
 * on the ticket and the cash reports as the cashier; `name` stays the user name for
 * login and the screen. NULL = not set, the user name is printed. Guarded so the
 * migration can run again.
 */
final class Version00000049UserFullName extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.full_name';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        if (!$this->hasColumn('user', 'full_name')) {
            $this->addSql('ALTER TABLE user ADD COLUMN full_name VARCHAR(128) NULL AFTER name');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        if ($this->hasColumn('user', 'full_name')) {
            $this->addSql('ALTER TABLE user DROP COLUMN full_name');
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    }
}
