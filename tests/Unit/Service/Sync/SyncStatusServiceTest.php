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
                'catalog' => new SyncLogEntry(1, 'catalog', 'inbound', new \DateTimeImmutable('-30 hours'), 10, new \DateTimeImmutable()),
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

    /**
     * Stock is refreshed every minute (cron and the POS timer), so it is fresh for
     * three minutes; catalog and prices are the nightly full sync, fresh for 26 h.
     *
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function freshnessCases(): iterable
    {
        yield 'stock 2 min' => ['stock', '-120 seconds', true];
        yield 'stock 4 min' => ['stock', '-240 seconds', false];
        yield 'catalog 20 h' => ['catalog', '-20 hours', true];
        yield 'catalog 27 h' => ['catalog', '-27 hours', false];
        yield 'prices 20 h' => ['prices', '-20 hours', true];
        yield 'prices 27 h' => ['prices', '-27 hours', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('freshnessCases')]
    public function testFreshnessFollowsHowOftenEachKindIsSynced(string $type, string $age, bool $fresh): void
    {
        $service = $this->createService(
            pingResult: ['online' => true, 'latency_ms' => 100],
            lastSyncs: [$type => new SyncLogEntry(1, $type, 'inbound', new \DateTimeImmutable($age), 10, new \DateTimeImmutable())],
        );

        $this->assertSame($fresh, $service->getStatus()->freshness[$type]);
    }

    public function testANightlyCatalogAndAMinuteOldStockAreOk(): void
    {
        $service = $this->createService(
            pingResult: ['online' => true, 'latency_ms' => 100],
            lastSyncs: [
                'catalog' => new SyncLogEntry(1, 'catalog', 'inbound', new \DateTimeImmutable('-8 hours'), 900, new \DateTimeImmutable()),
                'prices' => new SyncLogEntry(2, 'prices', 'inbound', new \DateTimeImmutable('-8 hours'), 900, new \DateTimeImmutable()),
                'stock' => new SyncLogEntry(3, 'stock', 'inbound', new \DateTimeImmutable('-50 seconds'), 900, new \DateTimeImmutable()),
            ],
        );

        $this->assertSame('ok', $service->getStatus()->state);
    }
}
