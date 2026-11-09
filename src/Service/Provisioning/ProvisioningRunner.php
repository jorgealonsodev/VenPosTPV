<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Provisioning;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs the registered provisioning steps (docs/provisioning.md) in their order:
 * each pending step is run, a failure is remembered with its reason and never
 * stops the steps after it.
 *
 * The minute task runs it throttled: a step that failed is tried again only
 * RETRY_AFTER_SECONDS after its last attempt, so a shop that is down or a module
 * that is not installed yet is not called every minute. The installer and
 * tpv:provision run it without waiting.
 *
 * A step that throws ProvisioningBlockedException is blocked: the minute task no
 * longer retries it, and every RETRY_AFTER_SECONDS it only reports the block
 * (StepStatus::$reported), without running anything. tpv:provision by hand tries it
 * again; so does the minute task once a BlockableProvisioningStep's blockKey()
 * differs from the one it was blocked with (a new release).
 */
class ProvisioningRunner
{
    public const RETRY_AFTER_SECONDS = 900;

    /** @var list<ProvisioningStep> */
    private readonly array $steps;

    /** @param iterable<ProvisioningStep> $steps */
    public function __construct(
        #[AutowireIterator(ProvisioningStep::TAG)]
        iterable $steps,
        private readonly ProvisioningStateStore $state,
        private readonly int $retryAfterSeconds = self::RETRY_AFTER_SECONDS,
    ) {
        $this->steps = array_values(\is_array($steps) ? $steps : iterator_to_array($steps, false));
    }

    public function run(bool $throttled = false, ?\DateTimeImmutable $now = null): ProvisioningReport
    {
        $now ??= new \DateTimeImmutable();
        $report = [];
        foreach ($this->steps as $step) {
            $report[] = $this->runStep($step, $throttled, $now);
        }

        return new ProvisioningReport($report);
    }

    /** Checks every step and reports it with its last failure; runs nothing. */
    public function status(?\DateTimeImmutable $now = null): ProvisioningReport
    {
        $report = [];
        foreach ($this->steps as $step) {
            try {
                $done = $step->isDone();
            } catch (\Throwable $e) {
                $report[] = $this->uncheckable($step, $e);
                continue;
            }
            if ($done) {
                $report[] = new StepStatus($step->id(), $step->description(), StepStatus::DONE);
                continue;
            }
            $failure = $this->failure($step);
            if ($failure === null) {
                $report[] = new StepStatus($step->id(), $step->description(), StepStatus::PENDING);
            } elseif ($failure['blocked'] && $this->stillBlocked($step, $failure)) {
                $report[] = new StepStatus($step->id(), $step->description(), StepStatus::FAILED, $failure['reason'], $failure['attempted_at'], false, null, true);
            } else {
                // Not blocked any more (a new release): the minute task tries it at once.
                $next = $failure['blocked'] ? null : $this->nextAttempt($failure['attempted_at']);
                $report[] = new StepStatus($step->id(), $step->description(), StepStatus::FAILED, $failure['reason'], $failure['attempted_at'], false, $next);
            }
        }

        return new ProvisioningReport($report);
    }

