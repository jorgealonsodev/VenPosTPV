<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version00000013AddTicketContentToTerminal extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add store address and ticket header/footer fields to terminal table';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql("ALTER TABLE terminal ADD COLUMN store_address VARCHAR(255) NOT NULL DEFAULT '' AFTER printer_type");
        $this->addSql('ALTER TABLE terminal ADD COLUMN ticket_header TEXT DEFAULT NULL AFTER store_address');
        $this->addSql('ALTER TABLE terminal ADD COLUMN ticket_footer TEXT DEFAULT NULL AFTER ticket_header');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('ALTER TABLE terminal DROP COLUMN ticket_footer');
        $this->addSql('ALTER TABLE terminal DROP COLUMN ticket_header');
        $this->addSql('ALTER TABLE terminal DROP COLUMN store_address');
    }
}
