<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version00000006AddImageUrlToCatalogProduct extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add image_url column to catalog_product';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('
            ALTER TABLE catalog_product
            ADD COLUMN image_url VARCHAR(512) NULL AFTER active
        ');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('
            ALTER TABLE catalog_product
            DROP COLUMN image_url
        ');
    }
}
