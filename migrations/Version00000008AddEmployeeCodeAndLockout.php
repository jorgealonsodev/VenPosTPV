<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version00000008AddEmployeeCodeAndLockout extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add employee code, login_failures counter, and locked_at to user table';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('ALTER TABLE user ADD COLUMN code VARCHAR(32) NULL UNIQUE AFTER name');
        $this->addSql('ALTER TABLE user ADD COLUMN login_failures TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER last_login_at');
        $this->addSql('ALTER TABLE user ADD COLUMN locked_at DATETIME NULL AFTER login_failures');
        $this->addSql('CREATE INDEX idx_user_code ON user (code)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('DROP INDEX idx_user_code ON user');
        $this->addSql('ALTER TABLE user DROP COLUMN locked_at');
        $this->addSql('ALTER TABLE user DROP COLUMN login_failures');
        $this->addSql('ALTER TABLE user DROP COLUMN code');
    }
}
