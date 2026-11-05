<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Sale;

/**
 * Spreads an amount over weights in whole cents, the rounding remainder going to the
 * heaviest weight, so the shares always add up to the amount. Checkout uses it for
 * what each line paid; the sale lines backfill for old sales without paid_total.
 */
final class Prorate
{
    /**
     * @param array<int, float> $weights
     *
     * @return array<int, float> same keys as $weights
     */
    public static function shares(array $weights, float $total): array
    {
        $sum = array_sum($weights);
        $totalCents = (int) round($total * 100);
        $shares = [];
        $assigned = 0;
        $heaviest = $weights === [] ? null : array_keys($weights, max($weights))[0];

        foreach ($weights as $index => $weight) {
            $cents = $sum > 0 ? (int) round($totalCents * $weight / $sum) : 0;
            $shares[$index] = $cents;
            $assigned += $cents;
        }

        if ($heaviest !== null) {
            $shares[$heaviest] += $totalCents - $assigned;
        }

        foreach ($shares as $index => $cents) {
            $shares[$index] = $cents / 100;
        }

        return $shares;
    }
}
