<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Provisioning;

/** The state of one provisioning step, as reported by ProvisioningRunner. */
final class StepStatus
{
    public const DONE = 'done';
    /** Not done and not failed yet (never tried, or another run is doing it). */
    public const PENDING = 'pending';
    /** Not done: the last attempt failed for $reason ($blocked: the minute task no longer retries it). */
    public const FAILED = 'failed';
    /** How toArray() reports a blocked failure (Ajustes, the API). */
    public const BLOCKED = 'blocked';

    public function __construct(
        public readonly string $id,
        public readonly string $description,
        public readonly string $status,
        public readonly ?string $reason = null,
        public readonly ?\DateTimeImmutable $attemptedAt = null,
        // run() was called in this run.
        public readonly bool $attempted = false,
        // When the minute task will try a failed step again.
        public readonly ?\DateTimeImmutable $nextAttemptAt = null,
        // A blocked failure: only tpv:provision (or a new release) tries it again.
        public readonly bool $blocked = false,
        // The minute task would have retried it now: it reports the block instead.
        public readonly bool $reported = false,
    ) {
    }

    public function isDone(): bool
    {
        return $this->status === self::DONE;
    }

    /** @return array{id: string, description: string, status: string, reason: ?string, attempted_at: ?string, next_attempt_at: ?string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'status' => $this->blocked ? self::BLOCKED : $this->status,
            'reason' => $this->reason,
            'attempted_at' => $this->attemptedAt?->format(\DATE_ATOM),
            'next_attempt_at' => $this->nextAttemptAt?->format(\DATE_ATOM),
        ];
    }
}
