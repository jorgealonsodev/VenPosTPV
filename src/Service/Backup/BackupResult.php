<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Backup;

final class BackupResult
{
    public function __construct(
        public readonly string $path,
        public readonly int $bytes,
        public readonly int $tables,
        public readonly HealthReport $health,
        // The backup is complete; only removing the oldest copies failed.
        public readonly ?string $retentionError = null,
    ) {
    }

    public function fileName(): string
    {
        return basename($this->path);
    }
}
