<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Offline refunds and exchanges (PRD venta-local-primero D1-D3) and checkout
 * idempotency.
 *
 * - refund.order_id: the local sale (order_meta.id) a refund belongs to, so a sale
 *   not uploaded yet (ps_order_id NULL) can be refunded; existing refunds of TPV
 *   sales get it from their ps_order_id. Online-order refunds keep it NULL.
 * - exchange.order_id / new_order_id: the local returned sale and new sale;
 *   exchange.ps_order_id becomes NULLable for sales not uploaded yet.
 * - outbox.order_id becomes NULLable: a restock of a refund without a ticket
 *   belongs to no sale and has nothing to wait for.
 * - order_meta.request_id: the browser's id of one payment attempt, unique, so a
 *   repeated /api/checkout returns the sale it already created.
 *
 * Every step is guarded with information_schema (Doctrine cannot introspect the
 * ENUM tables here) so the migration can run again, and values are written
 * literally because the test database runner executes the SQL without parameters.
 */
final class Version00000032OfflineRefunds extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Local sale of refunds and exchanges, nullable outbox.order_id, order_meta.request_id';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        if (!$this->hasColumn('refund', 'order_id')) {
            $this->addSql('ALTER TABLE refund ADD COLUMN order_id BIGINT UNSIGNED NULL AFTER ps_order_id');
        }
        if (!$this->hasIndex('refund', 'idx_refund_sale_status')) {
            $this->addSql('ALTER TABLE refund ADD INDEX idx_refund_sale_status (order_id, status)');
        }
        $this->addSql('UPDATE refund r JOIN order_meta o ON o.ps_order_id = r.ps_order_id
            SET r.order_id = o.id
            WHERE r.order_id IS NULL AND r.ps_order_id IS NOT NULL');

        if ($this->isNotNull('exchange', 'ps_order_id')) {
            $this->addSql('ALTER TABLE exchange MODIFY ps_order_id INT UNSIGNED NULL');
        }
        if (!$this->hasColumn('exchange', 'order_id')) {
            $this->addSql('ALTER TABLE exchange ADD COLUMN order_id BIGINT UNSIGNED NULL AFTER ps_order_id');
        }
        if (!$this->hasColumn('exchange', 'new_order_id')) {
            $this->addSql('ALTER TABLE exchange ADD COLUMN new_order_id BIGINT UNSIGNED NULL AFTER new_ps_order_id');
        }
        if (!$this->hasIndex('exchange', 'idx_exchange_sale_status')) {
            $this->addSql('ALTER TABLE exchange ADD INDEX idx_exchange_sale_status (order_id, status)');
        }
        $this->addSql('UPDATE exchange e JOIN order_meta o ON o.ps_order_id = e.ps_order_id
            SET e.order_id = o.id
            WHERE e.order_id IS NULL AND e.ps_order_id IS NOT NULL');
        $this->addSql('UPDATE exchange e JOIN order_meta o ON o.ps_order_id = e.new_ps_order_id
            SET e.new_order_id = o.id
            WHERE e.new_order_id IS NULL AND e.new_ps_order_id IS NOT NULL');

        if ($this->isNotNull('outbox', 'order_id')) {
            $this->addSql('ALTER TABLE outbox MODIFY order_id BIGINT UNSIGNED NULL');
        }

        if (!$this->hasColumn('order_meta', 'request_id')) {
            $this->addSql('ALTER TABLE order_meta ADD COLUMN request_id VARCHAR(64) NULL AFTER ps_reference');
        }
        if (!$this->hasIndex('order_meta', 'uk_order_meta_request')) {
            $this->addSql('ALTER TABLE order_meta ADD UNIQUE KEY uk_order_meta_request (request_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM refund WHERE order_id IS NOT NULL AND ps_order_id IS NULL') > 0
                || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM outbox WHERE order_id IS NULL') > 0
                || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM exchange WHERE ps_order_id IS NULL') > 0,
            'Refunds, exchanges or restocks not uploaded to PrestaShop yet would be lost: upload them first.'
        );

        $this->addSql('ALTER TABLE order_meta DROP INDEX uk_order_meta_request, DROP COLUMN request_id');
        $this->addSql('ALTER TABLE outbox MODIFY order_id BIGINT UNSIGNED NOT NULL');
        $this->addSql('ALTER TABLE exchange DROP INDEX idx_exchange_sale_status, DROP COLUMN new_order_id, DROP COLUMN order_id, MODIFY ps_order_id INT UNSIGNED NOT NULL');
        $this->addSql('ALTER TABLE refund DROP INDEX idx_refund_sale_status, DROP COLUMN order_id');
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (int) $this->connection->fetchOne(sprintf(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND COLUMN_NAME = '%s'",
            $table,
            $column
        )) > 0;
    }

    private function isNotNull(string $table, string $column): bool
    {
        return $this->connection->fetchOne(sprintf(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND COLUMN_NAME = '%s'",
            $table,
            $column
        )) === 'NO';
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
