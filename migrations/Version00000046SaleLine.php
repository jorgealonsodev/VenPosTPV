<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * sale_line: one row per ticket line with its VAT rate, base and VAT amount, written
 * in the sale transaction (docs/prd/informes-ventas.md, I1). Also the date indexes the
 * sales reports filter on. Every step is guarded so the migration can run again.
 * Existing sales get their lines from `tpv:sales:backfill-lines`, not from here.
 */
final class Version00000046SaleLine extends AbstractMigration
{
    private const INDEXES = [
        ['order_meta', 'idx_order_meta_created', '(created_at)'],
        ['order_meta', 'idx_order_meta_terminal_created', '(terminal_id, created_at)'],
        ['refund', 'idx_refund_status_completed', '(status, completed_at)'],
    ];

    public function getDescription(): string
    {
        return 'Create sale_line and the date indexes of order_meta and refund';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('CREATE TABLE IF NOT EXISTS sale_line (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            line_index SMALLINT UNSIGNED NOT NULL,
            sold_at DATETIME NOT NULL,
            terminal_id INT UNSIGNED NOT NULL,
            session_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NULL,
            id_product_attribute INT UNSIGNED NOT NULL DEFAULT 0,
            reference VARCHAR(64) NULL,
            name VARCHAR(255) NOT NULL,
            category_id INT UNSIGNED NULL,
            category_name VARCHAR(255) NULL,
            quantity INT NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            line_discount_pct DECIMAL(5,2) NULL DEFAULT 0,
            gross_amount DECIMAL(10,2) NOT NULL,
            paid_amount DECIMAL(10,2) NOT NULL,
            vat_rate DECIMAL(5,2) NULL,
            base_amount DECIMAL(10,2) NULL,
            vat_amount DECIMAL(10,2) NULL,
            approximate TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uk_sale_line_order_line (order_id, line_index),
            KEY idx_sale_line_sold (sold_at),
            KEY idx_sale_line_terminal_sold (terminal_id, sold_at),
            KEY idx_sale_line_product_sold (product_id, id_product_attribute, sold_at),
            KEY idx_sale_line_category_sold (category_id, sold_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        foreach (self::INDEXES as [$table, $index, $columns]) {
            if (!$this->hasIndex($table, $index)) {
                $this->addSql(sprintf('ALTER TABLE %s ADD INDEX %s %s', $table, $index, $columns));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        foreach (array_reverse(self::INDEXES) as [$table, $index]) {
            if ($this->hasIndex($table, $index)) {
                $this->addSql(sprintf('ALTER TABLE %s DROP INDEX %s', $table, $index));
            }
        }
        $this->addSql('DROP TABLE IF EXISTS sale_line');
    }

    private function hasIndex(string $table, string $index): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        ) > 0;
    }
}
