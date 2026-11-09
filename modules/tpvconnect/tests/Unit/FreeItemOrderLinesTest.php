<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use PHPUnit\Framework\TestCase;

final class FakeFreeItemOrderGateway implements \FreeItemOrderGateway
{
    /** @var list<array<int|string, mixed>> */
    public array $calls = [];

    public function addCartPrice(int $idCart, int $idProduct, int $idProductAttribute, float $netPrice): void
    {
        $this->calls[] = ['price', $idCart, $idProduct, $idProductAttribute, $netPrice];
    }

    public function flushPriceCache(): void
    {
        $this->calls[] = ['flush'];
    }

    public function renameOrderLine(int $idOrder, int $idProduct, int $idProductAttribute, string $name): void
    {
        $this->calls[] = ['rename', $idOrder, $idProduct, $idProductAttribute, $name];
    }

    public function deleteCartPrices(int $idCart): void
    {
        $this->calls[] = ['delete', $idCart];
    }
}

/**
 * Free item lines of POST /module/tpvconnect/orders (docs/prd/articulo-libre.md, A5.4).
 */
final class FreeItemOrderLinesTest extends TestCase
{
    private const CATALOG = [
        '21.00' => ['id_product' => 30, 'slots' => [1 => 301, 2 => 302]],
        '10.00' => ['id_product' => 31, 'slots' => [1 => 311]],
    ];

    /** @return array<string, mixed> */
    private function freeLine(array $override = []): array
    {
        return $override + ['free_item' => true, 'id_product' => 30, 'id_product_attribute' => 301, 'quantity' => 1, 'price' => 12.0, 'vat_rate' => 21.0, 'concept' => 'Arreglo de bañador', 'slot' => 1];
    }

    /** @param list<array<string, mixed>> $products */
    private function request(array $products, array $extra = []): array
    {
        return $extra + ['id_customer' => 5, 'id_currency' => 1, 'id_shop' => 1, 'payment_method' => 'TPV', 'products' => $products];
    }

