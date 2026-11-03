<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Local customers (PRD C1, C2): the till's copy of the PrestaShop customers, synced
 * like the catalog (tpv:customers:sync) and searched without PrestaShop. A customer
 * created at the till has ps_customer_id NULL until its outbox row (outbox_id) is
 * uploaded. outbox.depends_on makes a sale wait for the upload of its customer.
 *
 * Literal values only, and every step guarded through information_schema, so it can
 * run again on a database that already has part of it.
 */
final class Version00000034LocalCustomers extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create customer (local copy + till-created customers) and outbox.depends_on';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $customer = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer'"
        ) > 0;
        if (!$customer) {
            $this->addSql('
                CREATE TABLE customer (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    ps_customer_id INT UNSIGNED NULL,
                    firstname VARCHAR(255) NOT NULL,
                    lastname VARCHAR(255) NOT NULL,
                    email VARCHAR(255) NULL,
                    phone VARCHAR(32) NULL,
                    active TINYINT(1) NOT NULL DEFAULT 1,
                    outbox_id BIGINT UNSIGNED NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    synced_at DATETIME NULL,
                    UNIQUE KEY uk_customer_ps (ps_customer_id),
                    KEY idx_customer_email (email),
                    KEY idx_customer_name (lastname, firstname)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
        }

        $dependsOn = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'outbox' AND COLUMN_NAME = 'depends_on'"
        ) > 0;
        if (!$dependsOn) {
            $this->addSql('ALTER TABLE outbox ADD COLUMN depends_on BIGINT UNSIGNED NULL AFTER sequence');
        }

        $index = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'outbox' AND INDEX_NAME = 'idx_outbox_depends_on'"
        ) > 0;
        if (!$index) {
            $this->addSql('ALTER TABLE outbox ADD KEY idx_outbox_depends_on (depends_on)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('ALTER TABLE outbox DROP KEY idx_outbox_depends_on');
        $this->addSql('ALTER TABLE outbox DROP COLUMN depends_on');
        $this->addSql('DROP TABLE IF EXISTS customer');
    }
}
