<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Platforms\MySQLPlatform;

final class Version00000001CreateRoleTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create all 10 TPV tables in dependency order';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        // 1. role
        $this->addSql('
            CREATE TABLE IF NOT EXISTS role (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(32) NOT NULL UNIQUE,
                permissions JSON NOT NULL
            ) ENGINE=InnoDB
        ');

        // 2. terminal
        $this->addSql('
            CREATE TABLE IF NOT EXISTS terminal (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ps_shop_id INT UNSIGNED NOT NULL,
                name VARCHAR(64) NOT NULL,
                printer_config JSON,
                scanner_config JSON,
                hotkeys JSON,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB
        ');

        // 3. user
        $this->addSql('
            CREATE TABLE IF NOT EXISTS user (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ps_employee_id INT UNSIGNED NULL,
                role_id INT UNSIGNED NOT NULL,
                name VARCHAR(128) NOT NULL,
                pin_hash VARCHAR(255) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                last_login_at DATETIME NULL,
                FOREIGN KEY (role_id) REFERENCES role(id)
            ) ENGINE=InnoDB
        ');

        // 4. session
        $this->addSql('
            CREATE TABLE IF NOT EXISTS session (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                terminal_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                opened_at DATETIME NOT NULL,
                closed_at DATETIME NULL,
                opening_cash DECIMAL(10,2) NOT NULL,
                counted_cash DECIMAL(10,2) NULL,
                expected_cash DECIMAL(10,2) NULL,
                difference DECIMAL(10,2) NULL,
                FOREIGN KEY (terminal_id) REFERENCES terminal(id),
                FOREIGN KEY (user_id) REFERENCES user(id)
            ) ENGINE=InnoDB
        ');

        // 5. movement
        $this->addSql('
            CREATE TABLE IF NOT EXISTS movement (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                session_id INT UNSIGNED NOT NULL,
                type ENUM(\'sale\',\'refund\',\'cash_in\',\'cash_out\') NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                concept VARCHAR(255),
                ps_order_id INT UNSIGNED NULL,
                ps_order_slip_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES session(id),
                INDEX idx_ps_order (ps_order_id),
                INDEX idx_session_type (session_id, type)
            ) ENGINE=InnoDB
        ');

        // 6. order_meta
        $this->addSql('
            CREATE TABLE IF NOT EXISTS order_meta (
                ps_order_id INT UNSIGNED PRIMARY KEY,
                terminal_id INT UNSIGNED NOT NULL,
                session_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                payment_breakdown JSON NOT NULL,
                printed TINYINT(1) NOT NULL DEFAULT 0,
                synced TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB
        ');

        // 7. offline_queue
        $this->addSql('
            CREATE TABLE IF NOT EXISTS offline_queue (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                terminal_id INT UNSIGNED NOT NULL,
                payload JSON NOT NULL,
                status ENUM(\'pending\',\'processing\',\'done\',\'error\') NOT NULL DEFAULT \'pending\',
                error_message TEXT NULL,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                processed_at DATETIME NULL,
                INDEX idx_status_created (status, created_at)
            ) ENGINE=InnoDB
        ');

        // 8. audit_log
        $this->addSql('
            CREATE TABLE IF NOT EXISTS audit_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                terminal_id INT UNSIGNED NULL,
                action VARCHAR(64) NOT NULL,
                target VARCHAR(128) NULL,
                payload JSON NULL,
                ip VARCHAR(45) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_created (user_id, created_at),
                INDEX idx_action (action)
            ) ENGINE=InnoDB
        ');

        // 9. z_report
        $this->addSql('
            CREATE TABLE IF NOT EXISTS z_report (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                session_id INT UNSIGNED NOT NULL UNIQUE,
                generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                totals JSON NOT NULL,
                signature_hash CHAR(64) NOT NULL,
                pdf_path VARCHAR(255),
                FOREIGN KEY (session_id) REFERENCES session(id)
            ) ENGINE=InnoDB
        ');

        // 10. messenger_messages
        $this->addSql('
            CREATE TABLE IF NOT EXISTS messenger_messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX idx_queue (queue_name),
                INDEX idx_available (available_at)
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
        $this->addSql('DROP TABLE IF EXISTS messenger_messages');
        $this->addSql('DROP TABLE IF EXISTS z_report');
        $this->addSql('DROP TABLE IF EXISTS audit_log');
        $this->addSql('DROP TABLE IF EXISTS offline_queue');
        $this->addSql('DROP TABLE IF EXISTS order_meta');
        $this->addSql('DROP TABLE IF EXISTS movement');
        $this->addSql('DROP TABLE IF EXISTS session');
        $this->addSql('DROP TABLE IF EXISTS user');
        $this->addSql('DROP TABLE IF EXISTS terminal');
        $this->addSql('DROP TABLE IF EXISTS role');
    }
}
