<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Catalog\CatalogSyncService;
use VenPosTPV\Service\Sync\OutboxSync;
use VenPosTPV\Service\Sync\StockPushService;
use VenPosTPV\Service\Sync\SyncStatusService;

#[AsController]
class SyncStatusController
{
    public function __construct(
        private readonly SyncStatusService $syncStatusService,
        private readonly CatalogSyncService $catalogSyncService,
        private readonly StockPushService $stockPushService,
        private readonly ?OutboxSync $outboxSync = null,
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
    public function syncNow(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Pending sales and refunds go first: they matter more than the catalog.
        $outbox = null;
        if ($this->outboxSync !== null) {
            $this->outboxSync->makePendingDue();
            $outbox = $this->outboxSync->run(50);
        }

        try {
            $counts = $this->catalogSyncService->syncAll();
            $catalogError = null;
        } catch (\Throwable $e) {
            if ($this->outboxSync === null) {
                throw $e;
            }
            $counts = null;
            $catalogError = $e->getMessage();
        }

        $data = ['queued' => true, 'counts' => $counts];
        if ($this->outboxSync !== null) {
            $data['outbox'] = $outbox;
            $data['catalog_error'] = $catalogError;
        }

        return new JsonResponse(['data' => $data]);
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
