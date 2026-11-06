<?php

declare(strict_types=1);

namespace VenPosTPV\Service\PrintAgent;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Fixed one-minute windows stored in `rate_limit` (PRD E7): pairing attempts and
 * invalid agent keys per IP. The database keeps the count, so every PHP process
 * (and every PHP-FPM worker) shares it. Old windows are removed by PrintRetention.
 */
class RateLimiter
{
    public const WINDOW_SECONDS = 60;

    private readonly ClockInterface $clock;

    public function __construct(private readonly Connection $connection, ?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new NativeClock();
    }

    /** Counts one more event for this scope and subject; returns the count in the current window. */
    public function hit(string $scope, string $subject): int
    {
        $key = $this->key($scope, $subject);
        $window = $this->windowStart();
        $this->connection->executeStatement(
            'INSERT INTO rate_limit (rate_key, window_start, count) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE count = count + 1',
            [$key, $window]
        );

        return $this->count($scope, $subject);
    }

    /** Events counted for this scope and subject in the current window. */
    public function count(string $scope, string $subject): int
    {
        $count = $this->connection->fetchOne(
            'SELECT count FROM rate_limit WHERE rate_key = ? AND window_start = ?',
            [$this->key($scope, $subject), $this->windowStart()]
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Events counted for this scope and subject in the windows of the last
     * `$seconds` (whole minutes, the current one included). PrintRetention keeps an hour.
     */
    public function countWithin(string $scope, string $subject, int $seconds): int
    {
        $windows = max(1, intdiv($seconds, self::WINDOW_SECONDS));
        $current = new \DateTimeImmutable($this->windowStart());
        $count = $this->connection->fetchOne(
            'SELECT COALESCE(SUM(count), 0) FROM rate_limit WHERE rate_key = ? AND window_start > ? AND window_start <= ?',
            [$this->key($scope, $subject), $current->modify(sprintf('-%d seconds', $windows * self::WINDOW_SECONDS))->format('Y-m-d H:i:s'), $current->format('Y-m-d H:i:s')]
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    private function key(string $scope, string $subject): string
    {
        // The subject (an IP) is hashed so the key has a fixed length.
        return $scope . ':' . hash('sha256', $subject);
    }

    private function windowStart(): string
    {
        $now = $this->clock->now()->getTimestamp();

        return date('Y-m-d H:i:s', $now - ($now % self::WINDOW_SECONDS));
    }
}
