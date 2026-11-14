<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Cron;

/** What one run of the scheduled task did (job ids). */
final class CronReport
{
    /**
     * @param list<string> $launched
     * @param list<string> $failures
     * @param list<string> $skipped  still running from an earlier run
     */
    public function __construct(
        private readonly array $launched,
        private readonly array $failures,
        private readonly array $skipped,
    ) {
    }

    /** @return list<string> */
    public function launched(): array
    {
        return $this->launched;
    }

    /** @return list<string> */
    public function failures(): array
    {
        return $this->failures;
    }

    /** @return list<string> */
    public function skipped(): array
    {
        return $this->skipped;
    }

    public function failed(): bool
    {
        return $this->failures !== [];
    }
}
