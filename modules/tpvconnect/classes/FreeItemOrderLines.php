<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The generic products of free items (docs/prd/articulo-libre.md, A5), as
 * FreeItemProductSetup stored them: one hidden product per VAT rate, each with
 * slot combinations 1..N.
 */
final class FreeItemCatalog
{
    public const CONFIG_KEY = 'TPVCONNECT_FREE_ITEM_PRODUCTS';
    public const REFERENCE_PREFIX = 'TPV-LIBRE-';

    /**
     * @return array<string, array{id_product: int, slots: array<int, int>}> keyed by rate ("21.00")
     */
    public static function load(): array
    {
        $map = json_decode((string) Configuration::get(self::CONFIG_KEY), true);
        if (!is_array($map)) {
            return [];
        }
        $clean = [];
        foreach ($map as $rate => $entry) {
            if (!is_array($entry) || (int) ($entry['id_product'] ?? 0) < 1 || !is_array($entry['slots'] ?? null)) {
                continue;
            }
            $slots = [];
            foreach ($entry['slots'] as $slot => $idAttribute) {
                if ((int) $slot >= 1 && (int) $idAttribute >= 1) {
                    $slots[(int) $slot] = (int) $idAttribute;
                }
            }
            $clean[self::rateKey((float) $rate)] = ['id_product' => (int) $entry['id_product'], 'slots' => $slots];
        }

        return $clean;
    }

    /** @param array<string, array{id_product: int, slots: array<int, int>}> $map */
    public static function save(array $map): void
    {
        Configuration::updateValue(self::CONFIG_KEY, (string) json_encode($map));
    }

    public static function rateKey(float $rate): string
    {
        return number_format($rate, 2, '.', '');
    }

    /** @param array<string, array{id_product: int, slots: array<int, int>}> $map */
    public static function isGeneric(array $map, int $idProduct): bool
    {
        foreach ($map as $entry) {
            if ($entry['id_product'] === $idProduct) {
                return true;
            }
        }

        return false;
    }
}

/**
 * Request-scoped concepts of the free lines of the order being created, so the
 * order confirmation e-mail (actionPaymentModuleProductVarTplAfter) shows the
 * concept instead of the generic product's name. Empty outside that request.
 */
final class TpvFreeItemNames
{
    /** @var array<string, string> "id_product-id_product_attribute" => concept */
    private static array $names = [];

    /** @param array<string, string> $names */
    public static function hold(array $names): void
    {
        self::$names = $names;
    }

    public static function release(): void
    {
        self::$names = [];
    }

    public static function nameFor(int $idProduct, int $idProductAttribute): ?string
    {
        return self::$names[$idProduct . '-' . $idProductAttribute] ?? null;
    }
}

/**
 * The PrestaShop writes a free line needs, so the rules can be tested without a
 * shop. PsFreeItemOrderGateway is the production one.
 */
interface FreeItemOrderGateway
{
    /** A specific price for this cart only: net price, no reduction. */
    public function addCartPrice(int $idCart, int $idProduct, int $idProductAttribute, float $netPrice): void;

    /** Drops the request's static price caches, so the cart sees the new prices. */
    public function flushPriceCache(): void;

    public function renameOrderLine(int $idOrder, int $idProduct, int $idProductAttribute, string $name): void;

    public function deleteCartPrices(int $idCart): void;
}

/**
 * Free item lines of POST /module/tpvconnect/orders (articulo-libre.md, A5.4).
 *
 * A free line is `{free_item: true, id_product, id_product_attribute, quantity,
 * price, vat_rate, concept}`, where the product and combination are the generic
 * product of that rate and one of its slots (the TPV resolves them from the ids
 * the setup endpoint returned). Its price is the TPV's price with VAT; the cart
 * gets a specific price for that cart only with the net price (6 decimals), so
 * PrestaShop's tax rule of the generic product gives back that price with VAT.
 * After validateOrder the order line takes the concept as its name and the
 * cart's specific prices are deleted. Lines without free_item are not touched.
 */
class FreeItemOrderLines
{
    private const FORBIDDEN = '/[<>;=#{}]/u';

    private ?int $pricedCart = null;

    /** @param array<string, array{id_product: int, slots: array<int, int>}> $catalog */
    public function __construct(private readonly FreeItemOrderGateway $gateway, private readonly array $catalog)
    {
    }

