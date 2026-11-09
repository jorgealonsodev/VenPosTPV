<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Checkout;

/**
 * Sets up the upload of free items (docs/prd/articulo-libre.md, A5.2): the module
 * creates or checks one hidden generic product per VAT rate offered at the till,
 * with FreeItemLine::MAX_SLOTS_PER_RATE slot combinations, and its ids are stored
 * in app_setting (free_item.ps_products). OutboxSync reads them when it uploads a
 * sale, so sales waiting for the setup go up on the next run. Running it again
 * returns the same ids; after adding a VAT rate to free_item.vat_rates run it again.
 */
class FreeItemSetupService
{
    public function __construct(
        private readonly PsOrderClient $client,
        private readonly FreeItemSettingsStore $store,
    ) {
    }

    /**
     * @return array<string, array{id_product: int, slots: array<int, int>}> keyed by rate ("21.00")
     *
     * @throws \RuntimeException when PrestaShop refuses or answers without every rate and slot
     */
    public function run(): array
    {
        $rates = array_map(
            static fn (float $rate): int|float => floor($rate) === $rate ? (int) $rate : $rate,
            $this->store->load()->vatRates
        );
        $answer = $this->client->setupFreeItemProducts($rates);

        $map = [];
        foreach ($rates as $rate) {
            $key = FreeItemSettingsStore::rateKey((float) $rate);
            $entry = \is_array($answer['products'] ?? null) ? ($answer['products'][$key] ?? null) : null;
            $idProduct = \is_array($entry) ? (int) ($entry['id_product'] ?? 0) : 0;
            $slots = [];
            for ($slot = 1; $slot <= FreeItemLine::MAX_SLOTS_PER_RATE; ++$slot) {
                $combination = (int) ($entry['slots'][(string) $slot] ?? $entry['slots'][$slot] ?? 0);
                if ($combination > 0) {
                    $slots[$slot] = $combination;
                }
            }
            if ($idProduct < 1 || \count($slots) !== FreeItemLine::MAX_SLOTS_PER_RATE) {
                throw new \RuntimeException(sprintf('PrestaShop did not return the generic product and its %d slots for VAT %s %%', FreeItemLine::MAX_SLOTS_PER_RATE, $key));
            }
            $map[$key] = ['id_product' => $idProduct, 'slots' => $slots];
        }
        $this->store->savePsProducts($map);

        return $map;
    }
}
