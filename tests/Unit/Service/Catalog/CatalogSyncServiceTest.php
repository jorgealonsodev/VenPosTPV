<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Catalog;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Catalog\CatalogSyncService;
use VenPosTPV\Service\Catalog\Exception\CatalogSyncException;
use VenPosTPV\Service\PrestaShop\Exception\PsApiException;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;
use VenPosTPV\Service\Sync\SyncLogRepository;

final class CatalogSyncServiceTest extends TestCase
{
    /** @var array<int, array{sql: string, params: array<int, mixed>}> */
    private array $capturedStatements = [];

    private function createMockConnection(): Connection
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('executeStatement')->willReturn(1);
        $conn->method('isTransactionActive')->willReturn(true);

        return $conn;
    }

    private function createMockApiClient(): PrestaShopApiClient
    {
        return $this->createMock(PrestaShopApiClient::class);
    }

    private function createMockSyncLogRepo(): SyncLogRepository
    {
        return $this->createMock(SyncLogRepository::class);
    }

    public function testSyncAllPopulatesAllTables(): void
    {
        $conn = $this->createMockConnection();
        $apiClient = $this->createMockApiClient();
        $syncLogRepo = $this->createMockSyncLogRepo();

        $conn->expects($this->once())->method('beginTransaction');
        $conn->expects($this->once())->method('commit');
        $conn->expects($this->never())->method('rollBack');

        $apiClient->method('getCategories')->willReturn([
            ['id' => 1, 'name' => 'Root', 'id_parent' => 0, 'active' => 1],
        ]);
        $apiClient->method('getProducts')->willReturn([
            ['id' => 1, 'name' => 'Product 1', 'reference' => 'REF001', 'ean13' => '1234567890123', 'price' => 19.99, 'id_category_default' => 1, 'active' => 1],
        ]);
        $apiClient->method('getCombinations')->willReturn([
            ['id' => 10, 'id_product' => 1, 'reference' => 'COMB001', 'ean13' => '', 'price' => 2.50],
        ]);
        $apiClient->method('getStockAvailables')->willReturn([
            ['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 15, 'id_shop' => 1],
        ]);

        $service = new CatalogSyncService($apiClient, $conn, $syncLogRepo);
        $result = $service->syncAll();

        $this->assertSame(1, $result['categories']);
        $this->assertSame(1, $result['products']);
        $this->assertSame(1, $result['combinations']);
        $this->assertSame(1, $result['stock']);
    }

    public function testSyncStockOnlyModifiesStockTable(): void
    {
        $conn = $this->createMockConnection();
        $apiClient = $this->createMockApiClient();
        $syncLogRepo = $this->createMockSyncLogRepo();

        $conn->expects($this->once())->method('beginTransaction');
        $conn->expects($this->once())->method('commit');
        $conn->expects($this->never())->method('rollBack');

        $apiClient->method('getStockAvailables')->willReturn([
            ['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 99, 'id_shop' => 1],
        ]);

        $service = new CatalogSyncService($apiClient, $conn, $syncLogRepo);
        $result = $service->syncStock();

        $this->assertSame(1, $result['stock']);
    }

    public function testSyncAllWithEmptyCatalogTruncatesTables(): void
    {
        $conn = $this->createMockConnection();
        $apiClient = $this->createMockApiClient();
        $syncLogRepo = $this->createMockSyncLogRepo();

        $apiClient->method('getCategories')->willReturn([]);
        $apiClient->method('getProducts')->willReturn([]);
        $apiClient->method('getCombinations')->willReturn([]);
        $apiClient->method('getStockAvailables')->willReturn([]);

        $service = new CatalogSyncService($apiClient, $conn, $syncLogRepo);
        $result = $service->syncAll();

        $this->assertSame(0, $result['products']);
        $this->assertSame(0, $result['combinations']);
        $this->assertSame(0, $result['stock']);
        $this->assertSame(0, $result['categories']);
    }

    public function testSyncAllLeavesTheDatabaseUntouchedWhenARemoteReadFails(): void
    {
        $conn = $this->createCapturingConnection();
        $apiClient = $this->createMockApiClient();
        $syncLogRepo = $this->createMockSyncLogRepo();

        // Remote reads now happen before the write transaction, so a failed read
        // cannot leave a half-written catalog behind: nothing is opened at all.
        $conn->expects($this->never())->method('beginTransaction');
        $conn->expects($this->never())->method('rollBack');
        $conn->expects($this->never())->method('commit');

        $apiClient->method('getCategories')->willReturn([
            ['id' => 1, 'name' => 'Root', 'id_parent' => 0, 'active' => 1],
        ]);
        $apiClient->method('getProducts')->willReturn([
            ['id' => 2, 'name' => 'Product 2', 'reference' => 'REF002', 'ean13' => '', 'price' => 10.00, 'id_category_default' => 1, 'active' => 1],
        ]);
        $apiClient->method('getCombinations')->willThrowException(new \RuntimeException('API error'));

        $service = new CatalogSyncService($apiClient, $conn, $syncLogRepo);

        $this->expectException(CatalogSyncException::class);

        try {
            $service->syncAll();
        } finally {
            $this->assertSame([], $this->capturedStatements, 'no statement may run when a remote read fails');
        }
    }

    public function testSyncAllReplacesStaleData(): void
    {
        $conn = $this->createMockConnection();
        $apiClient = $this->createMockApiClient();
        $syncLogRepo = $this->createMockSyncLogRepo();

        $apiClient->method('getCategories')->willReturn([
            ['id' => 2, 'name' => 'New Cat', 'id_parent' => 0, 'active' => 1],
        ]);
        $apiClient->method('getProducts')->willReturn([
            ['id' => 2, 'name' => 'New Product', 'reference' => 'NEW001', 'ean13' => '', 'price' => 29.99, 'id_category_default' => 2, 'active' => 1],
        ]);
        $apiClient->method('getCombinations')->willReturn([]);
        $apiClient->method('getStockAvailables')->willReturn([]);

        $service = new CatalogSyncService($apiClient, $conn, $syncLogRepo);
        $result = $service->syncAll();

        $this->assertSame(1, $result['categories']);
        $this->assertSame(1, $result['products']);
    }

    public function testSyncAllLogsEachInboundSourceWithRowCounts(): void
    {
        $conn = $this->createMockConnection();
        $apiClient = $this->createMockApiClient();
        $syncLogRepo = $this->createMockSyncLogRepo();

        $apiClient->method('getCategories')->willReturn([]);
        $apiClient->method('getProducts')->willReturn([]);
        $apiClient->method('getCombinations')->willReturn([]);
        $apiClient->method('getStockAvailables')->willReturn([]);

        $expectedUpserts = [
            ['catalog', 'inbound', 0],
            ['stock', 'inbound', 0],
            ['prices', 'inbound', 0],
        ];
        $upsertIndex = 0;
        $syncLogRepo->expects($this->exactly(3))
            ->method('upsert')
            ->willReturnCallback(function (string $source, string $direction, \DateTimeInterface $timestamp, int $rowCount) use (&$expectedUpserts, &$upsertIndex): void {
                self::assertSame($expectedUpserts[$upsertIndex][0], $source);
                self::assertSame($expectedUpserts[$upsertIndex][1], $direction);
                self::assertInstanceOf(\DateTimeImmutable::class, $timestamp);
                self::assertSame($expectedUpserts[$upsertIndex][2], $rowCount);
                ++$upsertIndex;
            });

        $service = new CatalogSyncService($apiClient, $conn, $syncLogRepo);
        $service->syncAll();

        self::assertSame(3, $upsertIndex);
    }

    public function testSyncStockLogsStockSyncTimestamp(): void
    {
        $conn = $this->createMockConnection();
        $apiClient = $this->createMockApiClient();
        $syncLogRepo = $this->createMockSyncLogRepo();

        $apiClient->method('getStockAvailables')->willReturn([
            ['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 99, 'id_shop' => 1],
        ]);

        $syncLogRepo->expects($this->once())
            ->method('upsert')
            ->with(
                'stock',
                'inbound',
                $this->isInstanceOf(\DateTimeInterface::class),
                $this->anything()
            );

        $service = new CatalogSyncService($apiClient, $conn, $syncLogRepo);
        $service->syncStock();
    }

    /**
     * Records every statement so a test can assert which column values are written.
     */
    private function createCapturingConnection(): Connection
    {
        $this->capturedStatements = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('isTransactionActive')->willReturn(true);
        $conn->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []): int {
                $this->capturedStatements[] = ['sql' => $sql, 'params' => $params];

                return 1;
            });

        return $conn;
    }

    /**
     * @return array<int, mixed>
     */
    private function capturedParamsForTable(string $table): array
    {
        $params = [];
        foreach ($this->capturedStatements as $statement) {
            if (str_contains($statement['sql'], "INSERT INTO `{$table}`")) {
                $params = array_merge($params, $statement['params']);
            }
        }

        return $params;
    }

    public function testSyncAllStoresTheTaxIncludedPriceForProductsAndCombinations(): void
    {
        $conn = $this->createCapturingConnection();
        $apiClient = $this->createMockApiClient();
        $syncLogRepo = $this->createMockSyncLogRepo();

        $apiClient->method('getProducts')->willReturn([
            ['id' => 1, 'name' => 'Product 1', 'reference' => 'REF001', 'ean13' => '', 'price' => 23.90, 'active' => 1],
        ]);
        $apiClient->method('getCombinations')->willReturn([
            ['id' => 10, 'id_product' => 1, 'reference' => 'COMB010', 'ean13' => '', 'price' => 0.0],
        ]);
        $apiClient->method('getStockAvailables')->willReturn([]);
        $apiClient->method('getAuthoritativePrices')
            ->willReturnCallback(static fn (int $productId, int $combinationId = 0): array => $combinationId > 0
                ? ['ttc' => 25.96, 'net' => 21.45]
                : ['ttc' => 23.14, 'net' => 19.12]);

        $service = new CatalogSyncService($apiClient, $conn, $syncLogRepo);
        $result = $service->syncAll();

        $productParams = $this->capturedParamsForTable('catalog_product');
        $this->assertContains(23.90, $productParams, 'the base price must keep being stored');
        $this->assertContains(23.14, $productParams, 'the authoritative tax-included price must be stored');

        $combinationParams = $this->capturedParamsForTable('catalog_combination');
        $this->assertContains(25.96, $combinationParams, 'the combination keeps its absolute tax-included price');

        $this->assertSame(2, $result['ttc_prices'], 'products and combinations with a resolved price are counted');
    }

    public function testProductImageUrlsArePublicAndNeverCarryTheApiKey(): void
    {
        // The catalog goes to every till browser: the webservice key must not.
        $conn = $this->createCapturingConnection();
        $apiClient = $this->createMockApiClient();
        $apiClient->method('getProducts')->willReturn([
            ['id' => 1, 'name' => 'Product 1', 'reference' => 'REF001', 'ean13' => '', 'price' => 10.0, 'active' => 1, 'id_default_image' => 123],
            ['id' => 2, 'name' => 'Product 2', 'reference' => 'REF002', 'ean13' => '', 'price' => 10.0, 'active' => 1, 'id_default_image' => 0],
        ]);
        $apiClient->method('getCombinations')->willReturn([]);
        $apiClient->method('getStockAvailables')->willReturn([]);

        (new CatalogSyncService($apiClient, $conn, $this->createMockSyncLogRepo(), 'https://shop.example.com/'))->syncAll();

        $params = $this->capturedParamsForTable('catalog_product');
        $this->assertContains('https://shop.example.com/img/p/1/2/3/123-home_default.jpg', $params);
        foreach ($params as $value) {
            $this->assertStringNotContainsString('ws_key', (string) $value);
        }
    }

    public function testSyncAllStoresTheTaxExcludedPriceWithReductionsApplied(): void
    {
        $conn = $this->createCapturingConnection();
        $apiClient = $this->createMockApiClient();
        $syncLogRepo = $this->createMockSyncLogRepo();

        $apiClient->method('getProducts')->willReturn([
            ['id' => 1, 'name' => 'Product 1', 'reference' => 'REF001', 'ean13' => '', 'price' => 23.90, 'active' => 1],
        ]);
        $apiClient->method('getCombinations')->willReturn([]);
        $apiClient->method('getStockAvailables')->willReturn([]);
        $apiClient->method('getAuthoritativePrices')
            ->willReturn(['ttc' => 23.14, 'net' => 19.12]);

        $service = new CatalogSyncService($apiClient, $conn, $syncLogRepo);
        $service->syncAll();

        $productParams = $this->capturedParamsForTable('catalog_product');
        $this->assertContains(23.14, $productParams, 'the tax-included price is stored');
        $this->assertContains(19.12, $productParams, 'the tax-excluded price with reductions is stored, so VAT is price_ttc - price_net');
        $this->assertContains(23.90, $productParams, 'the raw Web Service base price is still stored');
    }

    public function testSyncAllStoresNullTaxIncludedPriceAndStillCompletesWhenTheLookupFails(): void
    {
        $conn = $this->createCapturingConnection();
        $apiClient = $this->createMockApiClient();
        $syncLogRepo = $this->createMockSyncLogRepo();

        $apiClient->method('getProducts')->willReturn([
            ['id' => 1, 'name' => 'Product 1', 'reference' => 'REF001', 'ean13' => '', 'price' => 23.90, 'active' => 1],
        ]);
        $apiClient->method('getCombinations')->willReturn([]);
        $apiClient->method('getStockAvailables')->willReturn([]);
        $apiClient->method('getAuthoritativePrices')->willThrowException(new PsApiException('PS API down'));

        $service = new CatalogSyncService($apiClient, $conn, $syncLogRepo);
        $result = $service->syncAll();

        $productParams = $this->capturedParamsForTable('catalog_product');
        $this->assertContains(23.90, $productParams, 'the base price is still stored so the catalog stays usable');
        $this->assertContains(null, $productParams, 'the missing tax-included price is stored as NULL');
        $this->assertSame(1, $result['products'], 'a failed price lookup must not abort the sync');
        $this->assertSame(0, $result['ttc_prices']);
    }

    public function testSyncAllResolvesTaxIncludedPricesBeforeOpeningTheWriteTransaction(): void
    {
        $events = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('isTransactionActive')->willReturn(true);
        $conn->method('executeStatement')->willReturn(1);
        $conn->method('beginTransaction')->willReturnCallback(function () use (&$events): void {
            $events[] = 'begin';
        });
        $conn->method('commit')->willReturnCallback(function () use (&$events): void {
            $events[] = 'commit';
        });

        $apiClient = $this->createMockApiClient();
        $syncLogRepo = $this->createMockSyncLogRepo();
        $apiClient->method('getProducts')->willReturn([
            ['id' => 1, 'name' => 'Product 1', 'reference' => 'REF001', 'ean13' => '', 'price' => 23.90, 'active' => 1],
        ]);
        $apiClient->method('getCombinations')->willReturn([]);
        $apiClient->method('getStockAvailables')->willReturn([]);
        $apiClient->method('getAuthoritativePrices')->willReturnCallback(function () use (&$events): array {
            $events[] = 'ttc';

            return ['ttc' => 23.14, 'net' => 19.12];
        });

        $service = new CatalogSyncService($apiClient, $conn, $syncLogRepo);
        $service->syncAll();

        $this->assertSame(['ttc', 'begin', 'commit'], $events, 'no HTTP price request may run inside the write transaction');
    }

    /** A shop where the free item setup created 22 (21 %) and 23 (10 %, reference only). */
    private function createConnectionWithGenericProducts(): Connection
    {
        $conn = $this->createCapturingConnection();
        $conn->method('fetchOne')->willReturnCallback(static fn (string $sql, array $params = []): mixed => $params === ['free_item.ps_products']
            ? json_encode(['21.00' => ['id_product' => 22, 'slots' => ['1' => 2201, '2' => 2202]]])
            : false);

        return $conn;
    }

    /** docs/prd/articulo-libre.md, A5.2 and criterion 11: never in catalog_product nor the search. */
    public function testSyncAllLeavesTheGenericFreeItemProductsOut(): void
    {
        $conn = $this->createConnectionWithGenericProducts();
        $apiClient = $this->createMockApiClient();
        $apiClient->method('getProducts')->willReturn([
            ['id' => 1, 'name' => 'Camiseta', 'reference' => 'REF001', 'price' => 10.0, 'active' => 1],
            ['id' => 22, 'name' => 'Artículo libre TPV 21 %', 'reference' => 'renamed by someone', 'price' => 99999.0, 'active' => 1],
            ['id' => 23, 'name' => 'Artículo libre TPV 10 %', 'reference' => 'TPV-LIBRE-10', 'price' => 99999.0, 'active' => 1],
        ]);
        $apiClient->method('getCombinations')->willReturn([
            ['id' => 10, 'id_product' => 1, 'reference' => 'COMB010', 'price' => 0.0],
            ['id' => 2201, 'id_product' => 22, 'reference' => 'TPV-LIBRE-21-1', 'price' => 0.0],
            ['id' => 2301, 'id_product' => 23, 'reference' => 'TPV-LIBRE-10-1', 'price' => 0.0],
        ]);
        $apiClient->method('getStockAvailables')->willReturn([
            ['id' => 1, 'id_product' => 1, 'id_product_attribute' => 10, 'quantity' => 4],
            ['id' => 2, 'id_product' => 22, 'id_product_attribute' => 2201, 'quantity' => -3],
            ['id' => 3, 'id_product' => 23, 'id_product_attribute' => 0, 'quantity' => 0],
        ]);
        $priced = [];
        $apiClient->method('getAuthoritativePrices')->willReturnCallback(static function (int $productId, int $combinationId = 0) use (&$priced): array {
            $priced[] = $productId;

            return ['ttc' => 12.1, 'net' => 10.0];
        });

        $result = (new CatalogSyncService($apiClient, $conn, $this->createMockSyncLogRepo()))->syncAll();

        $this->assertSame(1, $result['products']);
        $this->assertSame(1, $result['combinations']);
        $this->assertSame(1, $result['stock']);
        $this->assertSame([1, 1], $priced, 'no price is asked for the generic products');
        foreach (['catalog_product', 'catalog_combination', 'catalog_stock'] as $table) {
            $params = $this->capturedParamsForTable($table);
            $this->assertNotContains(22, $params, $table);
            $this->assertNotContains(23, $params, $table);
        }
    }

    public function testSyncStockLeavesTheGenericFreeItemProductsOut(): void
    {
        $conn = $this->createConnectionWithGenericProducts();
        $apiClient = $this->createMockApiClient();
        $apiClient->method('getStockAvailables')->willReturn([
            ['id' => 1, 'id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 4],
            ['id' => 2, 'id_product' => 22, 'id_product_attribute' => 2201, 'quantity' => -3],
        ]);

        $result = (new CatalogSyncService($apiClient, $conn, $this->createMockSyncLogRepo()))->syncStock();

        $this->assertSame(1, $result['stock']);
        $this->assertNotContains(22, $this->capturedParamsForTable('catalog_stock'));
    }

    /**
     * «Modo edición»: the EAN and reference edited at the till live in
     * catalog_override, which neither the full sync nor the stock sync may empty
     * or rewrite (they replace only the synced catalog tables).
     */
    public function testNeitherSyncTouchesTheLocalOverrides(): void
    {
        $conn = $this->createCapturingConnection();
        $apiClient = $this->createMockApiClient();
        $apiClient->method('getCategories')->willReturn([['id' => 2, 'name' => 'Cat', 'id_parent' => 0, 'active' => 1]]);
        $apiClient->method('getProducts')->willReturn([['id' => 2, 'name' => 'P', 'reference' => 'R', 'ean13' => '4006381333931', 'price' => 10, 'id_category_default' => 2, 'active' => 1]]);
        $apiClient->method('getCombinations')->willReturn([]);
        $apiClient->method('getStockAvailables')->willReturn([['id' => 9, 'id_product' => 2, 'id_product_attribute' => 0, 'quantity' => 4]]);

        $service = new CatalogSyncService($apiClient, $conn, $this->createMockSyncLogRepo());
        $service->syncAll();
        $service->syncStock();

        $deleted = [];
        foreach ($this->capturedStatements as $statement) {
            $this->assertStringNotContainsString('catalog_override', $statement['sql']);
            if (preg_match('/^DELETE FROM `(\w+)`/', $statement['sql'], $m) === 1) {
                $deleted[] = $m[1];
            }
        }
        $this->assertSame(['catalog_stock', 'catalog_combination_attribute', 'catalog_combination', 'catalog_product', 'catalog_category', 'catalog_stock'], $deleted);
    }
}
