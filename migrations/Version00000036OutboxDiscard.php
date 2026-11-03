<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Discarded incidents (PRD I3): an administrator can give up uploading a row that
 * PrestaShop will never accept (a deleted product). The row keeps its error and
 * becomes `discarded`; these columns record who discarded it, when and why, so
 * nothing disappears without a trace. The local sale and the cash never change.
 *
 * Nothing existing is rewritten. Literal values only, and every step guarded through
 * information_schema, so it can run again on a database that already has part of it.
 */
final class Version00000036OutboxDiscard extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add outbox.discarded_at, discarded_by_user_id and discard_reason';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        if (!$this->hasColumn('outbox', 'discarded_at')) {
            $this->addSql('ALTER TABLE outbox ADD COLUMN discarded_at DATETIME NULL AFTER done_at');
        }
        if (!$this->hasColumn('outbox', 'discarded_by_user_id')) {
            $this->addSql('ALTER TABLE outbox ADD COLUMN discarded_by_user_id INT UNSIGNED NULL AFTER discarded_at');
        }
        if (!$this->hasColumn('outbox', 'discard_reason')) {
            $this->addSql('ALTER TABLE outbox ADD COLUMN discard_reason VARCHAR(255) NULL AFTER discarded_by_user_id');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('ALTER TABLE outbox DROP COLUMN discard_reason');
        $this->addSql('ALTER TABLE outbox DROP COLUMN discarded_by_user_id');
        $this->addSql('ALTER TABLE outbox DROP COLUMN discarded_at');
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    }
}
