<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * catalog_override: the EAN and reference of a product (id_product_attribute 0) or
 * of a combination edited at the till («Modo edición» of the sale screen). They
 * never reach PrestaShop and live apart from the synced catalog tables, which the
 * sync empties and refills, so they survive every sync and are applied over the
 * synced values. NULL = not overridden (the synced value applies). Guarded so the
 * migration can run again.
 */
final class Version00000050CatalogOverride extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create catalog_override';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('CREATE TABLE IF NOT EXISTS catalog_override (
            id_product INT UNSIGNED NOT NULL,
            id_product_attribute INT UNSIGNED NOT NULL DEFAULT 0,
            ean13 VARCHAR(13) NULL,
            reference VARCHAR(64) NULL,
            updated_by_user_id INT UNSIGNED NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id_product, id_product_attribute),
            KEY idx_catalog_override_ean13 (ean13),
            KEY idx_catalog_override_reference (reference)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('DROP TABLE IF EXISTS catalog_override');
    }
}
