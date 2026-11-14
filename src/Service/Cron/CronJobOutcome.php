<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Cron;

/** What a launched job returned: its exit code and the text it printed. */
final class CronJobOutcome
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
    ) {
    }
}
