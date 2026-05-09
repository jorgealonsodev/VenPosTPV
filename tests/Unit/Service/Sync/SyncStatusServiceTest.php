<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Sync;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;
use VenPosTPV\Service\Sync\SyncLogEntry;
use VenPosTPV\Service\Sync\SyncLogRepository;
use VenPosTPV\Service\Sync\SyncStatusDTO;
use VenPosTPV\Service\Sync\SyncStatusService;

final class SyncStatusServiceTest extends TestCase
{
    private function createService(
        array $pingResult = ['online' => true, 'latency_ms' => 100],
        array $lastSyncs = [],
        int $pendingOps = 0,
        int $queuedMessages = 0,
        int $errorOps = 0,
    ): SyncStatusService {
        $apiClient = $this->createMock(PrestaShopApiClient::class);
        $apiClient->method('ping')->willReturn($pingResult);

        $syncLogRepo = $this->createMock(SyncLogRepository::class);
        $syncLogRepo->method('getLastSyncsByDirection')->willReturn($lastSyncs);

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')
            ->willReturnCallback(function (string $sql) use ($pendingOps, $queuedMessages, $errorOps) {
                if (str_contains($sql, "status='pending'")) {
                    return (string) $pendingOps;
                }
                if (str_contains($sql, 'delivered_at IS NULL')) {
                    return (string) $queuedMessages;
                }
                if (str_contains($sql, "status='error'")) {
                    return (string) $errorOps;
                }
                return '0';
            });

        return new SyncStatusService($apiClient, $syncLogRepo, $connection);
    }

    public function testGetStatusReturnsAllFields(): void
    {
        $service = $this->createService(
            pingResult: ['online' => true, 'latency_ms' => 120],
            lastSyncs: [
                'catalog' => new SyncLogEntry(1, 'catalog', 'inbound', new \DateTimeImmutable('-5 minutes'), 10, new \DateTimeImmutable()),
            ],
            pendingOps: 2,
            queuedMessages: 1,
        );

        $status = $service->getStatus();

        $this->assertTrue($status->psOnline);
        $this->assertSame(120, $status->latencyMs);
        $this->assertSame(2, $status->pendingOps);
        $this->assertSame(1, $status->queuedMessages);
        $this->assertArrayHasKey('catalog', $status->lastSyncs);
    }

    public function testComputeStateReturnsOfflineWhenPsUnreachable(): void
    {
        $service = $this->createService(pingResult: ['online' => false, 'latency_ms' => null]);
        $dto = $service->getStatus();

        $this->assertSame('offline', $dto->state);
    }

    public function testComputeStateReturnsErrorWhenErrorOpsExist(): void
    {
        $service = $this->createService(
            pingResult: ['online' => true, 'latency_ms' => 100],
            errorOps: 1,
        );
        $dto = $service->getStatus();

        $this->assertSame('error', $dto->state);
    }

    public function testComputeStateReturnsSyncingWhenQueuesNonZero(): void
    {
        $service = $this->createService(
            pingResult: ['online' => true, 'latency_ms' => 100],
            pendingOps: 3,
            queuedMessages: 0,
        );
        $dto = $service->getStatus();

        $this->assertSame('syncing', $dto->state);
    }

    public function testComputeStateReturnsSyncingWhenMessagesNonZero(): void
    {
        $service = $this->createService(
            pingResult: ['online' => true, 'latency_ms' => 100],
            pendingOps: 0,
            queuedMessages: 2,
        );
        $dto = $service->getStatus();

        $this->assertSame('syncing', $dto->state);
    }

    public function testComputeStateReturnsDelayedWhenFreshnessStale(): void
    {
        $service = $this->createService(
            pingResult: ['online' => true, 'latency_ms' => 100],
            lastSyncs: [
                'catalog' => new SyncLogEntry(1, 'catalog', 'inbound', new \DateTimeImmutable('-20 minutes'), 10, new \DateTimeImmutable()),
            ],
        );
        $dto = $service->getStatus();

        $this->assertSame('delayed', $dto->state);
        $this->assertFalse($dto->freshness['catalog']);
    }

    public function testComputeStateReturnsOkWhenEverythingFresh(): void
    {
        $service = $this->createService(
            pingResult: ['online' => true, 'latency_ms' => 100],
            lastSyncs: [
                'catalog' => new SyncLogEntry(1, 'catalog', 'inbound', new \DateTimeImmutable('-2 minutes'), 10, new \DateTimeImmutable()),
                'stock' => new SyncLogEntry(2, 'stock', 'inbound', new \DateTimeImmutable('-10 seconds'), 50, new \DateTimeImmutable()),
            ],
        );
        $dto = $service->getStatus();

        $this->assertSame('ok', $dto->state);
        $this->assertTrue($dto->freshness['catalog']);
        $this->assertTrue($dto->freshness['stock']);
    }

    public function testColdStartReturnsDelayed(): void
    {
        $service = $this->createService(
            pingResult: ['online' => true, 'latency_ms' => 100],
            lastSyncs: [],
        );
        $dto = $service->getStatus();

        $this->assertSame('delayed', $dto->state);
        $this->assertFalse($dto->freshness['catalog']);
        $this->assertFalse($dto->freshness['stock']);
        $this->assertFalse($dto->freshness['prices']);
    }

    public function testStockFreshnessThresholdIs30Seconds(): void
    {
        $service = $this->createService(
            pingResult: ['online' => true, 'latency_ms' => 100],
            lastSyncs: [
                'stock' => new SyncLogEntry(1, 'stock', 'inbound', new \DateTimeImmutable('-25 seconds'), 10, new \DateTimeImmutable()),
            ],
        );
        $dto = $service->getStatus();

        $this->assertTrue($dto->freshness['stock']);
    }

    public function testStockFreshnessStaleAfter30Seconds(): void
    {
        $service = $this->createService(
            pingResult: ['online' => true, 'latency_ms' => 100],
            lastSyncs: [
                'stock' => new SyncLogEntry(1, 'stock', 'inbound', new \DateTimeImmutable('-35 seconds'), 10, new \DateTimeImmutable()),
            ],
        );
        $dto = $service->getStatus();

        $this->assertFalse($dto->freshness['stock']);
    }
}
