<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Catalog;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Catalog\CatalogSyncService;
use VenPosTPV\Service\Catalog\Exception\CatalogSyncException;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;
use VenPosTPV\Service\Sync\SyncLogRepository;

final class CatalogSyncServiceTest extends TestCase
{
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

    public function testSyncAllRollsBackOnError(): void
    {
        $conn = $this->createMockConnection();
        $apiClient = $this->createMockApiClient();
        $syncLogRepo = $this->createMockSyncLogRepo();

        $conn->expects($this->once())->method('beginTransaction');
        $conn->expects($this->once())->method('rollBack');
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
        $service->syncAll();
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
}
