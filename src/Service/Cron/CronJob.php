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

    /** The command and its options as typed, e.g. «tpv:catalog:sync --full». */
    public function commandLine(): string
    {
        $parts = [$this->command];
        foreach ($this->arguments as $name => $value) {
            if ($value === true) {
                $parts[] = (string) $name;
            } elseif ($value !== false && $value !== null) {
                $parts[] = $name . '=' . (\is_scalar($value) ? (string) $value : json_encode($value));
            }
        }

        return implode(' ', $parts);
    }
}
