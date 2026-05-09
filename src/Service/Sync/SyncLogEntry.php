<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Sync;

use Doctrine\DBAL\Connection;

final class SyncLogEntry
{
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $direction,
        public readonly \DateTimeImmutable $syncedAt,
        public readonly ?int $rowCount,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }
}
