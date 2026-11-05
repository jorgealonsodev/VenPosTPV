<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use VenPosTPV\Service\Fiscal\FiscalRecordTriggers;

/**
 * VERI*FACTU-style fiscal records: one row per issued ticket and credit note,
 * chained by the AEAT fingerprint of the previous row (fiscal_chain holds the
 * head, locked while a record is added). The rows are append-only; the triggers
 * that make MySQL refuse UPDATE and DELETE are created after the tables, and a
 * host that does not allow triggers keeps the tables without them.
 */
final class Version00000042FiscalRecord extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create fiscal_record (chained, append-only) and fiscal_chain';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql("CREATE TABLE IF NOT EXISTS fiscal_record (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            record_type VARCHAR(10) NOT NULL DEFAULT 'alta',
            invoice_type CHAR(2) NOT NULL,
            issuer_nif VARCHAR(16) NOT NULL,
            number VARCHAR(60) NOT NULL,
            issue_date CHAR(10) NOT NULL,
            tax_total CHAR(16) NOT NULL,
            total CHAR(16) NOT NULL,
            previous_hash CHAR(64) NULL,
            hash CHAR(64) NOT NULL,
            generated_at CHAR(25) NOT NULL,
            rectified_number VARCHAR(60) NULL,
            order_id INT UNSIGNED NULL,
            refund_id INT UNSIGNED NULL,
            terminal_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uk_fiscal_record_order (order_id),
            UNIQUE KEY uk_fiscal_record_refund (refund_id),
            KEY idx_fiscal_record_number (number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addSql('CREATE TABLE IF NOT EXISTS fiscal_chain (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            last_record_id BIGINT UNSIGNED NULL,
            last_hash CHAR(64) NULL
        ) ENGINE=InnoDB');

        $this->addSql('INSERT IGNORE INTO fiscal_chain (id, last_record_id, last_hash) VALUES (1, NULL, NULL)');
    }

    public function postUp(Schema $schema): void
    {
        if (!FiscalRecordTriggers::create($this->connection)) {
            $this->write('fiscal_record: this database user cannot create triggers; the records stay append-only through the application only.');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Fiscal records are never removed.');
    }
}
