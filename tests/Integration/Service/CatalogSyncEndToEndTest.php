<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\Catalog\CatalogSyncService;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;

/**
 * @group integration
 * @requires external_ps_api
 */
class CatalogSyncEndToEndTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $config = [
            'driver' => 'pdo_mysql',
            'host' => $_ENV['TPV_DB_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['TPV_DB_PORT'] ?? 3306),
            'dbname' => $_ENV['TPV_DB_NAME'] ?? 'tpv',
            'user' => $_ENV['TPV_DB_USER'] ?? 'tpv_user',
            'password' => $_ENV['TPV_DB_PASSWORD'] ?? '',
            'charset' => 'utf8mb4',
        ];

        try {
            $conn = DriverManager::getConnection($config);
            $conn->executeQuery('SELECT 1');
            $this->connection = $conn;
        } catch (\Throwable) {
            $this->connection = null;
        }
    }

    public function testFullSyncWithMockApiPopulatesAllTables(): void
    {
        if ($this->connection === null || !$this->connection->getDatabasePlatform() instanceof MySQLPlatform) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        // Ensure catalog tables exist
        $migration = new \VenPosTPV\Migrations\Version00000003CreateCatalogTables($this->connection);
        $schema = new \Doctrine\DBAL\Schema\Schema();
        try {
            $migration->up($schema);
        } catch (\Throwable) {
            // Tables may already exist
        }

        // Seed mock API responses
        $responses = [
            new MockResponse(json_encode([
                'categories' => [
                    ['id' => 1, 'name' => 'Root', 'id_parent' => 0, 'active' => 1],
                    ['id' => 2, 'name' => 'Electronics', 'id_parent' => 1, 'active' => 1],
                ],
            ])),
            new MockResponse(json_encode([
                'products' => [
                    ['id' => 1, 'name' => 'Laptop', 'reference' => 'LAP001', 'ean13' => '1234567890123', 'price' => '999.990000', 'id_category_default' => 2, 'active' => 1],
                    ['id' => 2, 'name' => 'Mouse', 'reference' => 'MOU001', 'ean13' => '', 'price' => '29.990000', 'id_category_default' => 2, 'active' => 1],
                ],
            ])),
            new MockResponse(json_encode([
                'combinations' => [
                    ['id' => 10, 'id_product' => 1, 'reference' => 'LAP001-BLK', 'ean13' => '', 'price' => '0.000000'],
                ],
            ])),
            new MockResponse(json_encode([
                'stock_availables' => [
                    ['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 5, 'id_shop' => 1],
                    ['id_product' => 2, 'id_product_attribute' => 0, 'quantity' => 20, 'id_shop' => 1],
                    ['id_product' => 1, 'id_product_attribute' => 10, 'quantity' => 3, 'id_shop' => 1],
                ],
            ])),
        ];

        $httpClient = new MockHttpClient($responses);
        $apiClient = new PrestaShopApiClient('http://localhost/prestashop/api', 'TEST_KEY', $httpClient);
        $syncService = new CatalogSyncService($apiClient, $this->connection);

        $result = $syncService->syncAll();

        $this->assertSame(2, $result['categories']);
        $this->assertSame(2, $result['products']);
        $this->assertSame(1, $result['combinations']);
        $this->assertSame(3, $result['stock']);

        // Verify data in DB
        $catCount = (int) $this->connection->executeQuery('SELECT COUNT(*) FROM catalog_category')->fetchOne();
        $this->assertSame(2, $catCount);

        $prodCount = (int) $this->connection->executeQuery('SELECT COUNT(*) FROM catalog_product')->fetchOne();
        $this->assertSame(2, $prodCount);

        $combCount = (int) $this->connection->executeQuery('SELECT COUNT(*) FROM catalog_combination')->fetchOne();
        $this->assertSame(1, $combCount);

        $stockCount = (int) $this->connection->executeQuery('SELECT COUNT(*) FROM catalog_stock')->fetchOne();
        $this->assertSame(3, $stockCount);
    }
}
