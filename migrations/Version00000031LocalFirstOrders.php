<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Local-first sales (PRD venta-local-primero V1, S5, §5).
 *
 * order_meta stops being keyed by the PrestaShop order: it gets a local key `id`,
 * `reference` (the 9-character ticket code, unique), and `ps_order_id` becomes
 * NULLable and unique, filled in when the sale is uploaded. `ps_reference` keeps a
 * different reference only if PrestaShop ever answered one. The existing `synced`
 * flag is the sync status (0 = waiting for upload); existing rows are synced.
 * movement gets `order_id` so a sale's cash movement can learn its PrestaShop id
 * after the upload. `outbox` holds the uploads still to do.
 *
 * Every step is guarded with information_schema (Doctrine cannot introspect the
 * ENUM tables here) so the migration can run again, and values are written
 * literally because the test database runner executes the SQL without parameters.
 */
final class Version00000031LocalFirstOrders extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Local key, ticket reference and nullable ps_order_id on order_meta; movement.order_id; outbox';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        if (!$this->hasColumn('order_meta', 'id')) {
            // One statement: the old primary key goes, the local key takes its place,
            // and the PrestaShop id stays unique (existing rows keep it).
            $this->addSql('ALTER TABLE order_meta
                DROP PRIMARY KEY,
                ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST,
                MODIFY ps_order_id INT UNSIGNED NULL,
                ADD UNIQUE KEY uk_order_meta_ps_order (ps_order_id)');
        }
        if (!$this->hasColumn('order_meta', 'reference')) {
            $this->addSql('ALTER TABLE order_meta ADD COLUMN reference VARCHAR(9) NULL AFTER id');
        }
        if (!$this->hasIndex('order_meta', 'uk_order_meta_reference')) {
            $this->addSql('ALTER TABLE order_meta ADD UNIQUE KEY uk_order_meta_reference (reference)');
        }
        if (!$this->hasColumn('order_meta', 'ps_reference')) {
            $this->addSql('ALTER TABLE order_meta ADD COLUMN ps_reference VARCHAR(9) NULL AFTER ps_order_id');
        }

        // Existing sales keep their PrestaShop reference as their code. A reference
        // shared by several orders (a split PrestaShop cart) or longer than the
        // column stays NULL: it is still found through payment_breakdown.
        $this->addSql("UPDATE order_meta o
            JOIN (
                SELECT MIN(id) AS id, JSON_UNQUOTE(JSON_EXTRACT(payment_breakdown, '$.reference')) AS ref
                FROM order_meta
                GROUP BY ref
                HAVING COUNT(*) = 1 AND MAX(reference IS NOT NULL) = 0
            ) f ON f.id = o.id
            SET o.reference = f.ref
            WHERE o.reference IS NULL AND o.ps_order_id IS NOT NULL
              AND f.ref IS NOT NULL AND CHAR_LENGTH(f.ref) BETWEEN 1 AND 9");
        $this->addSql('UPDATE order_meta SET synced = 1 WHERE ps_order_id IS NOT NULL AND synced <> 1');

        if (!$this->hasColumn('movement', 'order_id')) {
            $this->addSql('ALTER TABLE movement ADD COLUMN order_id BIGINT UNSIGNED NULL AFTER ps_order_id');
        }
        if (!$this->hasIndex('movement', 'idx_movement_order')) {
            $this->addSql('ALTER TABLE movement ADD INDEX idx_movement_order (order_id)');
        }
        $this->addSql("UPDATE movement m JOIN order_meta o ON o.ps_order_id = m.ps_order_id
            SET m.order_id = o.id
            WHERE m.order_id IS NULL AND m.type = 'sale'");

        // Not offline_queue: it is unused, keyed by terminal, has no request_id,
        // order key or retry schedule, and its ENUM status would need rewriting.
        $this->addSql("CREATE TABLE IF NOT EXISTS outbox (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(32) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            sequence INT UNSIGNED NOT NULL,
            request_id VARCHAR(64) NOT NULL,
            payload JSON NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            claim_token VARCHAR(32) NULL,
            claimed_at DATETIME NULL,
            last_error VARCHAR(1000) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            done_at DATETIME NULL,
            UNIQUE KEY uk_outbox_request (request_id),
            UNIQUE KEY uk_outbox_order_sequence (order_id, sequence),
            KEY idx_outbox_due (status, next_attempt_at)
        ) ENGINE=InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM order_meta WHERE ps_order_id IS NULL') > 0,
            'Sales not uploaded to PrestaShop yet would be lost: upload them first.'
        );

        $this->addSql('DROP TABLE IF EXISTS outbox');
        $this->addSql('ALTER TABLE movement DROP INDEX idx_movement_order, DROP COLUMN order_id');
        $this->addSql('ALTER TABLE order_meta DROP INDEX uk_order_meta_reference, DROP INDEX uk_order_meta_ps_order, DROP COLUMN reference, DROP COLUMN ps_reference, MODIFY id BIGINT UNSIGNED NOT NULL');
        $this->addSql('ALTER TABLE order_meta DROP PRIMARY KEY, DROP COLUMN id, MODIFY ps_order_id INT UNSIGNED NOT NULL, ADD PRIMARY KEY (ps_order_id)');
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (int) $this->connection->fetchOne(sprintf(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND COLUMN_NAME = '%s'",
            $table,
            $column
        )) > 0;
    }

    private function hasIndex(string $table, string $index): bool
    {
        return (int) $this->connection->fetchOne(sprintf(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND INDEX_NAME = '%s'",
            $table,
            $index
        )) > 0;
    }
}
