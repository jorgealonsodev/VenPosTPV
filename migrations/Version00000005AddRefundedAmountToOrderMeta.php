<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version00000005AddRefundedAmountToOrderMeta extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add refunded_amount to order_meta and ps_order_slip_id index to movement';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('
            ALTER TABLE order_meta
            ADD COLUMN refunded_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER synced
        ');

        $this->addSql('
            CREATE INDEX idx_ps_order_slip ON movement (ps_order_slip_id)
        ');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('
            ALTER TABLE order_meta
            DROP COLUMN refunded_amount
        ');

        $this->addSql('
            DROP INDEX idx_ps_order_slip ON movement
        ');
    }
}
