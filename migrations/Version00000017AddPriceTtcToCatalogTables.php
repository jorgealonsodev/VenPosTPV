<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the authoritative tax-included price returned by the PrestaShop Web
 * Service (`price[key][use_tax]=1&price[key][use_reduction]=1`).
 *
 * `catalog_product.price` stores the Web Service base price, which is
 * tax-excluded and has no reduction applied, while PrestaShop books the order
 * with `Cart::getOrderTotal(true, Cart::BOTH)`. Keeping both values lets the
 * checkout charge the amount PrestaShop will actually book and fall back to the
 * previous behaviour when the authoritative price is unavailable.
 *
 * Nullable on purpose: a failed price fetch must never block a catalog sync.
 *
 * The migration is idempotent because `migrations/install.sql` also declares the
 * columns for fresh installations, so `bin/console doctrine:migrations:migrate`
 * can run on top of an already complete schema.
 */
final class Version00000017AddPriceTtcToCatalogTables extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable price_ttc to catalog_product and catalog_combination';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['catalog_product', 'catalog_combination'])) {
            // Fresh database without the catalog schema yet: install.sql or
            // Version00000003CreateCatalogTables creates the columns instead.
            return;
        }

        if (!$schemaManager->introspectTable('catalog_product')->hasColumn('price_ttc')) {
            $this->addSql('
                ALTER TABLE catalog_product
                ADD COLUMN price_ttc DECIMAL(20,6) NULL AFTER price
            ');
        }

        if (!$schemaManager->introspectTable('catalog_combination')->hasColumn('price_ttc')) {
            $this->addSql('
                ALTER TABLE catalog_combination
                ADD COLUMN price_ttc DECIMAL(20,6) NULL AFTER price_impact
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

        if ($schemaManager->introspectTable('catalog_product')->hasColumn('price_ttc')) {
            $this->addSql('ALTER TABLE catalog_product DROP COLUMN price_ttc');
        }

        if ($schemaManager->introspectTable('catalog_combination')->hasColumn('price_ttc')) {
            $this->addSql('ALTER TABLE catalog_combination DROP COLUMN price_ttc');
        }
    }
}
