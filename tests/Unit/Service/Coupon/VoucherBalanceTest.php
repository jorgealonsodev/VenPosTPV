<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Coupon;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\Coupon\VoucherBalance;
use VenPosTPV\Service\PrestaShop\CouponStatusLookup;

/**
 * «Validar y aplicar» with an amount voucher shows its balance, what this sale
 * uses and what is left in a new voucher when the sale is charged.
 */
final class VoucherBalanceTest extends TestCase
{
    /** @param list<string> $urls */
    private function balance(string|\Throwable $answer, array &$urls = []): VoucherBalance
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($answer, &$urls): MockResponse {
            $urls[] = urldecode($url);
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return new MockResponse($answer);
        });

        return new VoucherBalance(new CouponStatusLookup('https://shop.example.com', 'KEY', $http));
    }

    private static function rule(array $over = []): string
    {
        return (string) json_encode(['cart_rules' => [$over + [
            'id' => 6, 'quantity' => '1', 'active' => '1', 'date_from' => '2026-11-10 12:31:19', 'date_to' => '2027-11-10 23:59:59',
            'reduction_amount' => '64.000000', 'reduction_percent' => '0.00', 'reduction_tax' => '1', 'partial_use' => '1',
        ]]]);
    }

    public function testAPartialUseAmountVoucherShowsBalanceUsedAndRest(): void
    {
        $urls = [];

        self::assertSame(['balance' => 64.0, 'used' => 36.0, 'left' => 28.0], $this->balance(self::rule(), $urls)->forSale('9VZ4WTAX-2', 36.0));
        self::assertStringContainsString('filter[code]=[9VZ4WTAX-2]', $urls[0]);
        self::assertStringContainsString('reduction_amount', $urls[0]);
    }

    public function testAVoucherUsedWholeLeavesNothing(): void
    {
        self::assertSame(['balance' => 64.0, 'used' => 64.0, 'left' => 0.0], $this->balance(self::rule())->forSale('9VZ4WTAX-2', 64.0));
    }

    public function testWithoutAnAmountBalanceThereIsNothingToShow(): void
    {
        self::assertNull($this->balance(self::rule(['reduction_amount' => '0.000000', 'reduction_percent' => '10.00']))->forSale('X', 3.0), 'a percentage coupon');
        self::assertNull($this->balance(self::rule(['reduction_tax' => '0']))->forSale('X', 3.0), 'an amount without VAT is not what the till shows');
        self::assertNull($this->balance(self::rule(['partial_use' => '0']))->forSale('X', 3.0), 'no rest is kept');
        self::assertNull($this->balance('[]')->forSale('X', 3.0));
        self::assertNull($this->balance(new \RuntimeException('down'))->forSale('X', 3.0));
    }
}
