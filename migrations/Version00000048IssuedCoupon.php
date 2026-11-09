<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * issued_coupon: a copy of every coupon created from the till («Crear cupón»),
 * written right after PrestaShop confirms the cart rule, so its slip (ticket, PDF,
 * email) is built from the server's own data, never from values sent by the
 * browser. Guarded so the migration can run again.
 */
final class Version00000048IssuedCoupon extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create issued_coupon';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('CREATE TABLE IF NOT EXISTS issued_coupon (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ps_cart_rule_id INT UNSIGNED NOT NULL,
            code VARCHAR(32) NOT NULL,
            discount_type VARCHAR(16) NOT NULL,
            value DECIMAL(10,2) NOT NULL,
            expires_at DATE NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            quantity_per_user INT UNSIGNED NOT NULL,
            terminal_id INT UNSIGNED NULL,
            user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uk_issued_coupon_code (code),
            KEY idx_issued_coupon_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('DROP TABLE IF EXISTS issued_coupon');
    }
}
