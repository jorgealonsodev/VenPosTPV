<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Checkout;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use VenPosTPV\Service\Sale\VatRate;

/**
 * Reads the free item settings from app_setting (`free_item.enabled` 1/0,
 * `free_item.role` all/admin, `free_item.max_price`, `free_item.vat_rates` as
 * «21,10»). A missing or unusable value takes its default, so the till works before
 * anyone sets them; a rate that is not a Spanish VAT rate is ignored.
 *
 * `free_item.ps_products` holds the hidden generic PrestaShop products the module
 * created (articulo-libre.md, A5.2), as JSON keyed by rate:
 * {"21.00": {"id_product": 22, "slots": {"1": 46, …, "20": 65}}, …}.
 */
class FreeItemSettingsStore
{
    public const PS_PRODUCTS = 'free_item.ps_products';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function load(): FreeItemSettings
    {
        $defaults = FreeItemSettings::defaults();
        try {
            $rows = $this->connection->fetchAllKeyValue("SELECT name, value FROM app_setting WHERE name LIKE 'free\\_item.%'");
        } catch (TableNotFoundException) {
            return $defaults;
        }

        $enabled = $defaults->enabled;
        if (isset($rows['free_item.enabled']) && \in_array((string) $rows['free_item.enabled'], ['0', '1'], true)) {
            $enabled = (string) $rows['free_item.enabled'] === '1';
        }
        $role = \in_array($rows['free_item.role'] ?? null, [FreeItemSettings::ROLE_ALL, FreeItemSettings::ROLE_ADMIN], true)
            ? (string) $rows['free_item.role']
            : $defaults->role;
        $maxPrice = $defaults->maxPrice;
        if (isset($rows['free_item.max_price']) && is_numeric($rows['free_item.max_price']) && (float) $rows['free_item.max_price'] >= 0.01) {
            $maxPrice = round((float) $rows['free_item.max_price'], 2);
        }
        $rates = [];
        foreach (explode(',', (string) ($rows['free_item.vat_rates'] ?? '')) as $value) {
            $value = trim($value);
            if (is_numeric($value) && \in_array((float) $value, VatRate::KNOWN, true) && !\in_array((float) $value, $rates, true)) {
                $rates[] = (float) $value;
            }
        }

        return new FreeItemSettings($enabled, $role, $maxPrice, $rates === [] ? $defaults->vatRates : $rates);
    }

    /**
     * @return array<string, array{id_product: int, slots: array<int, int>}> keyed by rate ("21.00");
     *                                                                     empty before the setup
     */
    public function loadPsProducts(): array
    {
        try {
            $json = $this->connection->fetchOne('SELECT value FROM app_setting WHERE name = ?', [self::PS_PRODUCTS]);
        } catch (TableNotFoundException) {
            return [];
        }
        $map = \is_string($json) ? json_decode($json, true) : null;
        if (!\is_array($map)) {
            return [];
        }

        $clean = [];
        foreach ($map as $rate => $entry) {
            if (!is_numeric($rate) || !\is_array($entry) || (int) ($entry['id_product'] ?? 0) < 1 || !\is_array($entry['slots'] ?? null)) {
                continue;
            }
            $slots = [];
            foreach ($entry['slots'] as $slot => $combination) {
                if ((int) $slot >= 1 && (int) $combination >= 1) {
                    $slots[(int) $slot] = (int) $combination;
                }
            }
            $clean[self::rateKey((float) $rate)] = ['id_product' => (int) $entry['id_product'], 'slots' => $slots];
        }

        return $clean;
    }

    /** @param array<string, array{id_product: int, slots: array<int|string, int>}> $map */
    public function savePsProducts(array $map): void
    {
        $this->connection->executeStatement(
            'INSERT INTO app_setting (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [self::PS_PRODUCTS, json_encode($map, \JSON_THROW_ON_ERROR)]
        );
    }

    /** @return list<int> the PrestaShop ids of the generic products */
    public function genericProductIds(): array
    {
        return array_values(array_unique(array_column($this->loadPsProducts(), 'id_product')));
    }

    public static function rateKey(float $rate): string
    {
        return number_format($rate, 2, '.', '');
    }
}
