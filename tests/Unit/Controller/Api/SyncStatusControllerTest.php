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

    private function requestWithUser(?int $userId): Request
    {
        $request = new Request();
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
}
