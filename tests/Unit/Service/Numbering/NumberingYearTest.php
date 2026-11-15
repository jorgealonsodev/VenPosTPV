<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Numbering;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Numbering\NumberingYear;

final class NumberingYearTest extends TestCase
{
    private string $previousZone;

    protected function setUp(): void
    {
        $this->previousZone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousZone);
    }

    /** @return array<string, array{string, string, string, int}> */
    public static function instants(): array
    {
        return [
            'UTC server, 00:30 in Madrid on 1 January' => ['UTC', '2026-12-31 23:30:00', 'UTC', 2027],
            'UTC server, 23:30 in Madrid on 31 December' => ['UTC', '2026-12-31 22:30:00', 'UTC', 2026],
            'New York DateTime, 00:30 in Madrid on 1 January' => ['UTC', '2026-12-31 18:30:00', 'America/New_York', 2027],
            'New York DateTime, 23:30 in Madrid on 31 December' => ['UTC', '2026-12-31 17:30:00', 'America/New_York', 2026],
            'Madrid server, 23:59 on 31 December' => ['Europe/Madrid', '2026-12-31 23:59:00', 'Europe/Madrid', 2026],
            'Madrid server, 00:01 on 1 January' => ['Europe/Madrid', '2027-01-01 00:01:00', 'Europe/Madrid', 2027],
            'Madrid server, a date without zone' => ['Europe/Madrid', '2026-07-15 12:00:00', '', 2026],
        ];
    }

    #[DataProvider('instants')]
    public function testTheYearIsTheOneInSpainAtThatMoment(string $defaultZone, string $time, string $zone, int $year): void
    {
        date_default_timezone_set($defaultZone);
        $moment = $zone === '' ? new \DateTimeImmutable($time) : new \DateTimeImmutable($time, new \DateTimeZone($zone));

        $this->assertSame($year, NumberingYear::of($moment));
        $this->assertSame($time, $moment->format('Y-m-d H:i:s'), 'the date passed in is not changed');
    }
}
