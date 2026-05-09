<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Catalog\CatalogSyncService;
use VenPosTPV\Service\Sync\StockPushService;
use VenPosTPV\Service\Sync\SyncStatusService;

#[AsController]
class SyncStatusController
{
    public function __construct(
        private readonly SyncStatusService $syncStatusService,
        private readonly CatalogSyncService $catalogSyncService,
        private readonly StockPushService $stockPushService,
    ) {
    }

    #[Route('/sync/status', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $status = $this->syncStatusService->getStatus();

        return new JsonResponse([
            'state' => $status->state,
            'ps_online' => $status->psOnline,
            'latency_ms' => $status->latencyMs,
            'last_syncs' => $status->lastSyncs,
            'freshness' => $status->freshness,
            'pending_ops' => $status->pendingOps,
            'queued_messages' => $status->queuedMessages,
        ]);
    }

    #[Route('/sync/stock', methods: ['POST'])]
    public function syncStock(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $counts = $this->catalogSyncService->syncStock();

        return new JsonResponse(['data' => ['queued' => true, 'counts' => $counts]], Response::HTTP_OK);
    }

    #[Route('/sync/now', methods: ['POST'])]
    public function syncNow(): JsonResponse
    {
        $counts = $this->catalogSyncService->syncAll();

        return new JsonResponse([
            'data' => ['queued' => true, 'counts' => $counts],
        ]);
    }

    #[Route('/sync/push-stock', methods: ['POST'])]
    public function pushStock(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $pushed = $this->stockPushService->pushPending();

        return new JsonResponse(['data' => ['pushed' => $pushed]]);
    }
}
