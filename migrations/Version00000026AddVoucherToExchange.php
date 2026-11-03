<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An exchange whose new sale can never be completed (credit_open) can be closed by
 * issuing a PrestaShop voucher for its open credit (status 'closed_voucher'). The
 * exchange row keeps the voucher's code, amount and cart rule, when it was
 * requested (a request with an unknown outcome is never repeated automatically)
 * and the cash session it was issued in, for the X/Z report.
 */
final class Version00000026AddVoucherToExchange extends AbstractMigration
{
    private const COLUMNS = [
        'voucher_code' => 'ADD COLUMN voucher_code VARCHAR(32) NULL AFTER new_reference',
        'voucher_amount' => 'ADD COLUMN voucher_amount DECIMAL(10,2) NULL AFTER voucher_code',
        'voucher_cart_rule_id' => 'ADD COLUMN voucher_cart_rule_id INT UNSIGNED NULL AFTER voucher_amount',
        'voucher_requested_at' => 'ADD COLUMN voucher_requested_at DATETIME NULL AFTER voucher_cart_rule_id',
        'voucher_session_id' => 'ADD COLUMN voucher_session_id INT UNSIGNED NULL AFTER voucher_requested_at',
    ];

    public function getDescription(): string
    {
        return 'Add voucher code, amount, cart rule, request time and session to exchange';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        // information_schema, like the other refund migrations (Doctrine cannot map ENUM columns here).
        foreach (self::COLUMNS as $column => $definition) {
            $exists = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exchange' AND COLUMN_NAME = ?",
                [$column]
            ) > 0;
            if (!$exists) {
                $this->addSql('ALTER TABLE exchange ' . $definition);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('ALTER TABLE exchange DROP COLUMN voucher_session_id, DROP COLUMN voucher_requested_at, DROP COLUMN voucher_cart_rule_id, DROP COLUMN voucher_amount, DROP COLUMN voucher_code');
    }
}
