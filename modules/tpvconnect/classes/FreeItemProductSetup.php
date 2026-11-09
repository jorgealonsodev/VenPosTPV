<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/FreeItemOrderLines.php';

/** A setup request that cannot be met as it is (unknown rate, no tax rules group). */
class FreeItemSetupException extends RuntimeException
{
}

/**
 * The PrestaShop operations the free item setup needs, so the rules can be tested
 * without a shop. PsFreeItemSetupGateway is the production one.
 */
interface FreeItemSetupGateway
{
    /** Active tax rules group whose rule for the shop's default country has this rate. */
    public function findTaxRulesGroup(float $rate): ?int;

    public function findOrCreateAttributeGroup(string $name): int;

    public function findOrCreateAttribute(int $idAttributeGroup, string $name): int;

    public function findProductByReference(string $reference): ?int;

    /** Creates or corrects the hidden product (name, tax group, visibility, out of stock orders). */
    public function saveProduct(?int $idProduct, string $name, string $reference, int $idTaxRulesGroup): int;

    /** The combination of the product made of exactly this attribute, created when missing. */
    public function findOrCreateCombination(int $idProduct, int $idAttribute, string $reference, bool $default): int;
}

/**
 * POST /module/tpvconnect/freeitem (articulo-libre.md, A5.1-A5.2): creates or
 * checks one hidden generic product per VAT rate, with SLOTS combinations of the
 * attribute «Línea TPV» 1..SLOTS, and returns their ids. Idempotent: the products
 * are found again by their reference (TPV-LIBRE-21), the attribute group by its
 * name and the combinations by their attribute, so running it again returns the
 * same ids and only fills in what is missing.
 */
class FreeItemProductSetup
{
    public const SLOTS = 20;
    public const ATTRIBUTE_GROUP = 'Línea TPV';
    /** Rates a generic product can be created for (exempt only when asked, P2). */
    public const RATES = [21.0, 10.0, 4.0, 0.0];

    public function __construct(private readonly FreeItemSetupGateway $gateway)
    {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string> field errors, empty when valid
     */
    public static function validateRequest(array $data): array
    {
        $rates = $data['vat_rates'] ?? null;
        if (!is_array($rates) || $rates === [] || !array_is_list($rates)) {
            return ['vat_rates' => 'Required non-empty list of VAT rates'];
        }
        foreach ($rates as $rate) {
            if (!(is_int($rate) || is_float($rate)) || !in_array((float) $rate, self::RATES, true)) {
                return ['vat_rates' => 'Each rate must be one of 21, 10, 4, 0'];
            }
        }

        return [];
    }

    /**
     * @param list<int|float> $rates
     *
     * @return array<string, array{id_product: int, slots: array<int, int>}> keyed by rate ("21.00")
     *
     * @throws FreeItemSetupException when a rate has no tax rules group in the shop
     */
    public function setup(array $rates): array
    {
        // Every rate is checked before anything is created.
        $taxGroups = [];
        foreach ($rates as $rate) {
            $key = FreeItemCatalog::rateKey((float) $rate);
            if ((float) $rate === 0.0) {
                $taxGroups[$key] = 0;
                continue;
            }
            $idGroup = $this->gateway->findTaxRulesGroup((float) $rate);
            if ($idGroup === null) {
                throw new FreeItemSetupException(sprintf('No active tax rules group with %s %% for the default country', $key));
            }
            $taxGroups[$key] = $idGroup;
        }

        $idAttributeGroup = $this->gateway->findOrCreateAttributeGroup(self::ATTRIBUTE_GROUP);
        $attributes = [];
        for ($slot = 1; $slot <= self::SLOTS; ++$slot) {
            $attributes[$slot] = $this->gateway->findOrCreateAttribute($idAttributeGroup, (string) $slot);
        }

        $map = FreeItemCatalog::load();
        foreach ($taxGroups as $key => $idTaxRulesGroup) {
            $label = (float) $key === 0.0 ? 'exento' : rtrim(rtrim($key, '0'), '.') . ' %';
            $reference = FreeItemCatalog::REFERENCE_PREFIX . ((float) $key === 0.0 ? 'EXENTO' : rtrim(rtrim($key, '0'), '.'));
            $idProduct = $this->gateway->saveProduct(
                $this->gateway->findProductByReference($reference),
                'Artículo libre TPV ' . $label,
                $reference,
                $idTaxRulesGroup
            );
            $slots = [];
            foreach ($attributes as $slot => $idAttribute) {
                $slots[$slot] = $this->gateway->findOrCreateCombination($idProduct, $idAttribute, $reference . '-' . $slot, $slot === 1);
            }
            $map[$key] = ['id_product' => $idProduct, 'slots' => $slots];
        }
        FreeItemCatalog::save($map);

        return $map;
    }
}

/**
 * Production FreeItemSetupGateway on top of PrestaShop's ObjectModels.
 */
class PsFreeItemSetupGateway implements FreeItemSetupGateway
{
    /**
     * The price a web customer would pay if they ever reached the hidden product:
     * TPV orders always get a per-cart price, so nobody can buy it for 0 € online.
     */
    private const GUARD_PRICE = 99999.0;

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

