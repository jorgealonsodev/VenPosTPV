<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Cron;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The single scheduled task (tpv:cron, every minute): launches the jobs due now.
 * Minute jobs run every time. A nightly job runs when its last success is older
 * than the latest occurrence of its hour (so a missed hour runs at the next
 * minute); after a failure it is tried again no sooner than 30 minutes later, at
 * most 3 times a night. A job still running from an earlier run is skipped.
 */
class CronRunner
{
    private const RETRY_AFTER_SECONDS = 1800;
    private const MAX_ATTEMPTS_PER_NIGHT = 3;
    private const RUNNING_WITHIN_SECONDS = 300;
    private const ERROR_MAX_LENGTH = 300;

    private readonly \DateTimeZone $timezone;

    public function __construct(
        private readonly CronStateStore $store,
        private readonly CronLock $lock,
        #[Autowire('%env(PS_SHOP_TIMEZONE)%')] string $timezone = 'Europe/Madrid',
    ) {
        try {
            $this->timezone = new \DateTimeZone($timezone);
        } catch (\Exception) {
            $this->timezone = new \DateTimeZone('Europe/Madrid');
        }
    }

    /** @return list<CronJob> */
    public static function jobs(): array
    {
        return [
            new CronJob('outbox', 'Subida de ventas', 'tpv:sync:outbox'),
            new CronJob('stock', 'Stock', 'tpv:catalog:sync', ['--stock-only' => true]),
            new CronJob('catalog', 'Catálogo completo', 'tpv:catalog:sync', ['--full' => true], '03:00'),
            new CronJob('customers', 'Clientes', 'tpv:customers:sync', [], '03:30'),
            new CronJob('backup', 'Copia de seguridad', 'tpv:backup:database', [], '04:00'),
        ];
    }

    /** @param callable(CronJob): CronJobOutcome $launch runs the job's command */
    public function run(callable $launch, ?\DateTimeImmutable $now = null): CronReport
    {
        $clock = fn (): \DateTimeImmutable => ($now ?? new \DateTimeImmutable())->setTimezone($this->timezone);
        $this->store->recordRun($clock());

        $launched = $failures = $skipped = [];
        foreach (self::jobs() as $job) {
            $at = $clock();
            $state = $this->store->job($job->id);
            if (!$this->isDue($job, $state, $at)) {
                continue;
            }
            $lockName = 'cron-' . $job->id;
            try {
                if (!$this->lock->acquire($lockName)) {
                    $skipped[] = $job->id;
                    continue;
                }
            } catch (\Throwable $e) {
                $launched[] = $failures[] = $job->id;
                $this->record($job, $state, $at, $e->getMessage());
                continue;
            }

            $launched[] = $job->id;
            try {
                $outcome = $launch($job);
                $error = $outcome->exitCode === 0 ? null : self::lastLine($outcome->output, $outcome->exitCode);
            } catch (\Throwable $e) {
                $error = $e->getMessage() !== '' ? $e->getMessage() : $e::class;
            } finally {
                $this->lock->release($lockName);
            }
            if ($error !== null) {
                $failures[] = $job->id;
            }
            $this->record($job, $state, $at, $error);
        }

        return new CronReport($launched, $failures, $skipped);
    }

    /**
     * @return array{last_run_at: ?string, running: bool, jobs: list<array{id: string, label: string, daily_at: ?string, last_attempt_at: ?string, last_success_at: ?string, last_error: ?string}>}
     */
    public function status(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $lastRun = $this->store->lastRun();
        $jobs = [];
        foreach (self::jobs() as $job) {
            $state = $this->store->job($job->id);
            $jobs[] = [
                'id' => $job->id,
                'label' => $job->label,
                'daily_at' => $job->dailyAt,
                'last_attempt_at' => $this->format(self::date($state['last_attempt_at'] ?? null)),
                'last_success_at' => $this->format(self::date($state['last_success_at'] ?? null)),
                'last_error' => \is_string($state['last_error'] ?? null) ? $state['last_error'] : null,
            ];
        }

        return [
            'last_run_at' => $this->format($lastRun),
            'running' => $lastRun !== null && $now->getTimestamp() - $lastRun->getTimestamp() <= self::RUNNING_WITHIN_SECONDS,
            'jobs' => $jobs,
        ];
    }

    /** @param array<string, mixed> $state */
    private function isDue(CronJob $job, array $state, \DateTimeImmutable $now): bool
    {
        if ($job->dailyAt === null) {
            return true;
        }
        $occurrence = $this->latestOccurrence($job->dailyAt, $now);
        $success = self::date($state['last_success_at'] ?? null);
        if ($success !== null && $success >= $occurrence) {
            return false;
        }
        if (($state['failed_for'] ?? null) !== $occurrence->format(\DATE_ATOM)) {
            return true;
        }
        if ((int) ($state['failed_attempts'] ?? 0) >= self::MAX_ATTEMPTS_PER_NIGHT) {
            return false;
        }
        $attempt = self::date($state['last_attempt_at'] ?? null);

        return $attempt === null || $now->getTimestamp() - $attempt->getTimestamp() >= self::RETRY_AFTER_SECONDS;
    }

    /** The most recent moment (at or before $now) the clock showed $dailyAt in the shop timezone. */
    private function latestOccurrence(string $dailyAt, \DateTimeImmutable $now): \DateTimeImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $dailyAt));
        $local = $now->setTimezone($this->timezone);
        $today = $local->setTime($hour, $minute);

        return $today > $local ? $today->modify('-1 day')->setTime($hour, $minute) : $today;
    }

    /** @param array<string, mixed> $state */
    private function record(CronJob $job, array $state, \DateTimeImmutable $at, ?string $error): void
    {
        $state['last_attempt_at'] = $at->format(\DATE_ATOM);
        if ($error === null) {
            $state['last_success_at'] = $at->format(\DATE_ATOM);
            $state['last_error'] = null;
            unset($state['failed_for'], $state['failed_attempts']);
        } else {
            $state['last_error'] = mb_substr($error, 0, self::ERROR_MAX_LENGTH);
            if ($job->dailyAt !== null) {
                $occurrence = $this->latestOccurrence($job->dailyAt, $at)->format(\DATE_ATOM);
                $sameNight = ($state['failed_for'] ?? null) === $occurrence;
                $state['failed_for'] = $occurrence;
                $state['failed_attempts'] = ($sameNight ? (int) ($state['failed_attempts'] ?? 0) : 0) + 1;
            }
        }
        $this->store->saveJob($job->id, $state);
    }

    private static function lastLine(string $output, int $exitCode): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $output) ?: []), static fn (string $l): bool => $l !== ''));

        return $lines !== [] ? $lines[\count($lines) - 1] : "Terminó con el código {$exitCode}.";
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function format(?\DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone($this->timezone)->format(\DATE_ATOM);
    }
}
