<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Sale;

/**
 * The VAT rate of a sale line from its catalog prices, and its split into base and
 * VAT amount (docs/prd/informes-ventas.md, I1; factura-completa.md, F5). One rule for
 * the ticket, the reports and the full invoice.
 */
final class VatRate
{
    /** The Spanish rates the shop uses, most common first. */
    public const KNOWN = [21.0, 10.0, 4.0, 0.0];

    /** Used when the net price is unknown. */
    public const ASSUMED = 21.0;

    /**
     * A known rate matches when net × (1 + rate) rounds to the gross price within a
     * cent: the catalog net price has two decimals, so it is off by up to half a cent.
     */
    private const MATCH_TOLERANCE = 0.011;

    /**
     * @return array{rate: float, approximate: bool}
     */
    public static function fromPrices(float $gross, ?float $net): array
    {
        if ($net === null || $net <= 0.0 || $gross <= 0.0) {
            return ['rate' => self::ASSUMED, 'approximate' => true];
        }

        // Same gross and net price: no VAT, even for items so cheap that 4 % rounds away.
        if (abs($gross - $net) < 0.005) {
            return ['rate' => 0.0, 'approximate' => false];
        }

        $best = null;
        $bestDiff = INF;
        foreach (self::KNOWN as $rate) {
            $diff = abs(round($net * (1 + $rate / 100), 2) - $gross);
            if ($diff < $bestDiff) {
                $best = $rate;
                $bestDiff = $diff;
            }
        }
        if ($best !== null && $bestDiff <= self::MATCH_TOLERANCE) {
            return ['rate' => $best, 'approximate' => false];
        }

        $computed = round(($gross / $net - 1) * 100, 2);

        return ['rate' => max(0.0, min(99.99, $computed)), 'approximate' => true];
    }

    /**
     * The VAT box of a ticket or invoice: one row per rate, rounded per rate and not
     * per line (factura-completa.md, F5), highest rate first. Each row's base + VAT is
     * exactly what was paid at that rate.
     *
     * @param array<int|string, float> $paidByRate amount paid with VAT, keyed by rate
     *
     * @return list<array{rate: float, base: float, vat: float}>
     */
    public static function summary(array $paidByRate): array
    {
        $rows = [];
        foreach ($paidByRate as $rate => $paid) {
            $split = self::split(round((float) $paid, 2), (float) $rate);
            $rows[] = ['rate' => (float) $rate, 'base' => $split['base'], 'vat' => $split['vat']];
        }
        usort($rows, static fn (array $a, array $b): int => $b['rate'] <=> $a['rate']);

        return $rows;
    }

    /**
     * Base and VAT of an amount that includes VAT; they add up to it exactly.
     *
     * @return array{base: float, vat: float}
     */
    public static function split(float $amountWithVat, float $rate): array
    {
        $base = round($amountWithVat / (1 + $rate / 100), 2);

        return ['base' => $base + 0.0, 'vat' => round($amountWithVat - $base, 2) + 0.0];
    }
}
