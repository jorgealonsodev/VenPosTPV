<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version00000003CreateCatalogTables extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create catalog tables for PrestaShop product sync';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        // 1. catalog_category — no FK dependencies
        $this->addSql('
            CREATE TABLE IF NOT EXISTS catalog_category (
                id INT UNSIGNED PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                id_parent INT UNSIGNED NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_active (active)
            ) ENGINE=InnoDB
        ');

        // 2. catalog_product — references catalog_category.id via id_category_default (optional)
        $this->addSql('
            CREATE TABLE IF NOT EXISTS catalog_product (
                id INT UNSIGNED PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                reference VARCHAR(64),
                ean13 VARCHAR(13),
                price DECIMAL(20,6) NOT NULL,
                id_category_default INT UNSIGNED,
                active TINYINT(1) NOT NULL DEFAULT 1,
                synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_name (name),
                INDEX idx_ean13 (ean13),
                INDEX idx_active (active)
            ) ENGINE=InnoDB
        ');

        // 3. catalog_combination — references catalog_product.id
        $this->addSql('
            CREATE TABLE IF NOT EXISTS catalog_combination (
                id INT UNSIGNED PRIMARY KEY,
                id_product INT UNSIGNED NOT NULL,
                reference VARCHAR(64),
                ean13 VARCHAR(13),
                price_impact DECIMAL(20,6) NOT NULL DEFAULT 0,
                synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_product (id_product),
                INDEX idx_ean13 (ean13)
            ) ENGINE=InnoDB
        ');

        // 4. catalog_stock — composite PK, references product + combination implicitly
        $this->addSql('
            CREATE TABLE IF NOT EXISTS catalog_stock (
                id_product INT UNSIGNED NOT NULL,
                id_product_attribute INT UNSIGNED NOT NULL DEFAULT 0,
                quantity INT NOT NULL DEFAULT 0,
                id_shop INT UNSIGNED NOT NULL DEFAULT 1,
                synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id_product, id_product_attribute),
                INDEX idx_product (id_product)
            ) ENGINE=InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        // Drop in reverse dependency order
        $this->addSql('DROP TABLE IF EXISTS catalog_stock');
        $this->addSql('DROP TABLE IF EXISTS catalog_combination');
        $this->addSql('DROP TABLE IF EXISTS catalog_product');
        $this->addSql('DROP TABLE IF EXISTS catalog_category');
    }
}
