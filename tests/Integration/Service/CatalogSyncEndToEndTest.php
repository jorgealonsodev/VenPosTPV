<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\Catalog\CatalogSyncService;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;
use VenPosTPV\Service\Sync\SyncLogRepository;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * @group integration
 */
final class CatalogSyncEndToEndTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            foreach ([
                'catalog_stock',
                'catalog_combination_attribute',
                'catalog_combination',
                'catalog_product',
                'catalog_category',
                'sync_log',
            ] as $table) {
                $this->connection->executeStatement("DELETE FROM `{$table}`");
            }
            $this->connection->close();
        }

        $this->connection = null;
    }

    public function testFullSyncWithMockApiPopulatesAllTables(): void
    {
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        // Every response is supplied by MockHttpClient: this integration case never
        // contacts a live PrestaShop instance.
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
                    [
                        'id' => 10,
                        'id_product' => 1,
                        'reference' => 'LAP001-BLK',
                        'ean13' => '',
                        'price' => '0.000000',
                        'associations' => ['product_option_values' => [['id' => 10]]],
                    ],
                ],
            ])),
            new MockResponse(json_encode([
                'product_options' => [
                    ['id' => 9, 'name' => 'Colour'],
                ],
            ])),
            new MockResponse(json_encode([
                'product_option_values' => [
                    ['id' => 10, 'id_attribute_group' => 9, 'name' => 'Black'],
                ],
            ])),
            new MockResponse(json_encode([
                'stock_availables' => [
                    ['id' => 101, 'id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 5],
                    ['id' => 102, 'id_product' => 2, 'id_product_attribute' => 0, 'quantity' => 20],
                    ['id' => 103, 'id_product' => 1, 'id_product_attribute' => 10, 'quantity' => 3],
                ],
            ])),
            new MockResponse(json_encode([
                'product' => ['tpv_ttc' => '1209.987900', 'tpv_net' => '999.990000'],
            ])),
            new MockResponse(json_encode([
                'product' => ['tpv_ttc' => '36.287900', 'tpv_net' => '29.990000'],
            ])),
            new MockResponse(json_encode([
                'product' => ['tpv_ttc' => '1209.987900', 'tpv_net' => '999.990000'],
            ])),
        ];

        $httpClient = new MockHttpClient($responses);
        $apiClient = new PrestaShopApiClient('http://localhost/prestashop/api', 'TEST_KEY', $httpClient);
        $syncService = new CatalogSyncService(
            $apiClient,
            $this->connection,
            new SyncLogRepository($this->connection),
        );

        $result = $syncService->syncAll();

        $this->assertSame(2, $result['categories']);
        $this->assertSame(2, $result['products']);
        $this->assertSame(1, $result['combinations']);
        $this->assertSame(1, $result['combination_attributes']);
        $this->assertSame(3, $result['stock']);
        $this->assertSame(3, $result['ttc_prices']);

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_category'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_combination'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_combination_attribute'));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_stock'));
        self::assertSame('1209.987900', (string) $this->connection->fetchOne('SELECT price_ttc FROM catalog_product WHERE id = 1'));
        self::assertSame('999.990000', (string) $this->connection->fetchOne('SELECT price_net FROM catalog_product WHERE id = 1'));
    }
}
