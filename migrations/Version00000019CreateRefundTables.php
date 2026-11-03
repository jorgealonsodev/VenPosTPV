<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One row per refund attempt and one per refunded sale line. The refund row is
 * written before PrestaShop is called (status pending) so an uncertain call can
 * be resumed with the same request_id instead of refunding twice; refund_line
 * keeps the quantities already returned per sale line.
 */
final class Version00000019CreateRefundTables extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create refund and refund_line tables';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql("
            CREATE TABLE IF NOT EXISTS refund (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                request_id VARCHAR(64) NOT NULL,
                status ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
                ps_order_id INT UNSIGNED NULL,
                ps_order_slip_id INT UNSIGNED NULL,
                terminal_id INT UNSIGNED NOT NULL,
                session_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                reason_code VARCHAR(32) NOT NULL,
                reason_text VARCHAR(255) NULL,
                total DECIMAL(10,2) NOT NULL,
                cash_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                card_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                card_confirmed TINYINT(1) NOT NULL DEFAULT 0,
                ps_payload JSON NULL,
                error_message VARCHAR(1000) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                UNIQUE KEY uk_refund_request (request_id),
                INDEX idx_refund_order_status (ps_order_id, status),
                INDEX idx_refund_session (session_id)
            ) ENGINE=InnoDB
        ");

        $this->addSql('
            CREATE TABLE IF NOT EXISTS refund_line (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                refund_id BIGINT UNSIGNED NOT NULL,
                line_index INT UNSIGNED NULL,
                product_id INT UNSIGNED NOT NULL,
                id_product_attribute INT UNSIGNED NOT NULL DEFAULT 0,
                quantity INT UNSIGNED NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                restock TINYINT(1) NOT NULL DEFAULT 1,
                restocked TINYINT(1) NOT NULL DEFAULT 0,
                CONSTRAINT fk_refund_line_refund FOREIGN KEY (refund_id) REFERENCES refund (id) ON DELETE CASCADE,
                INDEX idx_refund_line_refund (refund_id)
            ) ENGINE=InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('DROP TABLE IF EXISTS refund_line');
        $this->addSql('DROP TABLE IF EXISTS refund');
    }
}
