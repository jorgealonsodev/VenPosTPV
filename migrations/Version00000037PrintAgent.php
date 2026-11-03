<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Print agent (docs/prd/agente-impresion.md v2): the program on each till's computer
 * that fetches that till's print jobs over HTTPS and prints them on the shop's
 * printers, so the TPV can run on Plesk.
 *
 * - `terminal.printer_type` accepts `agent`.
 * - `print_job`: the queue of each till (T0-T9). Claimed with a conditional UPDATE
 *   per till in priority and id order, like the outbox; the payload (ESC/POS bytes,
 *   may carry customer data) is nulled 24 h after the job finished and the row is
 *   deleted after 30 days (S4).
 * - `print_agent`: the paired agent of a till (one per till). Only the SHA-256 of
 *   its key is stored (E3); `revoked_at` cuts it at once (E5).
 * - `agent_pairing`: 6-digit codes, stored as an HMAC only, 10 minutes, single use,
 *   void after 5 failures (E2, E7).
 * - `drawer_audit`: every drawer opening with user, till, reason and result (T11);
 *   never purged.
 * - `rate_limit`: fixed one-minute windows for pairing and invalid keys (E7).
 *
 * Nothing existing is rewritten. Literal values only, and every step guarded through
 * information_schema, so it can run again on a database that already has part of it.
 */
final class Version00000037PrintAgent extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Print agent: printer_type agent, print_job, print_agent, agent_pairing, drawer_audit, rate_limit';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $printerType = (string) $this->connection->fetchOne(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'terminal' AND COLUMN_NAME = 'printer_type'"
        );
        if ($printerType !== '' && !str_contains($printerType, "'agent'")) {
            $this->addSql("ALTER TABLE terminal MODIFY printer_type ENUM('none','pdf','escpos','system','agent') NOT NULL DEFAULT 'pdf'");
        }

        if (!$this->hasTable('print_job')) {
            $this->addSql("
                CREATE TABLE print_job (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    terminal_id INT UNSIGNED NOT NULL,
                    type ENUM('ticket','drawer','label') NOT NULL,
                    target ENUM('ticket','label') NOT NULL DEFAULT 'ticket',
                    priority TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    status ENUM('pending','claimed','done','failed','expired','cancelled') NOT NULL DEFAULT 'pending',
                    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    claim_token CHAR(32) NULL,
                    lease_until DATETIME NULL,
                    next_attempt_at DATETIME NULL,
                    expires_at DATETIME NOT NULL,
                    payload MEDIUMBLOB NULL,
                    payload_size INT UNSIGNED NOT NULL DEFAULT 0,
                    sha256 CHAR(64) NULL,
                    source VARCHAR(32) NOT NULL,
                    source_ref VARCHAR(64) NULL,
                    created_by_user_id INT UNSIGNED NULL,
                    error_code VARCHAR(64) NULL,
                    error VARCHAR(500) NULL,
                    created_at DATETIME NOT NULL,
                    claimed_at DATETIME NULL,
                    finished_at DATETIME NULL,
                    KEY idx_print_job_queue (terminal_id, status, priority, id),
                    KEY idx_print_job_created (created_at),
                    KEY idx_print_job_finished (finished_at)
                ) ENGINE=InnoDB
            ");
        }

        if (!$this->hasTable('print_agent')) {
            $this->addSql('
                CREATE TABLE print_agent (
                    terminal_id INT UNSIGNED NOT NULL,
                    key_hash CHAR(64) NOT NULL,
                    paired_at DATETIME NOT NULL,
                    paired_by_user_id INT UNSIGNED NULL,
                    last_seen_at DATETIME NULL,
                    last_ip VARCHAR(45) NULL,
                    agent_version VARCHAR(32) NULL,
                    hostname VARCHAR(255) NULL,
                    os VARCHAR(64) NULL,
                    printers JSON NULL,
                    revoked_at DATETIME NULL,
                    PRIMARY KEY (terminal_id),
                    UNIQUE KEY uk_print_agent_key (key_hash),
                    CONSTRAINT fk_print_agent_terminal FOREIGN KEY (terminal_id) REFERENCES terminal (id) ON DELETE CASCADE
                ) ENGINE=InnoDB
            ');
        }

        if (!$this->hasTable('agent_pairing')) {
            $this->addSql('
                CREATE TABLE agent_pairing (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    code_hash CHAR(64) NOT NULL,
                    terminal_id INT UNSIGNED NOT NULL,
                    created_by_user_id INT UNSIGNED NULL,
                    created_at DATETIME NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME NULL,
                    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    KEY idx_agent_pairing_code (code_hash),
                    KEY idx_agent_pairing_terminal (terminal_id),
                    CONSTRAINT fk_agent_pairing_terminal FOREIGN KEY (terminal_id) REFERENCES terminal (id) ON DELETE CASCADE
                ) ENGINE=InnoDB
            ');
        }

        if (!$this->hasTable('drawer_audit')) {
            $this->addSql('
                CREATE TABLE drawer_audit (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    terminal_id INT UNSIGNED NOT NULL,
                    user_id INT UNSIGNED NULL,
                    reason VARCHAR(32) NOT NULL,
                    order_id BIGINT NULL,
                    sale_reference VARCHAR(64) NULL,
                    job_id BIGINT UNSIGNED NULL,
                    printer_type VARCHAR(16) NOT NULL,
                    result VARCHAR(16) NOT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NULL,
                    KEY idx_drawer_audit_terminal (terminal_id, created_at),
                    KEY idx_drawer_audit_job (job_id)
                ) ENGINE=InnoDB
            ');
        }

        if (!$this->hasTable('rate_limit')) {
            $this->addSql('
                CREATE TABLE rate_limit (
                    rate_key VARCHAR(128) NOT NULL,
                    window_start DATETIME NOT NULL,
                    count INT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (rate_key, window_start),
                    KEY idx_rate_limit_window (window_start)
                ) ENGINE=InnoDB
            ');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('DROP TABLE IF EXISTS rate_limit');
        $this->addSql('DROP TABLE IF EXISTS drawer_audit');
        $this->addSql('DROP TABLE IF EXISTS agent_pairing');
        $this->addSql('DROP TABLE IF EXISTS print_agent');
        $this->addSql('DROP TABLE IF EXISTS print_job');
        $this->addSql("UPDATE terminal SET printer_type = 'pdf', printer_config = NULL WHERE printer_type = 'agent'");
        $this->addSql("ALTER TABLE terminal MODIFY printer_type ENUM('none','pdf','escpos','system') NOT NULL DEFAULT 'pdf'");
    }

    private function hasTable(string $table): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    }
}
