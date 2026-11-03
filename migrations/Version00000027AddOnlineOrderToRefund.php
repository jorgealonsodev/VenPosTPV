<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Refunds of online orders (R10): the TPV pays nothing back, so the refund row
 * records that it belongs to an online order (online), what the office still has
 * to refund from the PrestaShop back office (web_refund_amount), and the order's
 * reference and date for the credit note, which has no order_meta to read them
 * from. refund_line keeps the line name for the same reason.
 */
final class Version00000027AddOnlineOrderToRefund extends AbstractMigration
{
    private const COLUMNS = [
        'refund' => [
            'online' => 'ADD COLUMN online TINYINT(1) NOT NULL DEFAULT 0 AFTER exchange_credit',
            'web_refund_amount' => 'ADD COLUMN web_refund_amount DECIMAL(10,2) NULL AFTER online',
            'source_reference' => 'ADD COLUMN source_reference VARCHAR(32) NULL AFTER web_refund_amount',
            'source_date' => 'ADD COLUMN source_date DATETIME NULL AFTER source_reference',
        ],
        'refund_line' => [
            'name' => 'ADD COLUMN name VARCHAR(255) NULL AFTER id_product_attribute',
        ],
    ];

    public function getDescription(): string
    {
        return 'Add online order flag, pending web refund amount, source reference/date and line name to refunds';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        // information_schema, like the other refund migrations (Doctrine cannot map ENUM columns here).
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $definition) {
                $exists = (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, $column]
                ) > 0;
                if (!$exists) {
                    $this->addSql('ALTER TABLE ' . $table . ' ' . $definition);
                }
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('ALTER TABLE refund_line DROP COLUMN name');
        $this->addSql('ALTER TABLE refund DROP COLUMN source_date, DROP COLUMN source_reference, DROP COLUMN web_refund_amount, DROP COLUMN online');
    }
}
