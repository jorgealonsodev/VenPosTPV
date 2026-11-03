<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tills and devices (S10): each computer is linked once to a till.
 *
 * The browser keeps a long random token (HttpOnly cookie, mirrored in localStorage);
 * the server stores only its SHA-256. One active device per till (unique
 * terminal_id). No device is linked here: "first device adopts the only till" —
 * on an install with exactly one active till, the first computer that loads the
 * POS while no device holds that till is linked to it automatically, so nobody is
 * locked out after the deploy. With two or more tills an administrator links each
 * computer on the "Este equipo no tiene caja asignada" screen.
 */
final class Version00000033TillDevices extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create terminal_device (sha256 device token -> till, one device per till)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'terminal_device'"
        ) > 0;
        if ($exists) {
            return;
        }

        $this->addSql('
            CREATE TABLE terminal_device (
                token_hash CHAR(64) NOT NULL,
                terminal_id INT UNSIGNED NOT NULL,
                linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NULL,
                linked_by_user_id INT UNSIGNED NULL,
                PRIMARY KEY (token_hash),
                UNIQUE KEY uk_terminal_device_terminal (terminal_id),
                CONSTRAINT fk_terminal_device_terminal FOREIGN KEY (terminal_id) REFERENCES terminal (id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('DROP TABLE IF EXISTS terminal_device');
    }
}