    public static function isFree(mixed $product): bool
    {
        return is_array($product) && ($product['free_item'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $data the order request
     * @param array<string, array{id_product: int, slots: array<int, int>}> $catalog
     *
     * @return array<string, string> field errors, empty when valid
     */
    public static function validateRequest(array $data, array $catalog): array
    {
        $errors = [];
        $products = is_array($data['products'] ?? null) ? $data['products'] : [];
        $hasFree = false;
        $seen = [];
        foreach ($products as $index => $product) {
            $field = 'products.' . $index;
            if (!is_array($product)) {
                continue;
            }
            $idProduct = (int) ($product['id_product'] ?? 0);
            if (!self::isFree($product)) {
                if (FreeItemCatalog::isGeneric($catalog, $idProduct)) {
                    $errors[$field . '.id_product'] = 'Generic free item product needs free_item';
                }
                continue;
            }
            $hasFree = true;

            $rate = $product['vat_rate'] ?? null;
            $entry = is_int($rate) || is_float($rate) ? ($catalog[FreeItemCatalog::rateKey((float) $rate)] ?? null) : null;
            if ($entry === null) {
                $errors[$field . '.vat_rate'] = 'No generic free item product for this VAT rate';
                continue;
            }
            $idAttribute = (int) ($product['id_product_attribute'] ?? 0);
            if ($idProduct !== $entry['id_product'] || !in_array($idAttribute, $entry['slots'], true)) {
                $errors[$field . '.id_product_attribute'] = 'Not a slot of the generic product of this VAT rate';
            } elseif (isset($seen[$idProduct . '-' . $idAttribute])) {
                $errors[$field . '.id_product_attribute'] = 'Slot already used by another line';
            }
            $seen[$idProduct . '-' . $idAttribute] = true;

            $price = $product['price'] ?? null;
            if (!(is_int($price) || is_float($price)) || !is_finite((float) $price) || (float) $price < 0.01) {
                $errors[$field . '.price'] = 'Required price with VAT >= 0.01';
            }
            $concept = $product['concept'] ?? null;
            if (!is_string($concept) || trim($concept) === '' || mb_strlen($concept) > 100 || preg_match(self::FORBIDDEN, $concept) === 1) {
                $errors[$field . '.concept'] = 'Required name of 1-100 characters without < > ; = # { }';
            }
        }
        if ($hasFree && (array_key_exists('coupon', $data) || ($data['operation'] ?? 'order') === 'coupon_preview')) {
            $errors['coupon'] = 'A coupon cannot be used with a free item';
        }

        return $errors;
    }

    public static function netPrice(float $priceWithVat, float $vatRate): float
    {
        return round($priceWithVat / (1 + $vatRate / 100), 6);
    }

    /**
     * Gives the cart the per-cart prices of its free lines. Called before the
     * products are added, so no price was cached for them yet.
     *
     * @param array<int, mixed> $products
     */
    public function priceCart(int $idCart, array $products): void
    {
        $priced = false;
        foreach ($products as $product) {
            if (!self::isFree($product)) {
                continue;
            }
            $this->gateway->addCartPrice(
                $idCart,
                (int) $product['id_product'],
                (int) $product['id_product_attribute'],
                self::netPrice((float) $product['price'], (float) $product['vat_rate'])
            );
            $priced = true;
            $this->pricedCart = $idCart;
        }
        if ($priced) {
            $this->gateway->flushPriceCache();
        }
    }

    /**
     * @param array<int, mixed> $products
     *
     * @return array<string, string> "id_product-id_product_attribute" => concept
     */
    public static function concepts(array $products): array
    {
        $names = [];
        foreach ($products as $product) {
            if (self::isFree($product)) {
                $names[(int) $product['id_product'] . '-' . (int) $product['id_product_attribute']] = trim((string) $product['concept']);
            }
        }

        return $names;
    }

    /**
     * After validateOrder: each free line takes its concept as name.
     *
     * @param array<int, mixed> $products
     */
    public function nameOrderLines(int $idOrder, array $products): void
    {
        foreach (self::concepts($products) as $key => $concept) {
            [$idProduct, $idAttribute] = array_map('intval', explode('-', $key));
            $this->gateway->renameOrderLine($idOrder, $idProduct, $idAttribute, $concept);
        }
    }

    /**
     * The per-cart prices are only needed while the order is created: deleted
     * after validateOrder and also when creating the order failed.
     */
    public function releaseCart(): void
    {
        if ($this->pricedCart !== null) {
            $this->gateway->deleteCartPrices($this->pricedCart);
            $this->pricedCart = null;
        }
    }
}

/**
 * Production FreeItemOrderGateway on top of PrestaShop.
 */
class PsFreeItemOrderGateway implements FreeItemOrderGateway
{
    public function addCartPrice(int $idCart, int $idProduct, int $idProductAttribute, float $netPrice): void
    {
        $price = new SpecificPrice();
        $price->id_specific_price_rule = 0;
        $price->id_cart = $idCart;
        $price->id_product = $idProduct;
        $price->id_product_attribute = $idProductAttribute;
        $price->id_shop = 0;
        $price->id_shop_group = 0;
        $price->id_currency = 0;
        $price->id_country = 0;
        $price->id_group = 0;
        $price->id_customer = 0;
        $price->price = $netPrice;
        $price->from_quantity = 1;
        $price->reduction = 0;
        $price->reduction_tax = 1;
        $price->reduction_type = 'amount';
        $price->from = '0000-00-00 00:00:00';
        $price->to = '0000-00-00 00:00:00';
        if (!$price->add()) {
            throw new RuntimeException(sprintf('Free item price could not be created for product %d combination %d', $idProduct, $idProductAttribute));
        }
    }

    public function flushPriceCache(): void
    {
        if (method_exists('SpecificPrice', 'flushCache')) {
            SpecificPrice::flushCache();
        }
        if (method_exists('Product', 'flushPriceCache')) {
            Product::flushPriceCache();
        }
        Cache::clean('*');
    }

    public function renameOrderLine(int $idOrder, int $idProduct, int $idProductAttribute, string $name): void
    {
        Db::getInstance()->update(
            'order_detail',
            ['product_name' => pSQL($name)],
            'id_order = ' . $idOrder . ' AND product_id = ' . $idProduct . ' AND product_attribute_id = ' . $idProductAttribute
        );
    }

    public function deleteCartPrices(int $idCart): void
    {
        Db::getInstance()->delete('specific_price', 'id_cart = ' . $idCart);
    }
}
