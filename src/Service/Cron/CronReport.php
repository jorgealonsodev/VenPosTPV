<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Cron;

/** What one run of the scheduled task did (job ids). */
final class CronReport
{
    /**
     * @param list<string> $launched
     * @param list<string> $failures
     * @param list<string> $skipped  still running from an earlier run (or another nightly job is)
     * @param array<string, string> $errors the error text of each failed job, by id
     */
    public function __construct(
        private readonly array $launched,
        private readonly array $failures,
        private readonly array $skipped,
        private readonly array $errors = [],
    ) {
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
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
