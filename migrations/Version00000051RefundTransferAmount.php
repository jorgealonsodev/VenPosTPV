<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * refund.transfer_amount: the part of a refund paid back by bank transfer, for a
 * ticket that was paid by transfer (docs/pago-transferencia.md). Like card_amount
 * it never touches the drawer; the X/Z report shows it apart. Guarded so the
 * migration can run again.
 */
final class Version00000051RefundTransferAmount extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add refund.transfer_amount';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        if (!$this->hasColumn('refund', 'transfer_amount')) {
            $this->addSql('ALTER TABLE refund ADD COLUMN transfer_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER card_confirmed');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        if ($this->hasColumn('refund', 'transfer_amount')) {
            $this->addSql('ALTER TABLE refund DROP COLUMN transfer_amount');
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    }
}
