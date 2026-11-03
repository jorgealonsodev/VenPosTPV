<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Refunds without a ticket can be paid with a PrestaShop voucher. The refund row
 * keeps its code, amount and cart rule, and when the voucher was requested: a
 * request with an unknown outcome must never be repeated automatically.
 */
final class Version00000022AddVoucherToRefund extends AbstractMigration
{
    private const COLUMNS = [
        'voucher_code' => 'ADD COLUMN voucher_code VARCHAR(32) NULL AFTER card_confirmed',
        'voucher_amount' => 'ADD COLUMN voucher_amount DECIMAL(10,2) NULL AFTER voucher_code',
        'voucher_cart_rule_id' => 'ADD COLUMN voucher_cart_rule_id INT UNSIGNED NULL AFTER voucher_amount',
        'voucher_requested_at' => 'ADD COLUMN voucher_requested_at DATETIME NULL AFTER voucher_cart_rule_id',
    ];

    public function getDescription(): string
    {
        return 'Add voucher code, amount, cart rule and request time to refund';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        // information_schema instead of introspectTable(): Doctrine cannot map refund's ENUM column.
        foreach (self::COLUMNS as $column => $definition) {
            $exists = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'refund' AND COLUMN_NAME = ?",
                [$column]
            ) > 0;
            if (!$exists) {
                $this->addSql('ALTER TABLE refund ' . $definition);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('ALTER TABLE refund DROP COLUMN voucher_requested_at, DROP COLUMN voucher_cart_rule_id, DROP COLUMN voucher_amount, DROP COLUMN voucher_code');
    }
}
