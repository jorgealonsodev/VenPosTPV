<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Product exchanges: the returned lines are a normal refund whose money (fully or
 * partly) pays the new items instead of leaving the shop. refund.exchange_credit
 * is the part of the refund applied to the new sale; the exchange row links the
 * refund to that sale and keeps the new cart so a failed sale can be resumed
 * without refunding again.
 */
final class Version00000024CreateExchangeTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create exchange table and add refund.exchange_credit';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        // information_schema instead of introspectTable(): Doctrine cannot map refund's ENUM column.
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'refund' AND COLUMN_NAME = 'exchange_credit'"
        ) > 0;
        if (!$exists) {
            $this->addSql('ALTER TABLE refund ADD COLUMN exchange_credit DECIMAL(10,2) NULL AFTER voucher_requested_at');
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS exchange (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                ps_order_id INT UNSIGNED NOT NULL,
                refund_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                credit DECIMAL(10,2) NOT NULL,
                credit_applied DECIMAL(10,2) NOT NULL,
                new_total DECIMAL(10,2) NOT NULL,
                balance DECIMAL(10,2) NOT NULL,
                payment_method VARCHAR(16) NOT NULL,
                amount_tendered DECIMAL(10,2) NOT NULL DEFAULT 0,
                cart_data JSON NOT NULL,
                new_ps_order_id INT UNSIGNED NULL,
                new_reference VARCHAR(32) NULL,
                error_message VARCHAR(1000) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                UNIQUE KEY uk_exchange_refund (refund_id),
                INDEX idx_exchange_order_status (ps_order_id, status)
            ) ENGINE=InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('DROP TABLE IF EXISTS exchange');
        $this->addSql('ALTER TABLE refund DROP COLUMN exchange_credit');
    }
}
