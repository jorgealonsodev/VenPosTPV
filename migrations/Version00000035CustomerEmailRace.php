<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two customers created at the same time with the same email (PRD C2) and answers
 * that cannot be recorded (PRD S4):
 *
 * - customer.merged_into: a till-created customer whose PrestaShop customer was
 *   already linked to another local row is merged into that row (made inactive and
 *   pointing at it), so its sales, carts and parked tickets sell to that customer.
 * - outbox.record_failures: consecutive failures recording PrestaShop's answer for a
 *   row; after a few the row becomes an incident instead of retrying forever.
 *
 * Nothing existing is rewritten. Literal values only, and every step guarded through
 * information_schema, so it can run again on a database that already has part of it.
 */
final class Version00000035CustomerEmailRace extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add customer.merged_into and outbox.record_failures';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        if (!$this->hasColumn('customer', 'merged_into')) {
            $this->addSql('ALTER TABLE customer ADD COLUMN merged_into INT UNSIGNED NULL AFTER outbox_id');
        }
        if (!$this->hasColumn('outbox', 'record_failures')) {
            $this->addSql('ALTER TABLE outbox ADD COLUMN record_failures INT UNSIGNED NOT NULL DEFAULT 0 AFTER attempts');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('ALTER TABLE outbox DROP COLUMN record_failures');
        $this->addSql('ALTER TABLE customer DROP COLUMN merged_into');
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    }
}
