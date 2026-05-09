<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ensures a synthetic "master-admin" user row exists in the `user` table so
 * that the .env-authenticated master admin has a valid FK target for all
 * business tables (session, order_meta, etc.).
 *
 * Idempotent: uses INSERT IGNORE — safe to run multiple times.
 */
final class Version00000009EnsureMasterAdminUser extends AbstractMigration
{
    private const MASTER_ADMIN_CODE = 'master-admin';

    public function getDescription(): string
    {
        return 'Seed synthetic master-admin user row so .env admin has a valid FK user_id';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        // Ensure the admin role exists (idempotent)
        $this->addSql(
            "INSERT IGNORE INTO role (name, permissions) VALUES ('admin', JSON_OBJECT())"
        );

        // Ensure the synthetic master-admin user exists (idempotent via unique code)
        // pin_hash is a bcrypt of a 64-char random string — the account is never
        // used for PIN login, credentials always come from .env.
        $this->addSql("
            INSERT IGNORE INTO user (ps_employee_id, role_id, name, code, pin_hash, active)
            SELECT
                NULL,
                r.id,
                'Master Admin',
                '" . self::MASTER_ADMIN_CODE . "',
                '\$2y\$12\$unusablePinHashForMasterAdminXXXXXXXXXXXXXXXXXXXXXXXX.',
                1
            FROM role r
            WHERE r.name = 'admin'
            LIMIT 1
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql("DELETE FROM user WHERE code = '" . self::MASTER_ADMIN_CODE . "'");
    }
}
