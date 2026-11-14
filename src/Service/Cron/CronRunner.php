<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Cron;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The single scheduled task (tpv:cron, every minute): launches the jobs due now.
 * Minute jobs run every time. A nightly job runs when its last success is older
 * than the latest occurrence of its hour (so a missed hour runs at the next
 * minute); after a failure it is tried again no sooner than 30 minutes later, at
 * most 3 times a night. A job still running from an earlier run is skipped, and
 * so is a nightly job while another nightly job runs. A state that cannot be
 * saved never stops the run.
 */
class CronRunner
{
    private const RETRY_AFTER_SECONDS = 1800;
    private const MAX_ATTEMPTS_PER_NIGHT = 3;
    private const RUNNING_WITHIN_SECONDS = 300;
    private const ERROR_MAX_LENGTH = 300;
    private const NIGHTLY_LOCK = 'cron-nightly';

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
        $this->save(fn () => $this->store->recordRun($clock()));

        $launched = $failures = $skipped = $errors = [];
        foreach (self::jobs() as $job) {
            $at = $clock();
            $state = $this->store->job($job->id);
            if (!$this->isDue($job, $state, $at)) {
                continue;
            }
            // A nightly job also takes the nightly lock, so two nightly jobs never
            // run at the same time (a backup taken during the catalog write).
            $locks = $job->dailyAt !== null ? [self::NIGHTLY_LOCK, 'cron-' . $job->id] : ['cron-' . $job->id];
            try {
                $held = $this->acquireAll($locks);
            } catch (\Throwable $e) {
                $launched[] = $failures[] = $job->id;
                $errors[$job->id] = $this->record($job, $state, $at, $e->getMessage());
                continue;
            }
            if (!$held) {
                $skipped[] = $job->id;
                continue;
            }

            $launched[] = $job->id;
            try {
                $this->store->refreshConnection();
                try {
                    $outcome = $launch($job);
                    $error = $outcome->exitCode === 0 ? null : self::lastLine($outcome->output, $outcome->exitCode);
                } catch (\Throwable $e) {
                    $error = $e->getMessage() !== '' ? $e->getMessage() : $e::class;
                }
                $recorded = $this->record($job, $state, $at, $error);
                if ($recorded !== null) {
                    $failures[] = $job->id;
                    $errors[$job->id] = $recorded;
                }
            } finally {
                // Saved before the lock is freed: another process must not start
                // a nightly job that has just finished.
                foreach (array_reverse($locks) as $lockName) {
                    $this->lock->release($lockName);
                }
            }
        }

        return new CronReport($launched, $failures, $skipped, $errors);
    }

    /**
     * Takes every lock or none: false (holding nothing) when another process holds one.
     *
     * @param list<string> $names
     */
    private function acquireAll(array $names): bool
    {
        $acquired = [];
        try {
            foreach ($names as $name) {
                if (!$this->lock->acquire($name)) {
                    break;
                }
                $acquired[] = $name;
            }
        } catch (\Throwable $e) {
            foreach (array_reverse($acquired) as $name) {
                $this->lock->release($name);
            }
            throw $e;
        }
        if (\count($acquired) === \count($names)) {
            return true;
        }
        foreach (array_reverse($acquired) as $name) {
            $this->lock->release($name);
        }

        return false;
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
            'running' => self::notAfter($lastRun, $now) !== null && $now->getTimestamp() - $lastRun->getTimestamp() <= self::RUNNING_WITHIN_SECONDS,
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
        // A date later than now (a clock that was ahead) counts as not set.
        $success = self::notAfter(self::date($state['last_success_at'] ?? null), $now);
        if ($success !== null && $success >= $occurrence) {
            return false;
        }
        if (($state['failed_for'] ?? null) !== $occurrence->format(\DATE_ATOM)) {
            return true;
        }
        if ((int) ($state['failed_attempts'] ?? 0) >= self::MAX_ATTEMPTS_PER_NIGHT) {
            return false;
        }
        $attempt = self::notAfter(self::date($state['last_attempt_at'] ?? null), $now);

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

    /**
     * Saves the job's result and returns its stored error (null on success).
     *
     * @param array<string, mixed> $state
     */
    private function record(CronJob $job, array $state, \DateTimeImmutable $at, ?string $error): ?string
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
        $this->save(function () use ($job, $state): void {
            $this->store->refreshConnection();
            $this->store->saveJob($job->id, $state);
        });

        return $error === null ? null : $state['last_error'];
    }

    /** A state write that fails is lost (the stored state stays as it was); the run goes on. */
    private function save(callable $write): void
    {
        try {
            $write();
        } catch (\Throwable) {
        }
    }

    private static function notAfter(?\DateTimeImmutable $at, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return $at !== null && $at <= $now ? $at : null;
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
