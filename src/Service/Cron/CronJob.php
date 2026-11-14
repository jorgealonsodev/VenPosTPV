<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Cron;

/** One job of the single scheduled task: an existing console command and when it is due. */
final class CronJob
{
    /**
     * @param array<string, mixed> $arguments the command's input (options as '--name' => value)
     * @param string|null          $dailyAt   'HH:MM' in the shop timezone; null = every run
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $command,
        public readonly array $arguments = [],
        public readonly ?string $dailyAt = null,
    ) {
    }
}
