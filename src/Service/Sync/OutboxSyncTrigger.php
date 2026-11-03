<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Sync;

/**
 * Request-scoped flag: an operation of this request queued an upload, so one
 * attempt runs once the response has been sent (PRD S1, "intento inmediato tras
 * cada operación"). The minute task uploads whatever this attempt leaves behind.
 */
class OutboxSyncTrigger
{
    private bool $requested = false;

    public function request(): void
    {
        $this->requested = true;
    }

    public function isRequested(): bool
    {
        return $this->requested;
    }

    /** Clears the flag and says whether it was set, so the attempt runs once. */
    public function consume(): bool
    {
        $requested = $this->requested;
        $this->requested = false;

        return $requested;
    }
}
