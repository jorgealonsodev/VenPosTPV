<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Full invoices (docs/prd/factura-completa.md, phase 1): the customer's tax data
 * (F1, nullable columns only), the invoice with a copy of everything it shows
 * (F9), the shop's invoice series F + year (F4) and the fiscal record of an invoice
 * (F7: invoice_id, because uk_fiscal_record_order allows one record per sale and
 * the ticket keeps its F2). Every step is guarded so the migration can run again.
 *
 * The letter F is reserved for the invoices: the migration stops while a till
 * still uses it, or a ticket series F of this year exists, so tickets and invoices
 * never share numbers.
 */
final class Version00000047FullInvoice extends AbstractMigration
{
    private const CUSTOMER_COLUMNS = [
        'tax_name' => 'VARCHAR(120) NULL',
        'country' => 'CHAR(2) NULL',
        'tax_id_type' => 'CHAR(2) NULL',
        'tax_id' => 'VARCHAR(20) NULL',
        'address' => 'VARCHAR(255) NULL',
        'postcode' => 'VARCHAR(12) NULL',
        'city' => 'VARCHAR(100) NULL',
        'province' => 'VARCHAR(100) NULL',
    ];

    public function getDescription(): string
    {
        return 'Customer tax data, invoice, invoice_sequence and fiscal_record.invoice_id (full invoice)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );
        $tills = $this->connection->fetchFirstColumn("SELECT name FROM terminal WHERE ticket_letter = 'F'");
        $this->abortIf(
            $tills !== [],
            sprintf('La letra F está reservada para las facturas: cambia la letra de la caja %s en Ajustes → Cajas y vuelve a ejecutar la migración.', implode(', ', $tills))
        );
        $this->abortIf(
            $this->tableExists('ticket_sequence') && (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM ticket_sequence WHERE letter = 'F' AND year >= ? AND last_number > 0",
                [(int) (new \DateTimeImmutable())->format('Y')]
            ) > 0,
            'Este año ya se han emitido tickets con la letra F, reservada para las facturas: consulta con soporte antes de migrar.'
        );

        foreach (self::CUSTOMER_COLUMNS as $column => $definition) {
            if (!$this->hasColumn('customer', $column)) {
                $this->addSql(sprintf('ALTER TABLE customer ADD COLUMN %s %s', $column, $definition));
            }
        }

        $this->addSql('CREATE TABLE IF NOT EXISTS invoice_sequence (
            series CHAR(1) NOT NULL,
            year SMALLINT UNSIGNED NOT NULL,
            last_number INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (series, year)
        ) ENGINE=InnoDB');

        $this->addSql("CREATE TABLE IF NOT EXISTS invoice (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            number VARCHAR(20) NOT NULL,
            series CHAR(1) NOT NULL,
            year SMALLINT UNSIGNED NOT NULL,
            seq INT UNSIGNED NOT NULL,
            invoice_type CHAR(2) NOT NULL,
            sale_order_id BIGINT UNSIGNED NULL,
            refund_id BIGINT UNSIGNED NULL,
            rectified_invoice_id BIGINT UNSIGNED NULL,
            replaced_reference VARCHAR(9) NULL,
            replaced_date DATE NULL,
            customer_id INT UNSIGNED NULL,
            issuer JSON NOT NULL,
            recipient JSON NOT NULL,
            line_items JSON NOT NULL,
            tax_breakdown JSON NOT NULL,
            payments JSON NOT NULL,
            tax_total DECIMAL(10,2) NOT NULL,
            total DECIMAL(10,2) NOT NULL,
            issue_date DATE NOT NULL,
            operation_date DATE NOT NULL,
            email_consent_at DATETIME NULL,
            request_id VARCHAR(64) NULL,
            terminal_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uk_invoice_number (number),
            UNIQUE KEY uk_invoice_sale (sale_order_id),
            UNIQUE KEY uk_invoice_refund (refund_id),
            UNIQUE KEY uk_invoice_request (request_id),
            KEY idx_invoice_issue_date (issue_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        if ($this->tableExists('fiscal_record')) {
            if (!$this->hasColumn('fiscal_record', 'invoice_id')) {
                $this->addSql('ALTER TABLE fiscal_record ADD COLUMN invoice_id BIGINT UNSIGNED NULL AFTER refund_id');
            }
            if (!$this->hasIndex('fiscal_record', 'uk_fiscal_record_invoice')) {
                $this->addSql('ALTER TABLE fiscal_record ADD UNIQUE KEY uk_fiscal_record_invoice (invoice_id)');
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Invoices and their fiscal records are never removed.');
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    }

    private function hasIndex(string $table, string $index): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        ) > 0;
    }
}
