<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version00000004AddPrintColumnsToOrderMeta extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add print_attempts and printed_at to order_meta for ticket printing';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('
            ALTER TABLE order_meta
            ADD COLUMN print_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            ADD COLUMN printed_at DATETIME NULL
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
            DROP COLUMN print_attempts,
            DROP COLUMN printed_at
        ');
    }
}
