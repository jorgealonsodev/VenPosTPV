<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the authoritative tax-excluded price returned by the PrestaShop Web
 * Service (`price[key][use_tax]=0&price[key][use_reduction]=1`), i.e. the net
 * amount with reductions applied, which is exactly the base the shop applies its
 * tax to.
 *
 * `catalog_product.price` is not that value: it is the Web Service base price,
 * which ignores reductions. Keeping the net price lets the TPV report
 * `price_ttc - price_net` as the VAT actually charged, instead of assuming a rate
 * and instead of computing `total - subtotal` on a tax-included basis.
 *
 * Nullable on purpose: a failed price fetch must never block a catalog sync.
 *
 * Idempotent because `migrations/install.sql` also declares the columns for fresh
 * installations.
 */
final class Version00000018AddPriceNetToCatalogTables extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable price_net to catalog_product and catalog_combination';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['catalog_product', 'catalog_combination'])) {
            return;
        }

        if (!$schemaManager->introspectTable('catalog_product')->hasColumn('price_net')) {
            $this->addSql('
                ALTER TABLE catalog_product
                ADD COLUMN price_net DECIMAL(20,6) NULL AFTER price_ttc
            ');
        }

        if (!$schemaManager->introspectTable('catalog_combination')->hasColumn('price_net')) {
            $this->addSql('
                ALTER TABLE catalog_combination
                ADD COLUMN price_net DECIMAL(20,6) NULL AFTER price_ttc
            ');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['catalog_product', 'catalog_combination'])) {
            return;
        }

        if ($schemaManager->introspectTable('catalog_product')->hasColumn('price_net')) {
            $this->addSql('ALTER TABLE catalog_product DROP COLUMN price_net');
        }

        if ($schemaManager->introspectTable('catalog_combination')->hasColumn('price_net')) {
            $this->addSql('ALTER TABLE catalog_combination DROP COLUMN price_net');
        }
    }
}
