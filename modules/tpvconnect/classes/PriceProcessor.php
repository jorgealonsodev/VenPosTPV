<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/RefundProcessor.php';

/**
 * The PrestaShop operations a price change from the TPV needs, so the rules can be
 * tested without a shop. PsPriceGateway is the production implementation.
 */
interface PriceGateway
{
    /** True when the product exists and, for $attributeId > 0, the combination belongs to it. */
    public function itemExists(int $productId, int $attributeId): bool;

    /** The product's base price without VAT (Product->price). */
    public function productNetPrice(int $productId): float;

    /** The active tax rules group of the shop's country for this rate; null when there is none. */
    public function findTaxRulesGroup(float $rate): ?int;

    /** Sets the tax rules group and, unless null, the price without VAT of the product. */
    public function saveProduct(int $productId, ?float $netPrice, int $taxRulesGroup): void;

    /** Sets a combination's price impact without VAT over the product price. */
    public function saveCombinationImpact(int $productId, int $attributeId, float $impact): void;
}

/**
 * «Modo edición» of the TPV: the price WITH VAT of a product or of one of its
 * combinations, and the product's VAT rate (PrestaShop keeps the rate per product).
 * The product stores its price without VAT; a combination stores the difference to
 * the product price (impact), so its price with VAT is the one typed. Prices are
 * absolute values: running the same change twice leaves the same prices.
 *
 * Specific prices (reductions) of the shop are not touched.
 */
class PriceProcessor
{
    public const RATES = [21.0, 10.0, 4.0, 0.0];
    private const MAX_PRICE = 99999.99;

    public function __construct(private readonly PriceGateway $gateway)
    {
    }

