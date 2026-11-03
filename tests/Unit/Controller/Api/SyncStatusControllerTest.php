<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use VenPosTPV\Controller\Api\SyncStatusController;
use VenPosTPV\Service\Catalog\CatalogSyncService;
use VenPosTPV\Service\Sync\OutboxSync;
use VenPosTPV\Service\Sync\StockPushService;
use VenPosTPV\Service\Sync\SyncStatusDTO;
use VenPosTPV\Service\Sync\SyncStatusService;

class SyncStatusControllerTest extends TestCase
{
    private function createController(SyncStatusService $syncStatusService): SyncStatusController
    {
        return new SyncStatusController(
            $syncStatusService,
            $this->createMock(CatalogSyncService::class),
            $this->createMock(StockPushService::class),
        );
    }

    public function testReturnsSyncStatusJson(): void
    {
        $syncStatusService = $this->createMock(SyncStatusService::class);
        $syncStatusService->expects($this->once())->method('getStatus')->willReturn(new SyncStatusDTO(
            state: 'ok', psOnline: true, latencyMs: 120,
            lastSyncs: ['catalog' => '2026-05-05T12:00:00+00:00', 'stock' => null, 'prices' => null],
            freshness: ['catalog' => true, 'stock' => false, 'prices' => false], pendingOps: 2, queuedMessages: 1,
        ));

        $response = $this->createController($syncStatusService)->index();

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $data['state']);
        $this->assertTrue($data['ps_online']);
        $this->assertSame(120, $data['latency_ms']);
        $this->assertArrayHasKey('last_syncs', $data);
        $this->assertArrayHasKey('freshness', $data);
        $this->assertSame(2, $data['pending_ops']);
        $this->assertSame(1, $data['queued_messages']);
    }

    public function testReturnsOfflineStateCorrectly(): void
    {
        $syncStatusService = $this->createMock(SyncStatusService::class);
        $syncStatusService->expects($this->once())->method('getStatus')->willReturn(new SyncStatusDTO(
            state: 'offline', psOnline: false, latencyMs: null, lastSyncs: [],
            freshness: ['catalog' => false, 'stock' => false, 'prices' => false], pendingOps: 0, queuedMessages: 0,
        ));

        $response = $this->createController($syncStatusService)->index();

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('offline', $data['state']);
        $this->assertFalse($data['ps_online']);
        $this->assertNull($data['latency_ms']);
    }

    /** @param array<string, mixed>|null $body */
    private function requestWithUser(?int $userId, ?array $body = null): Request
    {
        $request = $body === null
            ? new Request()
            : new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
        $session = new Session(new MockArraySessionStorage());
        if ($userId !== null) {
            $session->set('user_id', $userId);
        }
        $request->setSession($session);

        return $request;
    }

    public function testSyncNowRejectsRequestsWithoutSession(): void
    {
        $catalog = $this->createMock(CatalogSyncService::class);
        $catalog->expects($this->never())->method('syncAll');
        $controller = new SyncStatusController(
            $this->createMock(SyncStatusService::class),
            $catalog,
            $this->createMock(StockPushService::class),
        );

        $response = $controller->syncNow($this->requestWithUser(null));

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testSyncNowRunsCatalogSyncForLoggedInUser(): void
    {
        $catalog = $this->createMock(CatalogSyncService::class);
        $catalog->expects($this->once())->method('syncAll')
            ->willReturn(['products' => 1, 'combinations' => 0, 'combination_attributes' => 0, 'stock' => 1, 'categories' => 1, 'ttc_prices' => 1]);
        $controller = new SyncStatusController(
            $this->createMock(SyncStatusService::class),
            $catalog,
            $this->createMock(StockPushService::class),
        );

        $response = $controller->syncNow($this->requestWithUser(5));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($data['data']['queued']);
        $this->assertSame(1, $data['data']['counts']['products']);
    }

    public function testManualSyncUploadsPendingOperationsNowEvenWhenTheCatalogFails(): void
    {
        $catalog = $this->createMock(CatalogSyncService::class);
        $catalog->method('syncAll')->willThrowException(new \RuntimeException('PrestaShop down'));
        $outbox = $this->createMock(OutboxSync::class);
        $outbox->expects($this->once())->method('makePendingDue')->willReturn(2);
        $outbox->expects($this->once())->method('run')->willReturn(['uploaded' => 2, 'retried' => 0, 'incidents' => 0, 'recovered' => 0]);
        $controller = new SyncStatusController(
            $this->createMock(SyncStatusService::class),
            $catalog,
            $this->createMock(StockPushService::class),
            $outbox,
        );

        $response = $controller->syncNow($this->requestWithUser(5, ['manual' => true]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $data['data']['outbox']['uploaded']);
        $this->assertSame('PrestaShop down', $data['data']['catalog_error']);
    }

    /**
     * T8 bug 2: the POS timer calls /api/sync/now every minute. It must not make every
     * pending row due, or the growing wait between retries (PRD S4) never applies.
     *
     * @return iterable<string, array{0: array<string, mixed>|null}>
     */
    public static function timerBodies(): iterable
    {
        yield 'no body' => [null];
        yield 'empty body' => [[]];
        yield 'manual false' => [['manual' => false]];
        yield 'manual not a boolean' => [['manual' => 'yes']];
    }

    /** @param array<string, mixed>|null $body */
    #[\PHPUnit\Framework\Attributes\DataProvider('timerBodies')]
    public function testTimerSyncUploadsOnlyDueRowsAndStillSyncsTheCatalog(?array $body): void
    {
        $catalog = $this->createMock(CatalogSyncService::class);
        $catalog->expects($this->once())->method('syncAll')
            ->willReturn(['products' => 1, 'combinations' => 0, 'combination_attributes' => 0, 'stock' => 1, 'categories' => 1, 'ttc_prices' => 1]);
        $outbox = $this->createMock(OutboxSync::class);
        $outbox->expects($this->never())->method('makePendingDue');
        $outbox->expects($this->once())->method('run')->willReturn(['uploaded' => 1, 'retried' => 0, 'incidents' => 0, 'recovered' => 0]);
        $controller = new SyncStatusController($this->createMock(SyncStatusService::class), $catalog, $this->createMock(StockPushService::class), $outbox);

        $response = $controller->syncNow($this->requestWithUser(5, $body));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['data']['outbox']['uploaded']);
        $this->assertSame(1, $data['data']['counts']['products']);
        $this->assertNull($data['data']['catalog_error']);
    }

    public function testManualSyncMakesPendingRowsDueBeforeUploading(): void
    {
        $calls = [];
        $outbox = $this->createMock(OutboxSync::class);
        $outbox->expects($this->once())->method('makePendingDue')->willReturnCallback(function () use (&$calls): int {
            $calls[] = 'makePendingDue';

            return 3;
        });
        $outbox->expects($this->once())->method('run')->willReturnCallback(function () use (&$calls): array {
            $calls[] = 'run';

            return ['uploaded' => 3, 'retried' => 0, 'incidents' => 0, 'recovered' => 0];
        });
        $controller = new SyncStatusController($this->createMock(SyncStatusService::class), $this->createMock(CatalogSyncService::class), $this->createMock(StockPushService::class), $outbox);

        $controller->syncNow($this->requestWithUser(5, ['manual' => true]));

        $this->assertSame(['makePendingDue', 'run'], $calls);
    }
}