    private function runStep(ProvisioningStep $step, bool $throttled, \DateTimeImmutable $now): StepStatus
    {
        $id = $step->id();
        try {
            $done = $step->isDone();
        } catch (\Throwable $e) {
            // Without knowing its state (the database is down…) running it is pointless.
            return $this->uncheckable($step, $e);
        }
        if ($done) {
            if ($this->failure($step) !== null) {
                $this->forget($id);
            }

            return new StepStatus($id, $step->description(), StepStatus::DONE);
        }

        $failure = $this->failure($step);
        if ($throttled && $failure !== null) {
            if ($failure['blocked']) {
                if ($this->stillBlocked($step, $failure)) {
                    return $this->blocked($step, $failure, $now);
                }
            } elseif ($now < $this->nextAttempt($failure['attempted_at'])) {
                return new StepStatus($id, $step->description(), StepStatus::FAILED, $failure['reason'], $failure['attempted_at'], false, $this->nextAttempt($failure['attempted_at']));
            }
        }

        try {
            $step->run();
            if (!$step->isDone()) {
                throw new ProvisioningException('El paso ha terminado, pero sigue pendiente.');
            }
        } catch (ProvisioningBusyException $e) {
            return new StepStatus($id, $step->description(), StepStatus::PENDING, $e->getMessage(), null, true);
        } catch (ProvisioningBlockedException $e) {
            $this->remember($id, $e->getMessage(), $now, $e->blockKey);

            return new StepStatus($id, $step->description(), StepStatus::FAILED, $e->getMessage(), $now, true, null, true);
        } catch (\Throwable $e) {
            $reason = $e instanceof ProvisioningException ? $e->getMessage() : 'Error inesperado: ' . $e->getMessage();
            $this->remember($id, $reason, $now);

            return new StepStatus($id, $step->description(), StepStatus::FAILED, $reason, $now, true, $this->nextAttempt($now));
        }
        $this->forget($id);

        return new StepStatus($id, $step->description(), StepStatus::DONE, null, $now, true);
    }

    /**
     * The minute task on a blocked step: nothing runs; once every
     * RETRY_AFTER_SECONDS, when it would have retried it, the block is reported.
     *
     * @param array{reason: string, attempted_at: \DateTimeImmutable, blocked: bool, block_key: ?string, reported_at: ?\DateTimeImmutable} $failure
     */
    private function blocked(ProvisioningStep $step, array $failure, \DateTimeImmutable $now): StepStatus
    {
        $reported = $now >= $this->nextAttempt($failure['reported_at'] ?? $failure['attempted_at']);
        if ($reported) {
            try {
                $this->state->markReported($step->id(), $now);
            } catch (\Throwable) {
            }
        }

        return new StepStatus($step->id(), $step->description(), StepStatus::FAILED, $failure['reason'], $failure['attempted_at'], false, null, true, $reported);
    }

    /** @param array{reason: string, attempted_at: \DateTimeImmutable, blocked: bool, block_key: ?string, reported_at: ?\DateTimeImmutable} $failure */
    private function stillBlocked(ProvisioningStep $step, array $failure): bool
    {
        if (!$step instanceof BlockableProvisioningStep || $failure['block_key'] === null) {
            return true;
        }
        try {
            return $step->blockKey() === $failure['block_key'];
        } catch (\Throwable) {
            // Unknown: stay blocked rather than retry something that may do harm.
            return true;
        }
    }

    private function uncheckable(ProvisioningStep $step, \Throwable $e): StepStatus
    {
        return new StepStatus($step->id(), $step->description(), StepStatus::FAILED, 'No se ha podido comprobar: ' . $e->getMessage());
    }

    private function nextAttempt(\DateTimeImmutable $attemptedAt): \DateTimeImmutable
    {
        return $attemptedAt->modify(sprintf('+%d seconds', $this->retryAfterSeconds));
    }

    /** @return array{reason: string, attempted_at: \DateTimeImmutable, blocked: bool, block_key: ?string, reported_at: ?\DateTimeImmutable}|null */
    private function failure(ProvisioningStep $step): ?array
    {
        try {
            return $this->state->failure($step->id());
        } catch (\Throwable) {
            return null;
        }
    }

    // The stored state only throttles and explains; failing to write it must not
    // turn a step into a failure nor stop the next steps.
    private function remember(string $id, string $reason, \DateTimeImmutable $now, ?string $blockKey = null): void
    {
        try {
            $this->state->recordFailure($id, $reason, $now, $blockKey);
        } catch (\Throwable) {
        }
    }

    private function forget(string $id): void
    {
        try {
            $this->state->clear($id);
        } catch (\Throwable) {
        }
    }
}
