<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Cron;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A nightly job's attempts tonight, kept in a local file next to the locks
 * (<directory>/cron-<id>.state, JSON), so a database that refuses to save the
 * job's state cannot turn a once-a-night job into an every-minute one. The
 * attempt is written before the job is launched and its result afterwards: a
 * process killed mid-job has still counted its attempt. A missing, unreadable or
 * corrupt file means no information; a file that cannot be written is ignored.
 */
class CronAttemptMarker
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var')] private readonly string $directory,
    ) {
    }

    /**
     * What the marker knows, in the shape of the job's stored state
     * (last_attempt_at, last_success_at, failed_for, failed_attempts); empty when nothing.
     *
     * @return array<string, mixed>
     */
    public function state(string $id): array
    {
        $marker = $this->read($id);
        if ($marker === null) {
            return [];
        }
        if ($marker['succeeded'] === true) {
            return ['last_attempt_at' => $marker['last_attempt_at'], 'last_success_at' => $marker['last_attempt_at']];
        }

        // Failed, or never finished (killed): it counts as a failed attempt.
        return ['last_attempt_at' => $marker['last_attempt_at'], 'failed_for' => $marker['slot'], 'failed_attempts' => $marker['attempts']];
    }

    /** Counts an attempt of the job for the night that started at $slot, before it is launched. */
    public function begin(string $id, \DateTimeImmutable $slot, \DateTimeImmutable $at): void
    {
        $slotKey = $slot->format(\DATE_ATOM);
        $previous = $this->read($id);
        $attempts = $previous !== null && $previous['slot'] === $slotKey ? $previous['attempts'] + 1 : 1;
        $this->write($id, ['slot' => $slotKey, 'attempts' => $attempts, 'last_attempt_at' => $at->format(\DATE_ATOM), 'succeeded' => null]);
    }

    /** Saves the result of the attempt counted by begin(). */
    public function finish(string $id, bool $succeeded): void
    {
        $marker = $this->read($id);
        if ($marker === null) {
            return;
        }
        $marker['succeeded'] = $succeeded;
        $this->write($id, $marker);
    }

    /** @return array{slot: string, attempts: int, last_attempt_at: string, succeeded: ?bool}|null */
    private function read(string $id): ?array
    {
        $json = @file_get_contents($this->path($id));
        $data = \is_string($json) ? json_decode($json, true) : null;
        if (!\is_array($data)
            || !\is_string($data['slot'] ?? null) || !self::isDate($data['slot'])
            || !\is_int($data['attempts'] ?? null) || $data['attempts'] < 1
            || !\is_string($data['last_attempt_at'] ?? null) || !self::isDate($data['last_attempt_at'])
            || !(\array_key_exists('succeeded', $data) && ($data['succeeded'] === null || \is_bool($data['succeeded'])))) {
            return null;
        }

        return ['slot' => $data['slot'], 'attempts' => $data['attempts'], 'last_attempt_at' => $data['last_attempt_at'], 'succeeded' => $data['succeeded']];
    }

    /**
     * Written to a temporary file and renamed, so a reader never sees half a file.
     *
     * @param array<string, mixed> $data
     */
    private function write(string $id, array $data): void
    {
        $temporary = null;
        try {
            if (!is_dir($this->directory)) {
                @mkdir($this->directory, 0775, true);
            }
            $temporary = $this->path($id) . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($temporary, json_encode($data, \JSON_THROW_ON_ERROR)) === false || !@rename($temporary, $this->path($id))) {
                @unlink($temporary);
            }
        } catch (\Throwable) {
            if ($temporary !== null) {
                @unlink($temporary);
            }
        }
    }

    private function path(string $id): string
    {
        return $this->directory . '/cron-' . $id . '.state';
    }

    private static function isDate(string $value): bool
    {
        try {
            new \DateTimeImmutable($value);

            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
