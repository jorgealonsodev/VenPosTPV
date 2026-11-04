<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Columns that were only ever added by hand (migrations/00004_stock_sync_interval.sql,
 * install.sql), so a fresh database stopped at Version00000021, which places a
 * column after terminal.stock_push_interval_seconds, and the catalog sync and
 * stock push failed on catalog_stock.ps_stock_id.
 * Sorts between Version00000020 and Version00000021; a no-op where they exist.
 */
final class Version00000020aRestoreHandAddedColumns extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add terminal stock intervals and catalog_stock.ps_stock_id where they are missing';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        if (!$this->hasColumn('terminal', 'stock_sync_interval_seconds')) {
            $this->addSql('ALTER TABLE terminal ADD COLUMN stock_sync_interval_seconds INT UNSIGNED NOT NULL DEFAULT 60');
        }
        if (!$this->hasColumn('terminal', 'stock_push_interval_seconds')) {
            $this->addSql('ALTER TABLE terminal ADD COLUMN stock_push_interval_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER stock_sync_interval_seconds');
        }
        if (!$this->hasColumn('catalog_stock', 'ps_stock_id')) {
            $this->addSql('ALTER TABLE catalog_stock ADD COLUMN ps_stock_id INT UNSIGNED NULL DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        // Deliberately empty: on existing databases these columns predate this migration.
    }

    private function hasColumn(string $table, string $column): bool
    {
        // information_schema instead of introspectTable(): Doctrine cannot map terminal's ENUM columns.
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    }
}