    /**
     * @param array<string, mixed> $data {id_product, id_product_attribute, price, vat_rate}
     *
     * @return array{id_product: int, id_product_attribute: int, price: float, price_net: float, vat_rate: float}
     *
     * @throws RefundRequestException
     */
    public function process(array $data): array
    {
        [$productId, $attributeId, $price, $rate] = $this->validate($data);

        if (!$this->gateway->itemExists($productId, $attributeId)) {
            throw new RefundRequestException(404, 'Product not found', ['id_product' => 'Product or combination not found']);
        }
        $group = $rate == 0.0 ? 0 : $this->gateway->findTaxRulesGroup($rate);
        if ($group === null) {
            throw new RefundRequestException(422, 'Validation failed', ['vat_rate' => sprintf('The shop has no tax rule for %s %%', rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.'))]);
        }

        $net = round($price / (1 + $rate / 100), 6);
        if ($attributeId === 0) {
            $this->gateway->saveProduct($productId, $net, $group);
        } else {
            $this->gateway->saveProduct($productId, null, $group);
            $this->gateway->saveCombinationImpact($productId, $attributeId, round($net - $this->gateway->productNetPrice($productId), 6));
        }

        return ['id_product' => $productId, 'id_product_attribute' => $attributeId, 'price' => $price, 'price_net' => $net, 'vat_rate' => $rate];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{int, int, float, float}
     */
    private function validate(array $data): array
    {
        $errors = [];
        $productId = $data['id_product'] ?? null;
        if (!is_int($productId) || $productId <= 0) {
            $errors['id_product'] = 'Required positive integer';
        }
        $attributeId = $data['id_product_attribute'] ?? 0;
        if (!is_int($attributeId) || $attributeId < 0) {
            $errors['id_product_attribute'] = 'Must be a non-negative integer';
        }
        $price = $data['price'] ?? null;
        if (!(is_int($price) || is_float($price)) || !is_finite((float) $price) || $price < 0 || $price > self::MAX_PRICE) {
            $errors['price'] = 'Required number between 0 and 99999.99';
        }
        $rate = $data['vat_rate'] ?? null;
        if (!(is_int($rate) || is_float($rate)) || !in_array((float) $rate, self::RATES, true)) {
            $errors['vat_rate'] = 'Must be 21, 10, 4 or 0';
        }
        if ($errors !== []) {
            throw new RefundRequestException(422, 'Validation failed', $errors);
        }

        return [(int) $productId, (int) $attributeId, round((float) $price, 2), (float) $rate];
    }
}

/**
 * Production PriceGateway: Product and Combination objects of the current shop.
 */
class PsPriceGateway implements PriceGateway
{
    public function itemExists(int $productId, int $attributeId): bool
    {
        $product = new Product($productId);
        if (!Validate::isLoadedObject($product)) {
            return false;
        }
        if ($attributeId === 0) {
            return true;
        }
        $combination = new Combination($attributeId);

        return Validate::isLoadedObject($combination) && (int) $combination->id_product === $productId;
    }

    public function productNetPrice(int $productId): float
    {
        return (float) (new Product($productId))->price;
    }

    public function findTaxRulesGroup(float $rate): ?int
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT g.`id_tax_rules_group` FROM `' . _DB_PREFIX_ . 'tax_rules_group` g
             JOIN `' . _DB_PREFIX_ . 'tax_rule` r ON r.`id_tax_rules_group` = g.`id_tax_rules_group`
             JOIN `' . _DB_PREFIX_ . 'tax` t ON t.`id_tax` = r.`id_tax`
             WHERE g.`active` = 1 AND g.`deleted` = 0 AND t.`active` = 1 AND t.`deleted` = 0
               AND r.`id_country` = ' . (int) Configuration::get('PS_COUNTRY_DEFAULT') . ' AND r.`id_state` = 0
               AND ABS(t.`rate` - ' . (float) $rate . ') < 0.001
             ORDER BY g.`id_tax_rules_group` ASC',
            false
        );

        return $id > 0 ? $id : null;
    }

    /**
     * Only the price columns are written: saving the whole Product would validate
     * every other field too, and a shop whose descriptions are longer than
     * PrestaShop allows (description_short above its limit) could never change a
     * price. The product and each shop's copy (product_shop) get the same values.
     */
    public function saveProduct(int $productId, ?float $netPrice, int $taxRulesGroup): void
    {
        $fields = ['id_tax_rules_group' => $taxRulesGroup, 'date_upd' => date('Y-m-d H:i:s')];
        if ($netPrice !== null) {
            $fields['price'] = number_format($netPrice, 6, '.', '');
        }
        $db = Db::getInstance();
        if (!$db->update('product', $fields, '`id_product` = ' . $productId)
            || !$db->update('product_shop', $fields, '`id_product` = ' . $productId)) {
            throw new RuntimeException('The product price could not be saved');
        }
        $this->afterPriceChange($productId);
    }

    public function saveCombinationImpact(int $productId, int $attributeId, float $impact): void
    {
        $fields = ['price' => number_format($impact, 6, '.', '')];
        $db = Db::getInstance();
        $where = '`id_product_attribute` = ' . $attributeId;
        if (!$db->update('product_attribute', $fields, $where . ' AND `id_product` = ' . $productId)
            || !$db->update('product_attribute_shop', $fields, $where)) {
            throw new RuntimeException('The combination price could not be saved');
        }
        $this->afterPriceChange($productId);
    }

    /**
     * What PrestaShop does after a product save, so the shop shows the new price at
     * once. The price is already saved: a failure here must not report it as failed.
     */
    private function afterPriceChange(int $productId): void
    {
        try {
            if (method_exists('Product', 'resetStaticCache')) {
                Product::resetStaticCache();
            }
            Cache::clean('Product::getPriceStatic_' . $productId . '*');
            Hook::exec('actionProductUpdate', ['id_product' => $productId, 'product' => new Product($productId)]);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('tpvconnect: price saved for product ' . $productId . ', but refreshing the shop failed: ' . $e->getMessage(), 2);
        }
    }
}
