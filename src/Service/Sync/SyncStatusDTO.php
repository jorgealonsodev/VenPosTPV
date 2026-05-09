<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Sync;

final class SyncStatusDTO
{
    /**
     * @param array<string, string|null> $lastSyncs ISO-8601 strings keyed by type
     * @param array<string, bool> $freshness true = fresh, false = stale keyed by type
     */
    public function __construct(
        public readonly string $state,
        public readonly bool $psOnline,
        public readonly ?int $latencyMs,
        public readonly array $lastSyncs,
        public readonly array $freshness,
        public readonly int $pendingOps,
        public readonly int $queuedMessages,
    ) {
    }
}
