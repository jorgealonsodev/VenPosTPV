<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\Catalog\CatalogSyncService;
use VenPosTPV\Service\Catalog\ProductSearchService;
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

    /**
     * Each size or colour can have its own photo in PrestaShop, and a colour value
     * its colour: both reach the product sheet the variants window reads.
     */
    public function testCombinationPhotosAndColoursReachTheProductSheet(): void
    {
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        $price = static fn (): MockResponse => new MockResponse(json_encode(['product' => ['tpv_ttc' => '50.000000', 'tpv_net' => '41.322314']]));
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode(['categories' => [['id' => 1, 'name' => 'Root', 'id_parent' => 0, 'active' => 1]]])),
            new MockResponse(json_encode(['products' => [
                ['id' => 1, 'name' => 'Aletas', 'reference' => 'ALE', 'ean13' => '', 'price' => '41.322314', 'id_category_default' => 1, 'active' => 1, 'id_default_image' => 50],
            ]])),
            new MockResponse(json_encode(['combinations' => [
                // Its own photo.
                ['id' => 10, 'id_product' => 1, 'reference' => 'ALE-NEG', 'ean13' => '', 'price' => '0', 'associations' => [
                    'product_option_values' => [['id' => 100]],
                    'images' => [['id' => 55], ['id' => 56]],
                ]],
                // A colour without a photo.
                ['id' => 11, 'id_product' => 1, 'reference' => 'ALE-ROJ', 'ean13' => '', 'price' => '0', 'associations' => [
                    'product_option_values' => [['id' => 101]],
                ]],
                // A size: neither photo nor colour.
                ['id' => 12, 'id_product' => 1, 'reference' => 'ALE-42', 'ean13' => '', 'price' => '0', 'associations' => [
                    'product_option_values' => [['id' => 200]],
                ]],
            ]])),
            new MockResponse(json_encode(['product_options' => [['id' => 9, 'name' => 'Color'], ['id' => 8, 'name' => 'Talla']]])),
            new MockResponse(json_encode(['product_option_values' => [
                ['id' => 100, 'id_attribute_group' => 9, 'name' => 'Negro', 'color' => '#000000'],
                ['id' => 101, 'id_attribute_group' => 9, 'name' => 'Rojo', 'color' => '#E84C3D'],
                ['id' => 200, 'id_attribute_group' => 8, 'name' => '42/43', 'color' => ''],
            ]])),
            new MockResponse(json_encode(['stock_availables' => []])),
            $price(), $price(), $price(), $price(),
        ]);
        $syncService = new CatalogSyncService(
            new PrestaShopApiClient('http://localhost/prestashop/api', 'TEST_KEY', $httpClient),
            $this->connection,
            new SyncLogRepository($this->connection),
            'https://shop.example.com',
        );

        $syncService->syncAll();
        $sheet = (new ProductSearchService($this->connection))->findById(1);

        self::assertNotNull($sheet);
        $combinations = [];
        foreach ($sheet['combinations'] as $combination) {
            $combinations[$combination['id']] = $combination;
        }
        self::assertSame('https://shop.example.com/img/p/5/0/50-home_default.jpg', $sheet['image_url']);
        self::assertSame('https://shop.example.com/img/p/5/5/55-home_default.jpg', $combinations[10]['image_url'], 'its first photo');
        self::assertNull($combinations[11]['image_url']);
        self::assertNull($combinations[12]['image_url']);
        self::assertSame([['group_name' => 'Color', 'attribute_name' => 'Negro', 'color' => '#000000']], $combinations[10]['attributes']);
        self::assertSame([['group_name' => 'Color', 'attribute_name' => 'Rojo', 'color' => '#E84C3D']], $combinations[11]['attributes']);
        self::assertSame([['group_name' => 'Talla', 'attribute_name' => '42/43', 'color' => null]], $combinations[12]['attributes']);
    }
}
