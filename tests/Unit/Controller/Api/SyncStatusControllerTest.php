<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\SyncStatusController;
use VenPosTPV\Service\Sync\SyncStatusDTO;
use VenPosTPV\Service\Sync\SyncStatusService;

class SyncStatusControllerTest extends TestCase
{
    public function testReturnsSyncStatusJson(): void
    {
        $syncStatusService = $this->createMock(SyncStatusService::class);
        $syncStatusService->expects($this->once())
            ->method('getStatus')
            ->willReturn(new SyncStatusDTO(
                state: 'ok',
                psOnline: true,
                latencyMs: 120,
                lastSyncs: ['catalog' => '2026-05-05T12:00:00+00:00', 'stock' => null, 'prices' => null],
                freshness: ['catalog' => true, 'stock' => false, 'prices' => false],
                pendingOps: 2,
                queuedMessages: 1,
            ));

        $messageBus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);

        $controller = new SyncStatusController($syncStatusService, $messageBus);
        $response = $controller->index();

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
        $syncStatusService->expects($this->once())
            ->method('getStatus')
            ->willReturn(new SyncStatusDTO(
                state: 'offline',
                psOnline: false,
                latencyMs: null,
                lastSyncs: [],
                freshness: ['catalog' => false, 'stock' => false, 'prices' => false],
                pendingOps: 0,
                queuedMessages: 0,
            ));

        $messageBus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);

        $controller = new SyncStatusController($syncStatusService, $messageBus);
        $response = $controller->index();

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('offline', $data['state']);
        $this->assertFalse($data['ps_online']);
        $this->assertNull($data['latency_ms']);
    }
}
