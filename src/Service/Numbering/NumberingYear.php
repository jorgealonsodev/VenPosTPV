<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Numbering;

use VenPosTPV\Service\Fiscal\FiscalRecorder;

/**
 * The year of a fiscal number (the 26 of A26001307, R2026-000001, F26000001) and
 * of the yearly counter it takes: the year in Spanish time (FiscalRecorder::TIME_ZONE)
 * at the moment of issue, whatever the PHP default zone or the zone of the date
 * passed in. The fiscal record dates the document in the same zone, so one document
 * gets the same year in its number, its record and its journal line (S11, O4).
 *
 * Every numberer and the numbering settings ask here; nothing else derives it.
 */
final class NumberingYear
{
    public static function of(\DateTimeImmutable $moment): int
    {
        return (int) $moment->setTimezone(new \DateTimeZone(FiscalRecorder::TIME_ZONE))->format('Y');
    }
}
