<?php

declare(strict_types=1);

namespace VenPosTPV\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Product image URLs used to point at the shop webservice with ?ws_key=, so the
 * PrestaShop API key reached every till browser. The catalog sync now stores the
 * public thumbnail (img/p/1/2/3/123-home_default.jpg); this rewrites the URLs
 * already stored so the key is gone before the next full sync. Only rows that
 * still carry ws_key change, so it can run again.
 */
final class Version00000039PublicProductImageUrls extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace webservice image URLs (with the API key) by public thumbnails in catalog_product';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, image_url FROM catalog_product WHERE image_url LIKE '%ws_key=%'"
        );
        foreach ($rows as $row) {
            $public = null;
            if (preg_match('#^(.*)/api/images/products/\d+/(\d+)(?:\?|$)#', (string) $row['image_url'], $m) === 1) {
                $public = $m[1] . '/img/p/' . implode('/', str_split($m[2])) . '/' . $m[2] . '-home_default.jpg';
            }
            // A URL that does not match is dropped rather than kept with the key.
            $this->addSql('UPDATE catalog_product SET image_url = ? WHERE id = ?', [$public, (int) $row['id']]);
        }
    }

    public function down(Schema $schema): void
    {
        // Deliberately empty: the key must not be written back.
    }
}
