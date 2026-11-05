<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Sale;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Sale\VatRate;

final class VatRateTest extends TestCase
{
    /** @return iterable<string, array{float, float, float}> */
    public static function knownRates(): iterable
    {
        yield '21 %' => [25.00, 20.66, 21.0];
        yield '10 %' => [11.00, 10.00, 10.0];
        yield '4 %' => [5.20, 5.00, 4.0];
        yield '0 %' => [8.00, 8.00, 0.0];
        // 0,10 × 1,04 still rounds to 0,10: equal prices mean no VAT, not 4 %.
        yield 'cheap exempt item' => [0.10, 0.10, 0.0];
        // 0.83 × 1.21 = 1.0043: the net price kept with two decimals still matches.
        yield 'cheap item at 21 %' => [1.00, 0.83, 21.0];
    }

    #[DataProvider('knownRates')]
    public function testTheGrossAndNetPricesGiveTheNearestKnownRate(float $gross, float $net, float $rate): void
    {
        $this->assertSame(['rate' => $rate, 'approximate' => false], VatRate::fromPrices($gross, $net));
    }

    public function testAPriceThatMatchesNoKnownRateKeepsTheComputedRateAsApproximate(): void
    {
        $this->assertSame(['rate' => 15.0, 'approximate' => true], VatRate::fromPrices(11.50, 10.00));
    }

    public function testAnUnknownNetPriceAssumesTwentyOnePercentAsApproximate(): void
    {
        $this->assertSame(['rate' => 21.0, 'approximate' => true], VatRate::fromPrices(12.10, null));
        $this->assertSame(['rate' => 21.0, 'approximate' => true], VatRate::fromPrices(12.10, 0.0));
        $this->assertSame(['rate' => 21.0, 'approximate' => true], VatRate::fromPrices(0.0, 0.0));
    }

    public function testTheBaseAndVatAddUpToThePaidAmountExactly(): void
    {
        $this->assertSame(['base' => 41.32, 'vat' => 8.68], VatRate::split(50.00, 21.0));
        $this->assertSame(['base' => 29.75, 'vat' => 6.25], VatRate::split(36.00, 21.0));
        $this->assertSame(['base' => 8.26, 'vat' => 1.74], VatRate::split(10.00, 21.0));
        $this->assertSame(['base' => 8.00, 'vat' => 0.0], VatRate::split(8.00, 0.0));
        $this->assertSame(['base' => 0.0, 'vat' => 0.0], VatRate::split(0.0, 21.0));
    }

    public function testTheSummaryRoundsPerRateHighestFirst(): void
    {
        // The sales report adds the line bases (41,32 + 8,26 + 29,75 + 8,26 = 87,59);
        // a ticket or invoice rounds once per rate.
        $this->assertSame([
            ['rate' => 21.0, 'base' => 87.6, 'vat' => 18.4],
            ['rate' => 10.0, 'base' => 10.0, 'vat' => 1.0],
        ], VatRate::summary(['10.00' => 11.00, '21.00' => 106.00]));
        $this->assertSame([], VatRate::summary([]));
    }
}