    public function findOrCreateAttributeGroup(string $name): int
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_attribute_group` FROM `' . _DB_PREFIX_ . "attribute_group_lang` WHERE `name` = '" . pSQL($name) . "' ORDER BY `id_attribute_group` ASC",
            false
        );
        if ($id > 0) {
            return $id;
        }
        $group = new AttributeGroup();
        $group->name = $this->multilang($name);
        $group->public_name = $this->multilang('Línea');
        $group->group_type = 'select';
        $group->is_color_group = false;
        if (!$group->add()) {
            throw new RuntimeException('Attribute group could not be created');
        }

        return (int) $group->id;
    }

    public function findOrCreateAttribute(int $idAttributeGroup, string $name): int
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT a.`id_attribute` FROM `' . _DB_PREFIX_ . 'attribute` a JOIN `' . _DB_PREFIX_ . 'attribute_lang` l ON l.`id_attribute` = a.`id_attribute`
             WHERE a.`id_attribute_group` = ' . $idAttributeGroup . " AND l.`name` = '" . pSQL($name) . "' ORDER BY a.`id_attribute` ASC",
            false
        );
        if ($id > 0) {
            return $id;
        }
        $attribute = class_exists('ProductAttribute') ? new ProductAttribute() : new Attribute();
        $attribute->id_attribute_group = $idAttributeGroup;
        $attribute->name = $this->multilang($name);
        if (!$attribute->add()) {
            throw new RuntimeException(sprintf('Attribute %s could not be created', $name));
        }

        return (int) $attribute->id;
    }

    public function findProductByReference(string $reference): ?int
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . "product` WHERE `reference` = '" . pSQL($reference) . "' ORDER BY `id_product` ASC",
            false
        );

        return $id > 0 ? $id : null;
    }

    public function saveProduct(?int $idProduct, string $name, string $reference, int $idTaxRulesGroup): int
    {
        $product = new Product($idProduct ?? null);
        $home = (int) Configuration::get('PS_HOME_CATEGORY');
        $product->name = $this->multilang($name);
        $product->link_rewrite = $this->multilang(Tools::str2url($name));
        $product->reference = $reference;
        $product->price = self::GUARD_PRICE;
        $product->id_tax_rules_group = $idTaxRulesGroup;
        $product->visibility = 'none';
        $product->available_for_order = true;
        $product->show_price = true;
        $product->active = true;
        $product->indexed = 0;
        $product->is_virtual = false;
        $product->id_category_default = $home;
        $saved = $idProduct === null ? $product->add() : $product->update();
        if (!$saved) {
            throw new RuntimeException(sprintf('Generic product %s could not be saved', $reference));
        }
        if ($idProduct === null) {
            $product->addToCategories([$home]);
        }
        // Not in the shop's search: the index rows of the product are removed.
        Db::getInstance()->delete('search_index', 'id_product = ' . (int) $product->id);
        StockAvailable::setProductOutOfStock((int) $product->id, 1);

        return (int) $product->id;
    }

    public function findOrCreateCombination(int $idProduct, int $idAttribute, string $reference, bool $default): int
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT pa.`id_product_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute` pa
             JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` c ON c.`id_product_attribute` = pa.`id_product_attribute`
             WHERE pa.`id_product` = ' . $idProduct . ' AND c.`id_attribute` = ' . $idAttribute . ' ORDER BY pa.`id_product_attribute` ASC',
            false
        );
        if ($id <= 0) {
            $combination = new Combination();
            $combination->id_product = $idProduct;
            $combination->reference = $reference;
            $combination->price = 0;
            $combination->minimal_quantity = 1;
            $combination->default_on = $default ? 1 : null;
            if (!$combination->add()) {
                throw new RuntimeException(sprintf('Combination %s could not be created', $reference));
            }
            $combination->setAttributes([$idAttribute]);
            $id = (int) $combination->id;
            if ($default) {
                Product::updateDefaultAttribute($idProduct);
            }
        }
        StockAvailable::setProductOutOfStock($idProduct, 1, null, $id);

        return $id;
    }

    /** @return array<int, string> */
    private function multilang(string $value): array
    {
        $values = [];
        foreach (Language::getLanguages(false) as $language) {
            $values[(int) $language['id_lang']] = $value;
        }

        return $values;
    }
}
