<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * transfer_payment: a sale paid by bank transfer waits «Pendiente de pago» until an
 * admin sees the money in the bank and marks it received (docs/pago-transferencia.md).
 * One row per sale, written in the sale's transaction; only its status changes.
 *
 * terminal.bank_account: the shop's IBAN (Ajustes → Tienda), printed on the tickets
 * paid by transfer so the customer knows where to pay. Guarded so it can run again.
 */
final class Version00000052TransferPaymentAndBankAccount extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create transfer_payment and add terminal.bank_account';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('CREATE TABLE IF NOT EXISTS transfer_payment (
            id INT UNSIGNED AUTO_INCREMENT NOT NULL,
            order_id INT UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT \'pending\',
            created_at DATETIME NOT NULL,
            received_at DATETIME NULL,
            received_by_user_id INT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_transfer_payment_order (order_id),
            KEY idx_transfer_payment_status (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        if (!$this->hasColumn('terminal', 'bank_account')) {
            $this->addSql('ALTER TABLE terminal ADD COLUMN bank_account VARCHAR(34) NULL AFTER tax_id');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('DROP TABLE IF EXISTS transfer_payment');
        if ($this->hasColumn('terminal', 'bank_account')) {
            $this->addSql('ALTER TABLE terminal DROP COLUMN bank_account');
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    }
}
