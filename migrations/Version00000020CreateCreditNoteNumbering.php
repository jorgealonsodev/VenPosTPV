<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Credit notes (abonos) get their own consecutive series per year, R2026-000001.
 * The counter row is incremented inside the transaction that completes a refund,
 * so a rolled-back refund never consumes a number.
 */
final class Version00000020CreateCreditNoteNumbering extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create credit_note_sequence and refund.credit_note_number';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('
            CREATE TABLE IF NOT EXISTS credit_note_sequence (
                year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
                last_number INT UNSIGNED NOT NULL
            ) ENGINE=InnoDB
        ');

        // information_schema instead of introspectTable(): Doctrine cannot map the ENUM status column.
        $hasColumn = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'refund' AND COLUMN_NAME = 'credit_note_number'"
        ) > 0;
        if (!$hasColumn) {
            $this->addSql('ALTER TABLE refund ADD COLUMN credit_note_number VARCHAR(20) NULL AFTER ps_order_slip_id, ADD UNIQUE KEY uk_refund_credit_note (credit_note_number)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('ALTER TABLE refund DROP INDEX uk_refund_credit_note, DROP COLUMN credit_note_number');
        $this->addSql('DROP TABLE IF EXISTS credit_note_sequence');
    }
}
