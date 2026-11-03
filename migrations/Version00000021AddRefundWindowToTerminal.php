<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version00000021AddRefundWindowToTerminal extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add refund_window_days (default 30) to terminal';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        // information_schema instead of introspectTable(): Doctrine cannot map terminal's ENUM columns.
        $hasColumn = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'terminal' AND COLUMN_NAME = 'refund_window_days'"
        ) > 0;
        if (!$hasColumn) {
            $this->addSql('ALTER TABLE terminal ADD COLUMN refund_window_days INT UNSIGNED NOT NULL DEFAULT 30 AFTER stock_push_interval_seconds');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('ALTER TABLE terminal DROP COLUMN refund_window_days');
    }
}
