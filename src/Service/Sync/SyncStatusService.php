<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Sync;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;

class SyncStatusService
{
    private const FRESHNESS_THRESHOLDS = [
        'stock' => 30,
        'prices' => 300,
        'catalog' => 900,
    ];

    public function __construct(
        private readonly PrestaShopApiClient $apiClient,
        private readonly SyncLogRepository $syncLogRepo,
        private readonly Connection $connection,
    ) {
    }

    public function getStatus(): SyncStatusDTO
    {
        $ping = $this->apiClient->ping();
        $lastSyncs = $this->syncLogRepo->getLastSyncsByDirection('inbound');
        $pendingOps = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM offline_queue WHERE status='pending'");
        $queuedMessages = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE delivered_at IS NULL');
        $errorOps = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM offline_queue WHERE status='error'");

        $now = new \DateTimeImmutable();
        $lastSyncsIso = [];
        $freshness = [];

        foreach (['catalog', 'stock', 'prices'] as $type) {
            if (isset($lastSyncs[$type])) {
                $lastSyncsIso[$type] = $lastSyncs[$type]->syncedAt->format(\DateTimeInterface::ATOM);
                $diff = $now->getTimestamp() - $lastSyncs[$type]->syncedAt->getTimestamp();
                $freshness[$type] = $diff <= self::FRESHNESS_THRESHOLDS[$type];
            } else {
                $lastSyncsIso[$type] = null;
                $freshness[$type] = false;
            }
        }

        $dto = new SyncStatusDTO(
            state: 'ok',
            psOnline: $ping['online'],
            latencyMs: $ping['latency_ms'],
            lastSyncs: $lastSyncsIso,
            freshness: $freshness,
            pendingOps: $pendingOps,
            queuedMessages: $queuedMessages,
        );

        return new SyncStatusDTO(
            state: $this->computeState($dto, $errorOps),
            psOnline: $dto->psOnline,
            latencyMs: $dto->latencyMs,
            lastSyncs: $dto->lastSyncs,
            freshness: $dto->freshness,
            pendingOps: $dto->pendingOps,
            queuedMessages: $dto->queuedMessages,
        );
    }

    public function computeState(SyncStatusDTO $dto, int $errorOps): string
    {
        if (!$dto->psOnline) {
            return 'offline';
        }

        if ($errorOps > 0) {
            return 'error';
        }

        if ($dto->pendingOps > 0 || $dto->queuedMessages > 0) {
            return 'syncing';
        }

        $hasAnySync = false;
        foreach ($dto->lastSyncs as $lastSync) {
            if ($lastSync !== null) {
                $hasAnySync = true;
                break;
            }
        }

        if (!$hasAnySync) {
            return 'delayed';
        }

        foreach ($dto->freshness as $type => $isFresh) {
            if (($dto->lastSyncs[$type] ?? null) !== null && !$isFresh) {
                return 'delayed';
            }
        }

        return 'ok';
    }
}
