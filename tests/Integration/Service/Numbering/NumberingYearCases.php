<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Numbering;

use Doctrine\DBAL\Connection;

/**
 * The New Year instants of the numbering-year rule (S11, O4): the year of a fiscal
 * number is the year in Spain's time (FiscalRecorder::TIME_ZONE) at the moment of
 * issue, whatever the PHP default zone or the zone of the DateTime passed in.
 *
 * Also saves and restores the PHP default zone and the global counter rows
 * (credit notes, invoices) a test touches, so the shared test database is left as found.
 */
trait NumberingYearCases
{
    private string $previousZone;

    /** @var list<array{table: string, where: string, key: list<int|string>, last: ?int}> */
    private array $savedCounters = [];

    /**
     * [PHP default zone, instant, zone the instant is written in, expected year].
     *
     * @return array<string, array{string, string, string, int}>
     */
    public static function newYearInstants(): array
    {
        return [
            // (a) 23:30 UTC on 31 December is 00:30 on 1 January in Madrid.
            'a: UTC server, 00:30 in Madrid on 1 January' => ['UTC', '2026-12-31 23:30:00', 'UTC', 2027],
            // (b) 22:30 UTC is still 23:30 on 31 December in Madrid.
            'b: UTC server, 23:30 in Madrid on 31 December' => ['UTC', '2026-12-31 22:30:00', 'UTC', 2026],
            // (c) the same instants written in another zone give the same year.
            'c: New York DateTime, 00:30 in Madrid on 1 January' => ['UTC', '2026-12-31 18:30:00', 'America/New_York', 2027],
            'c: New York DateTime, 23:30 in Madrid on 31 December' => ['UTC', '2026-12-31 17:30:00', 'America/New_York', 2026],
        ];
    }

    /**
     * The previous behaviour, which must not change: with the default zone already
     * Europe/Madrid (the test TPV) the year is the one of the local clock.
     *
     * @return array<string, array{string, string, string, int}>
     */
    public static function madridServerInstants(): array
    {
        return [
            'Madrid server, 23:59 on 31 December' => ['Europe/Madrid', '2026-12-31 23:59:00', 'Europe/Madrid', 2026],
            'Madrid server, 00:01 on 1 January' => ['Europe/Madrid', '2027-01-01 00:01:00', 'Europe/Madrid', 2027],
        ];
    }

    private function useDefaultZone(string $zone): void
    {
        $this->previousZone = date_default_timezone_get();
        date_default_timezone_set($zone);
    }

    private function restoreDefaultZone(): void
    {
        if (isset($this->previousZone)) {
            date_default_timezone_set($this->previousZone);
        }
    }

    private static function instant(string $time, string $zone): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone($zone));
    }

    /**
     * Remembers a counter row (or its absence) to put it back in tearDown.
     *
     * @param list<int|string> $key
     */
    private function saveCounter(Connection $connection, string $table, string $where, array $key): ?int
    {
        $last = $connection->fetchOne(sprintf('SELECT last_number FROM %s WHERE %s', $table, $where), $key);
        $last = $last === false ? null : (int) $last;
        $this->savedCounters[] = ['table' => $table, 'where' => $where, 'key' => $key, 'last' => $last];

        return $last;
    }

    private function restoreCounters(Connection $connection): void
    {
        foreach (array_reverse($this->savedCounters) as $saved) {
            if ($saved['last'] === null) {
                $connection->executeStatement(sprintf('DELETE FROM %s WHERE %s', $saved['table'], $saved['where']), $saved['key']);
            } else {
                $connection->executeStatement(sprintf('UPDATE %s SET last_number = ? WHERE %s', $saved['table'], $saved['where']), [$saved['last'], ...$saved['key']]);
            }
        }
        $this->savedCounters = [];
    }
}
