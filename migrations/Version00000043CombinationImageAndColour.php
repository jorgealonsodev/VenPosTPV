<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The photo of each size or colour (catalog_combination.image_url) and the colour
 * of a colour value (catalog_combination_attribute.color, #RRGGBB), so the variants
 * window can show them. Filled by the next full catalog sync.
 */
final class Version00000043CombinationImageAndColour extends AbstractMigration
{
    private const COLUMNS = [
        'catalog_combination' => ['image_url', 'ADD COLUMN image_url VARCHAR(512) NULL'],
        'catalog_combination_attribute' => ['color', 'ADD COLUMN color VARCHAR(16) NULL'],
    ];

    public function getDescription(): string
    {
        return 'Add catalog_combination.image_url and catalog_combination_attribute.color';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        foreach (self::COLUMNS as $table => [$column, $definition]) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            ) > 0;
            if (!$exists) {
                $this->addSql('ALTER TABLE ' . $table . ' ' . $definition);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_combination_attribute DROP COLUMN color');
        $this->addSql('ALTER TABLE catalog_combination DROP COLUMN image_url');
    }
}
