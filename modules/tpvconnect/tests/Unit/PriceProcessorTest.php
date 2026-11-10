<?php

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use PHPUnit\Framework\TestCase;
use PriceGateway;
use PriceProcessor;
use RefundRequestException;

final class FakePriceGateway implements PriceGateway
{
    /** @var array<string, bool> "product:attribute" => exists */
    public array $items = ['10:0' => true, '10:55' => true, '11:0' => true];
    /** @var array<int, float> product => tax-excluded price */
    public array $netPrices = [10 => 100.0, 11 => 5.0];
    /** @var array<string, int> rate => tax rules group */
    public array $groups = ['21' => 1, '10' => 2, '4' => 3];
    /** @var array<int, array{int, ?float, int}> */
    public array $products = [];
    /** @var array<int, array{int, float}> */
    public array $combinations = [];

    public function itemExists(int $productId, int $attributeId): bool
    {
        return $this->items[$productId . ':' . $attributeId] ?? false;
    }

    public function productNetPrice(int $productId): float
    {
        return $this->netPrices[$productId];
    }

    public function findTaxRulesGroup(float $rate): ?int
    {
        return $this->groups[(string) (int) $rate] ?? null;
    }

    public function saveProduct(int $productId, ?float $netPrice, int $taxRulesGroup): void
    {
        $this->products[] = [$productId, $netPrice, $taxRulesGroup];
    }

    public function saveCombinationImpact(int $productId, int $attributeId, float $impact): void
    {
        $this->combinations[] = [$attributeId, $impact];
    }
}

/**
 * POST /module/tpvconnect/price: the TPV's «Modo edición» sets the price with VAT
 * of a product or a combination and the product's VAT rate; PrestaShop stores the
 * product price without VAT, its tax rules group, and each combination's impact.
 */
final class PriceProcessorTest extends TestCase
{
    private FakePriceGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new FakePriceGateway();
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function process(array $data): array
    {
        return (new PriceProcessor($this->gateway))->process($data);
    }

    public function test_a_product_price_with_vat_is_stored_without_vat_with_its_tax_group(): void
    {
        $result = $this->process(['id_product' => 10, 'id_product_attribute' => 0, 'price' => 270.0, 'vat_rate' => 21]);

        $this->assertSame([[10, 223.140496, 1]], $this->gateway->products);
        $this->assertSame([], $this->gateway->combinations);
        $this->assertSame(['id_product' => 10, 'id_product_attribute' => 0, 'price' => 270.0, 'price_net' => 223.140496, 'vat_rate' => 21.0], $result);
    }

    public function test_a_combination_price_becomes_its_impact_over_the_product_and_sets_the_product_vat(): void
    {
        // The product costs 100 without VAT; the size at 133,10 with 21 % is 110 without: +10.
        $result = $this->process(['id_product' => 10, 'id_product_attribute' => 55, 'price' => 133.1, 'vat_rate' => 21]);

        $this->assertSame([[10, null, 1]], $this->gateway->products, 'the VAT rate is the product\'s');
        $this->assertSame([[55, 10.0]], $this->gateway->combinations);
        $this->assertSame(110.0, $result['price_net']);
    }

    public function test_zero_vat_needs_no_tax_group(): void
    {
        $this->process(['id_product' => 11, 'id_product_attribute' => 0, 'price' => 0.18, 'vat_rate' => 0]);

        $this->assertSame([[11, 0.18, 0]], $this->gateway->products);
    }

    public function test_a_rate_without_tax_group_in_the_shop_is_refused_and_nothing_changes(): void
    {
        unset($this->gateway->groups['4']);

        $this->expectRejection(422, fn () => $this->process(['id_product' => 10, 'id_product_attribute' => 0, 'price' => 10.4, 'vat_rate' => 4]));
        $this->assertSame([], $this->gateway->products);
    }

    public function test_an_unknown_product_or_combination_is_not_found(): void
    {
        $this->expectRejection(404, fn () => $this->process(['id_product' => 99, 'id_product_attribute' => 0, 'price' => 10, 'vat_rate' => 21]));
        $this->expectRejection(404, fn () => $this->process(['id_product' => 10, 'id_product_attribute' => 77, 'price' => 10, 'vat_rate' => 21]));
        $this->assertSame([], $this->gateway->products);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidBodies(): iterable
    {
        yield 'no product' => [['id_product_attribute' => 0, 'price' => 10, 'vat_rate' => 21]];
        yield 'negative price' => [['id_product' => 10, 'id_product_attribute' => 0, 'price' => -1, 'vat_rate' => 21]];
        yield 'price as text' => [['id_product' => 10, 'id_product_attribute' => 0, 'price' => '10', 'vat_rate' => 21]];
        yield 'too high' => [['id_product' => 10, 'id_product_attribute' => 0, 'price' => 1000000, 'vat_rate' => 21]];
        yield 'unknown rate' => [['id_product' => 10, 'id_product_attribute' => 0, 'price' => 10, 'vat_rate' => 7]];
    }

    /**
     * @dataProvider invalidBodies
     *
     * @param array<string, mixed> $body
     */
    public function test_an_invalid_body_is_422(array $body): void
    {
        $this->expectRejection(422, fn () => $this->process($body));
        $this->assertSame([], $this->gateway->products);
    }

    private function expectRejection(int $code, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected rejection ' . $code);
        } catch (RefundRequestException $e) {
            $this->assertSame($code, $e->getHttpCode());
        }
    }
}
