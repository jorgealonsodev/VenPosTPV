<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Controller\Api;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Controller\Api\SyncStatusController;
use VenPosTPV\Service\Catalog\CatalogSyncService;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;
use VenPosTPV\Service\Sync\StockPushService;
use VenPosTPV\Service\Sync\SyncLogRepository;
use VenPosTPV\Service\Sync\SyncStatusService;

/**
 * @group integration
 */
class SyncStatusIntegrationTest extends TestCase
{
    private ?\Doctrine\DBAL\Connection $connection = null;

    protected function setUp(): void
    {
        $config = [
            'driver' => 'pdo_mysql',
            'host' => getenv('TPV_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('TPV_DB_PORT') ?: 3306),
            'dbname' => getenv('TPV_DB_NAME') ?: 'venpostpv',
            'user' => getenv('TPV_DB_USER') ?: 'root',
            'password' => getenv('TPV_DB_PASSWORD') ?: '',
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

    private function ensureSyncLogTable(): void
    {
        if ($this->connection === null) {
            return;
        }

        $this->connection->executeStatement('
            CREATE TABLE IF NOT EXISTS sync_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type ENUM(\'catalog\', \'stock\', \'prices\') NOT NULL,
                direction ENUM(\'inbound\', \'outbound\') NOT NULL,
                synced_at DATETIME NOT NULL,
                row_count INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_type_direction (type, direction),
                INDEX idx_type_direction (type, direction),
                INDEX idx_synced_at (synced_at)
            ) ENGINE=InnoDB
        ');
    }

    private function clearSyncLog(): void
    {
        if ($this->connection === null) {
            return;
        }
        $this->connection->executeStatement('DELETE FROM sync_log');
    }

    private function createController(array $pingResult = ['online' => true, 'latency_ms' => 100]): SyncStatusController
    {
        $apiClient = $this->createMock(PrestaShopApiClient::class);
        $apiClient->method('ping')->willReturn($pingResult);

        $syncLogRepo = new SyncLogRepository($this->connection);
        $syncStatusService = new SyncStatusService($apiClient, $syncLogRepo, $this->connection);

        return new SyncStatusController(
            $syncStatusService,
            $this->createMock(CatalogSyncService::class),
            $this->createMock(StockPushService::class),
        );
    }

    public function testResponseShapeMatchesDtoSchema(): void
    {
        if ($this->connection === null || !$this->connection->getDatabasePlatform() instanceof MySQLPlatform) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        $this->ensureSyncLogTable();
        $this->clearSyncLog();

        $controller = $this->createController();
        $response = $controller->index();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('state', $data);
        $this->assertArrayHasKey('ps_online', $data);
        $this->assertArrayHasKey('latency_ms', $data);
        $this->assertArrayHasKey('last_syncs', $data);
        $this->assertArrayHasKey('freshness', $data);
        $this->assertArrayHasKey('pending_ops', $data);
        $this->assertArrayHasKey('queued_messages', $data);

        $this->assertIsString($data['state']);
        $this->assertIsBool($data['ps_online']);
        $this->assertIsInt($data['pending_ops']);
        $this->assertIsInt($data['queued_messages']);
        $this->assertIsArray($data['last_syncs']);
        $this->assertIsArray($data['freshness']);

        // Cold start: no sync_log rows → all types stale, state delayed
        $this->assertSame('delayed', $data['state']);
        $this->assertFalse($data['freshness']['catalog']);
        $this->assertFalse($data['freshness']['stock']);
        $this->assertFalse($data['freshness']['prices']);
    }

    public function testSyncStatusWithFreshDataReturnsOk(): void
    {
        if ($this->connection === null || !$this->connection->getDatabasePlatform() instanceof MySQLPlatform) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        $this->ensureSyncLogTable();
        $this->clearSyncLog();

        $now = new \DateTimeImmutable();
        $this->connection->executeStatement(
            "INSERT INTO sync_log (type, direction, synced_at, row_count, created_at) VALUES (?, ?, ?, ?, ?)",
            ['catalog', 'inbound', $now->format('Y-m-d H:i:s'), 10, $now->format('Y-m-d H:i:s')]
        );
        $this->connection->executeStatement(
            "INSERT INTO sync_log (type, direction, synced_at, row_count, created_at) VALUES (?, ?, ?, ?, ?)",
            ['stock', 'inbound', $now->format('Y-m-d H:i:s'), 50, $now->format('Y-m-d H:i:s')]
        );

        $controller = $this->createController();
        $response = $controller->index();

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('ok', $data['state']);
        $this->assertTrue($data['freshness']['catalog']);
        $this->assertTrue($data['freshness']['stock']);
        $this->assertNotNull($data['last_syncs']['catalog']);
        $this->assertNotNull($data['last_syncs']['stock']);
    }

    public function testSyncStatusReturnsOfflineWhenPsUnreachable(): void
    {
        if ($this->connection === null || !$this->connection->getDatabasePlatform() instanceof MySQLPlatform) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        $this->ensureSyncLogTable();
        $this->clearSyncLog();

        $controller = $this->createController(['online' => false, 'latency_ms' => null]);
        $response = $controller->index();

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('offline', $data['state']);
        $this->assertFalse($data['ps_online']);
        $this->assertNull($data['latency_ms']);
    }
}
