<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Label settings of each till (docs/prd/agente-impresion.md L5, L6):
 * `label_height_mm` 25-60 (30 by default) and `label_cut_each` (cut after every
 * label instead of once per strip; off by default). Literal values only, and every
 * step guarded through information_schema so it can run again.
 */
final class Version00000038LabelSettings extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add label_height_mm (default 30) and label_cut_each (default 0) to terminal';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        if (!$this->hasColumn('label_height_mm')) {
            $this->addSql('ALTER TABLE terminal ADD COLUMN label_height_mm TINYINT UNSIGNED NOT NULL DEFAULT 30');
        }
        if (!$this->hasColumn('label_cut_each')) {
            $this->addSql('ALTER TABLE terminal ADD COLUMN label_cut_each TINYINT(1) NOT NULL DEFAULT 0');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        if ($this->hasColumn('label_cut_each')) {
            $this->addSql('ALTER TABLE terminal DROP COLUMN label_cut_each');
        }
        if ($this->hasColumn('label_height_mm')) {
            $this->addSql('ALTER TABLE terminal DROP COLUMN label_height_mm');
        }
    }

    private function hasColumn(string $column): bool
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'terminal' AND COLUMN_NAME = ?",
            [$column]
        ) > 0;
    }
}
