<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ticket numbers A26000123: terminal letter, two year digits, six gapless digits.
 *
 * Each terminal gets its own letter (unique) and the counters are keyed by the
 * LETTER and year, not by terminal id, so changing a terminal's letter can never
 * hand out a reference that was already used. Existing terminals receive the
 * free letters in id order (A, B, C...); terminals beyond Z keep NULL until a
 * letter is set in Settings. Re-running only fills terminals still without one.
 */
final class Version00000030AddTicketNumbering extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add terminal.ticket_letter (unique) and ticket_sequence (letter, year)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        // information_schema instead of introspectTable(): Doctrine cannot map the ENUM columns of terminal.
        $hasColumn = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'terminal' AND COLUMN_NAME = 'ticket_letter'"
        ) > 0;
        if (!$hasColumn) {
            $this->addSql('ALTER TABLE terminal ADD COLUMN ticket_letter CHAR(1) NULL AFTER name');
        }

        $hasIndex = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'terminal' AND INDEX_NAME = 'uk_terminal_ticket_letter'"
        ) > 0;
        if (!$hasIndex) {
            $this->addSql('ALTER TABLE terminal ADD UNIQUE KEY uk_terminal_ticket_letter (ticket_letter)');
        }

        $this->addSql('
            CREATE TABLE IF NOT EXISTS ticket_sequence (
                letter CHAR(1) NOT NULL,
                year SMALLINT UNSIGNED NOT NULL,
                last_number INT UNSIGNED NOT NULL,
                PRIMARY KEY (letter, year)
            ) ENGINE=InnoDB
        ');

        $this->assignMissingLetters($hasColumn);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('DROP TABLE IF EXISTS ticket_sequence');
        $this->addSql('ALTER TABLE terminal DROP INDEX uk_terminal_ticket_letter, DROP COLUMN ticket_letter');
    }

    /**
     * The values are inlined (only A-Z and integer ids) because the test database
     * runner executes the planned SQL without parameters.
     */
    private function assignMissingLetters(bool $hasColumn): void
    {
        if ($hasColumn) {
            $ids = $this->connection->fetchFirstColumn('SELECT id FROM terminal WHERE ticket_letter IS NULL ORDER BY id');
            $used = array_map('strtoupper', $this->connection->fetchFirstColumn('SELECT ticket_letter FROM terminal WHERE ticket_letter IS NOT NULL'));
        } else {
            $ids = $this->connection->fetchFirstColumn('SELECT id FROM terminal ORDER BY id');
            $used = [];
        }

        $free = array_values(array_diff(range('A', 'Z'), $used));
        foreach ($ids as $position => $id) {
            if (!isset($free[$position])) {
                break;
            }
            $this->addSql(sprintf("UPDATE terminal SET ticket_letter = '%s' WHERE id = %d AND ticket_letter IS NULL", $free[$position], (int) $id));
        }
    }
}
