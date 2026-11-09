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
 */
class FreeItemSettingsStore
{
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
}
