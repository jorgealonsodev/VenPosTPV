<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Holds what the fiscal journal logs during one run of the every-minute job until
 * the run knows whether it is reporting an incident. Under cron the default logger
 * writes errors to STDERR and the hosting panel mails STDERR on every run, so a
 * lasting condition (a folder that cannot be written) must not print one line a
 * minute: on the run that reports the incident the messages go out at their own
 * level, on the others they go out as debug (kept for a verbose run, never mailed).
 */
final class IncidentLogBuffer extends AbstractLogger
{
    /** @var list<array{string, string|\Stringable, array<string, mixed>}> */
    private array $records = [];

    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    /** @param array<string, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $level, $message, $context];
    }

    /** Sends what was held: at its own level when $report, else as debug. */
    public function release(bool $report): void
    {
        $records = $this->records;
        $this->records = [];
        foreach ($records as [$level, $message, $context]) {
            $this->logger?->log($report ? $level : LogLevel::DEBUG, $message, $context);
        }
    }
}