    public function test_a_free_line_on_a_slot_of_its_rate_and_catalog_lines_are_valid(): void
    {
        $products = [
            $this->freeLine(),
            $this->freeLine(['id_product_attribute' => 302, 'price' => 7.35, 'quantity' => 3, 'concept' => 'Cuota']),
            $this->freeLine(['id_product' => 31, 'id_product_attribute' => 311, 'vat_rate' => 10, 'price' => 10]),
            ['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 1, 'price' => 23.9],
        ];

        self::assertSame([], \FreeItemOrderLines::validateRequest($this->request($products), self::CATALOG));
    }

    public function test_requests_without_free_lines_get_no_error_even_without_setup(): void
    {
        $products = [['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 2, 'price' => 23.9]];

        self::assertSame([], \FreeItemOrderLines::validateRequest($this->request($products), []));
        self::assertSame([], \FreeItemOrderLines::validateRequest($this->request($products, ['coupon' => []]), self::CATALOG));
    }

    public function test_a_free_line_is_refused_without_the_generic_product_of_its_rate(): void
    {
        self::assertSame(
            ['products.0.vat_rate' => 'No generic free item product for this VAT rate'],
            \FreeItemOrderLines::validateRequest($this->request([$this->freeLine()]), [])
        );
        self::assertSame(
            ['products.0.vat_rate' => 'No generic free item product for this VAT rate'],
            \FreeItemOrderLines::validateRequest($this->request([$this->freeLine(['vat_rate' => 4.0])]), self::CATALOG)
        );
    }

    public function test_a_free_line_must_use_a_slot_of_the_generic_product_of_its_rate_once(): void
    {
        $error = ['products.0.id_product_attribute' => 'Not a slot of the generic product of this VAT rate'];
        self::assertSame($error, \FreeItemOrderLines::validateRequest($this->request([$this->freeLine(['id_product_attribute' => 311])]), self::CATALOG));
        self::assertSame($error, \FreeItemOrderLines::validateRequest($this->request([$this->freeLine(['id_product' => 31])]), self::CATALOG));
        self::assertSame($error, \FreeItemOrderLines::validateRequest($this->request([$this->freeLine(['id_product' => 1, 'id_product_attribute' => 0])]), self::CATALOG));
        self::assertSame(
            ['products.1.id_product_attribute' => 'Slot already used by another line'],
            \FreeItemOrderLines::validateRequest($this->request([$this->freeLine(), $this->freeLine(['price' => 3.0])]), self::CATALOG)
        );
    }

    public function test_a_free_line_needs_a_price_and_a_valid_concept(): void
    {
        foreach ([0, 0.0, -1, '12.00', null, INF] as $price) {
            self::assertSame(['products.0.price' => 'Required price with VAT >= 0.01'], \FreeItemOrderLines::validateRequest($this->request([$this->freeLine(['price' => $price])]), self::CATALOG));
        }
        foreach (['', '   ', str_repeat('a', 101), 'a<b', 'x;y', 12, null] as $concept) {
            self::assertSame(
                ['products.0.concept' => 'Required name of 1-100 characters without < > ; = # { }'],
                \FreeItemOrderLines::validateRequest($this->request([$this->freeLine(['concept' => $concept])]), self::CATALOG),
                var_export($concept, true)
            );
        }
    }

    public function test_a_catalog_line_cannot_use_a_generic_product(): void
    {
        self::assertSame(
            ['products.0.id_product' => 'Generic free item product needs free_item'],
            \FreeItemOrderLines::validateRequest($this->request([['id_product' => 30, 'id_product_attribute' => 301, 'quantity' => 1]]), self::CATALOG)
        );
    }

    public function test_a_coupon_or_coupon_preview_with_a_free_line_is_refused(): void
    {
        $error = ['coupon' => 'A coupon cannot be used with a free item'];
        self::assertSame($error, \FreeItemOrderLines::validateRequest($this->request([$this->freeLine()], ['coupon' => ['code' => 'X']]), self::CATALOG));
        self::assertSame($error, \FreeItemOrderLines::validateRequest($this->request([$this->freeLine()], ['operation' => 'coupon_preview', 'coupon_code' => 'X']), self::CATALOG));
    }

    public function test_the_cart_gets_one_net_price_per_free_line_with_six_decimals_and_one_flush(): void
    {
        $gateway = new FakeFreeItemOrderGateway();
        $lines = new \FreeItemOrderLines($gateway, self::CATALOG);
        $lines->priceCart(9, [
            ['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 1],
            $this->freeLine(),
            $this->freeLine(['id_product_attribute' => 302, 'price' => 7.35]),
            $this->freeLine(['id_product' => 31, 'id_product_attribute' => 311, 'vat_rate' => 10.0, 'price' => 10.0]),
        ]);

        self::assertSame([
            ['price', 9, 30, 301, 9.917355],
            ['price', 9, 30, 302, 6.07438],
            ['price', 9, 31, 311, 9.090909],
            ['flush'],
        ], $gateway->calls);
    }

    public function test_an_order_without_free_lines_touches_no_price_name_or_cart(): void
    {
        $gateway = new FakeFreeItemOrderGateway();
        $lines = new \FreeItemOrderLines($gateway, self::CATALOG);
        $products = [['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 1]];
        $lines->priceCart(9, $products);
        $lines->nameOrderLines(70, $products);
        $lines->releaseCart();

        self::assertSame([], $gateway->calls);
    }

    public function test_after_the_order_each_free_line_takes_its_concept_and_the_cart_prices_go(): void
    {
        $gateway = new FakeFreeItemOrderGateway();
        $lines = new \FreeItemOrderLines($gateway, self::CATALOG);
        $products = [$this->freeLine(['concept' => '  Arreglo de bañador ']), $this->freeLine(['id_product_attribute' => 302, 'concept' => 'Cuota curso'])];
        $lines->priceCart(9, $products);
        $gateway->calls = [];

        $lines->nameOrderLines(70, $products);
        $lines->releaseCart();
        $lines->releaseCart();

        self::assertSame([
            ['rename', 70, 30, 301, 'Arreglo de bañador'],
            ['rename', 70, 30, 302, 'Cuota curso'],
            ['delete', 9],
        ], $gateway->calls);
    }

    public function test_the_order_email_shows_the_concept_only_for_the_held_free_lines(): void
    {
        \TpvFreeItemNames::hold(\FreeItemOrderLines::concepts([$this->freeLine(), ['id_product' => 1, 'id_product_attribute' => 0]]));
        $module = (new \ReflectionClass(\Tpvconnect::class))->newInstanceWithoutConstructor();
        $free = ['name' => 'Artículo libre TPV 21 %'];
        $catalog = ['name' => 'Camiseta'];
        $module->hookActionPaymentModuleProductVarTplAfter(['product_var_tpl' => &$free, 'product' => ['id_product' => 30, 'id_product_attribute' => 301]]);
        $module->hookActionPaymentModuleProductVarTplAfter(['product_var_tpl' => &$catalog, 'product' => ['id_product' => 1, 'id_product_attribute' => 0]]);
        \TpvFreeItemNames::release();
        $later = ['name' => 'Artículo libre TPV 21 %'];
        $module->hookActionPaymentModuleProductVarTplAfter(['product_var_tpl' => &$later, 'product' => ['id_product' => 30, 'id_product_attribute' => 301]]);

        self::assertSame('Arreglo de bañador', $free['name']);
        self::assertSame('Camiseta', $catalog['name']);
        self::assertSame('Artículo libre TPV 21 %', $later['name']);
    }

    public function test_the_stored_catalog_is_read_with_normalized_rate_keys(): void
    {
        \Configuration::$values[\FreeItemCatalog::CONFIG_KEY] = json_encode(['21' => ['id_product' => 30, 'slots' => ['1' => 301]], 'x' => 'bad']);
        self::assertSame(['21.00' => ['id_product' => 30, 'slots' => [1 => 301]]], \FreeItemCatalog::load());
        \Configuration::$values[\FreeItemCatalog::CONFIG_KEY] = 'not json';
        self::assertSame([], \FreeItemCatalog::load());
        unset(\Configuration::$values[\FreeItemCatalog::CONFIG_KEY]);
    }
}
