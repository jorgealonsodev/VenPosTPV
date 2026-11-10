<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use VenPosTPV\Service\Catalog\Exception\CatalogEditException;
use VenPosTPV\Service\Label\BarcodeSelector;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;
use VenPosTPV\Service\Sale\VatRate;
use VenPosTPV\Service\Stock\PendingUploadStock;

/**
 * «Modo edición» of the sale screen: what an administrator changes on a product
 * (id_product_attribute 0) or a combination.
 *
 * - Stock is shared with the web shop, so a new quantity is written to PrestaShop
 *   first (PUT stock_availables, as StockPushService does) and only then to the
 *   local catalog_stock. When PrestaShop refuses or does not answer nothing changes
 *   locally. The quantity typed is what the till must show; PrestaShop receives it
 *   plus what the operations still in the outbox will take off it (a pending sale
 *   not uploaded yet), exactly like StockPushService, so that sale is not counted
 *   twice when it is uploaded.
 * - EAN and reference stay at the till: they are stored in catalog_override, never
 *   sent to PrestaShop, and the sync (which empties and refills the catalog tables)
 *   never touches them. Readers apply them over the synced values.
 *
 * Every change writes an audit_log entry.
 */
class CatalogEditService
{
    public const ACTION_STOCK = 'catalog_stock_set';
    public const ACTION_OVERRIDE_SAVE = 'catalog_override_save';
    public const ACTION_OVERRIDE_RESET = 'catalog_override_reset';
    public const ACTION_PRICE = 'catalog_price_set';
    /** The VAT rates «Modo edición» offers (PrestaShop keeps the rate per product). */
    public const VAT_RATES = [21.0, 10.0, 4.0, 0.0];

    public function __construct(
        private readonly Connection $connection,
        private readonly PrestaShopApiClient $psApiClient,
        private readonly ?PsPriceClient $priceClient = null,
    ) {
    }

    /**
     * The item as the editor shows it: the synced values next to the local ones.
     *
     * @return array<string, mixed>
     *
     * @throws CatalogEditException
     */
    public function item(int $productId, int $attributeId): array
    {
        $synced = $this->synced($productId, $attributeId);
        $overridesAvailable = true;
        try {
            $local = $this->override($productId, $attributeId);
        } catch (TableNotFoundException) {
            $local = null;
            $overridesAvailable = false;
        }
        $stock = $this->connection->fetchAssociative(
            'SELECT quantity, ps_stock_id FROM catalog_stock WHERE id_product = ? AND id_product_attribute = ?',
            [$productId, $attributeId]
        );
        $stockPerCombination = $attributeId === 0 && $synced['has_combinations'];

        return [
            'id_product' => $productId,
            'id_product_attribute' => $attributeId,
            'name' => $synced['name'],
            'combination' => $synced['combination'],
            'has_combinations' => $synced['has_combinations'],
            'synced' => ['ean13' => $synced['ean13'], 'reference' => $synced['reference']],
            'local' => $local,
            'effective' => [
                'ean13' => $local['ean13'] ?? $synced['ean13'],
                'reference' => $local['reference'] ?? $synced['reference'],
            ],
            'stock' => [
                'quantity' => \is_array($stock) ? (int) $stock['quantity'] : 0,
                'editable' => !$stockPerCombination && \is_array($stock) && $stock['ps_stock_id'] !== null,
                'reason' => match (true) {
                    $stockPerCombination => 'El stock de un producto con tallas o colores se cambia en cada una',
                    !\is_array($stock) || $stock['ps_stock_id'] === null => 'Este artículo aún no tiene stock en PrestaShop: sincroniza el catálogo',
                    default => null,
                },
            ],
            'overrides_available' => $overridesAvailable,
            'price' => $this->price($productId, $attributeId),
        ];
    }

    /**
     * The item's price with VAT and its product's VAT rate, as the till charges them.
     *
     * @return array{price: float, vat_rate: ?float}
     */
    private function price(int $productId, int $attributeId): array
    {
        $row = $attributeId > 0
            ? $this->connection->fetchAssociative('SELECT price_ttc, price_net FROM catalog_combination WHERE id = ? AND id_product = ?', [$attributeId, $productId])
            : $this->connection->fetchAssociative('SELECT price_ttc, price_net FROM catalog_product WHERE id = ?', [$productId]);
        $ttc = \is_array($row) && is_numeric($row['price_ttc'] ?? null) ? (float) $row['price_ttc'] : null;
        $net = \is_array($row) && is_numeric($row['price_net'] ?? null) ? (float) $row['price_net'] : null;
        $resolved = (new CatalogPriceResolver($this->connection))->unitPrice($productId, $attributeId);
        $vat = $ttc !== null && $net !== null ? VatRate::fromPrices($ttc, $net) : null;

        return [
            'price' => round($ttc ?? (float) $resolved, 2),
            'vat_rate' => $vat === null || ($vat['approximate'] ?? false) ? null : (float) $vat['rate'],
        ];
    }

    /**
     * Sets the price WITH VAT of a product or a combination, and the product's VAT
     * rate, in PrestaShop (module 0.8.3), then in the local catalog: the product's
     * own prices and every combination's, since the rate is the product's.
     *
     * @return array<string, mixed> the item after the change
     *
     * @throws CatalogEditException
     */
    public function setPrice(int $productId, int $attributeId, mixed $price, mixed $vatRate, ?int $userId, ?int $terminalId = null, ?string $ip = null): array
    {
        $price = self::normalizePrice($price);
        $rate = self::normalizeVatRate($vatRate);
        $this->synced($productId, $attributeId);
        if ($this->priceClient === null) {
            throw new CatalogEditException('Cambiar precios no está disponible en esta instalación', 503, 'price_unavailable');
        }
        $before = $this->price($productId, $attributeId);

        $net = $this->priceClient->setPrice($productId, $attributeId, $price, $rate)['price_net'];

        // PrestaShop has the new price: the local catalog follows at once (the next
        // sync brings the shop's own figures, reductions included).
        $this->connection->transactional(function () use ($productId, $attributeId, $net, $rate): void {
            $factor = 1 + $rate / 100;
            if ($attributeId === 0) {
                $this->connection->executeStatement('UPDATE catalog_product SET price = ? WHERE id = ?', [$net, $productId]);
            } else {
                $base = (float) $this->connection->fetchOne('SELECT price FROM catalog_product WHERE id = ?', [$productId]);
                $this->connection->executeStatement(
                    'UPDATE catalog_combination SET price_impact = ? WHERE id = ? AND id_product = ?',
                    [round($net - $base, 6), $attributeId, $productId]
                );
            }
            $base = (float) $this->connection->fetchOne('SELECT price FROM catalog_product WHERE id = ?', [$productId]);
            $this->connection->executeStatement(
                'UPDATE catalog_product SET price_net = ?, price_ttc = ? WHERE id = ?',
                [$base, round($base * $factor, 2), $productId]
            );
            $this->connection->executeStatement(
                'UPDATE catalog_combination SET price_net = ? + price_impact, price_ttc = ROUND((? + price_impact) * ?, 2) WHERE id_product = ?',
                [$base, $base, $factor, $productId]
            );
        });

        $this->audit(self::ACTION_PRICE, $productId, $attributeId, [
            'before' => $before,
            'after' => ['price' => $price, 'vat_rate' => $rate],
            'price_net_sent' => $net,
        ], $userId, $terminalId, $ip);

        return $this->item($productId, $attributeId);
    }

    /** @throws CatalogEditException */
    public static function normalizePrice(mixed $value): float
    {
        $text = \is_string($value) ? str_replace(',', '.', trim($value)) : $value;
        if (!is_numeric($text) || (float) $text < 0 || (float) $text > 99999.99 || !preg_match('/^\d+(\.\d{1,2})?$/', (string) (\is_string($text) ? $text : round((float) $text, 2)))) {
            throw CatalogEditException::invalid('El precio debe ser un importe entre 0 y 99.999,99 € con hasta 2 decimales', 'invalid_price');
        }

        return round((float) $text, 2);
    }

    /** @throws CatalogEditException */
    public static function normalizeVatRate(mixed $value): float
    {
        if (!is_numeric($value) || !\in_array((float) $value, self::VAT_RATES, true)) {
            throw CatalogEditException::invalid('El IVA debe ser 21, 10, 4 o 0 %', 'invalid_vat_rate');
        }

        return (float) $value;
    }

    /**
     * Stores the local EAN and reference of an item. An empty value clears that
     * local value (the synced one applies again); both empty remove the override.
     *
     * @return array<string, mixed> the item after the change
     *
     * @throws CatalogEditException
     */
    public function saveOverride(int $productId, int $attributeId, mixed $ean13, mixed $reference, ?int $userId, ?int $terminalId = null, ?string $ip = null): array
    {
        $ean13 = self::normalizeEan($ean13);
        $reference = self::normalizeReference($reference);
        $this->synced($productId, $attributeId);

        try {
            $before = $this->override($productId, $attributeId);
            if ($ean13 === null && $reference === null) {
                $this->deleteOverride($productId, $attributeId);
            } else {
                $this->connection->executeStatement(
                    'INSERT INTO catalog_override (id_product, id_product_attribute, ean13, reference, updated_by_user_id, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE ean13 = VALUES(ean13), reference = VALUES(reference),
                        updated_by_user_id = VALUES(updated_by_user_id), updated_at = VALUES(updated_at)',
                    [$productId, $attributeId, $ean13, $reference, $userId, self::now()]
                );
            }
        } catch (TableNotFoundException) {
            throw CatalogEditException::overridesUnavailable();
        }

        $this->audit(self::ACTION_OVERRIDE_SAVE, $productId, $attributeId, [
            'before' => $before === null ? null : ['ean13' => $before['ean13'], 'reference' => $before['reference']],
            'after' => ['ean13' => $ean13, 'reference' => $reference],
        ], $userId, $terminalId, $ip);

        return $this->item($productId, $attributeId);
    }

    /**
     * «Restablecer»: removes the local values, the synced ones apply again.
     *
     * @return array<string, mixed> the item after the change
     *
     * @throws CatalogEditException
     */
    public function resetOverride(int $productId, int $attributeId, ?int $userId, ?int $terminalId = null, ?string $ip = null): array
    {
        $this->synced($productId, $attributeId);
        try {
            $before = $this->override($productId, $attributeId);
            $this->deleteOverride($productId, $attributeId);
        } catch (TableNotFoundException) {
            throw CatalogEditException::overridesUnavailable();
        }
        if ($before !== null) {
            $this->audit(self::ACTION_OVERRIDE_RESET, $productId, $attributeId, [
                'before' => ['ean13' => $before['ean13'], 'reference' => $before['reference']],
            ], $userId, $terminalId, $ip);
        }

        return $this->item($productId, $attributeId);
    }

    /**
     * Sets the absolute stock of an item in PrestaShop, then locally.
     *
     * @return array<string, mixed> the item after the change
     *
     * @throws CatalogEditException
     */
    public function setStock(int $productId, int $attributeId, mixed $quantity, ?int $userId, ?int $terminalId = null, ?string $ip = null): array
    {
        $quantity = self::normalizeQuantity($quantity);
        $synced = $this->synced($productId, $attributeId);
        if ($attributeId === 0 && $synced['has_combinations']) {
            throw CatalogEditException::invalid('El stock de un producto con tallas o colores se cambia en cada una', 'stock_per_combination');
        }

        // The row stays locked from the read to the write: a sale of this item at
        // another till waits, so it is neither lost nor counted twice.
        $this->connection->beginTransaction();
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT quantity, ps_stock_id FROM catalog_stock WHERE id_product = ? AND id_product_attribute = ? FOR UPDATE',
                [$productId, $attributeId]
            );
            if (!\is_array($row) || $row['ps_stock_id'] === null) {
                throw new CatalogEditException('Este artículo aún no tiene stock en PrestaShop: sincroniza el catálogo y vuelve a intentarlo', 409, 'stock_not_linked');
            }
            $before = (int) $row['quantity'];
            $owed = (new PendingUploadStock($this->connection))->owedByPendingUploads()[PendingUploadStock::key($productId, $attributeId)] ?? 0;
            $sent = $quantity + $owed;

            try {
                $this->psApiClient->updateStockAvailable((int) $row['ps_stock_id'], $sent);
            } catch (\Throwable $e) {
                throw new CatalogEditException('PrestaShop no ha aceptado el cambio de stock; no se ha cambiado nada', 502, 'prestashop_stock_failed', $e);
            }

            $this->connection->executeStatement(
                'UPDATE catalog_stock SET quantity = ? WHERE id_product = ? AND id_product_attribute = ?',
                [$quantity, $productId, $attributeId]
            );
            // PrestaShop recomputes the product's total (attribute 0) as the sum of
            // its combinations after the PUT (checked against PrestaShop 9.2); the
            // local total does the same until the next sync refreshes it.
            if ($attributeId > 0) {
                $total = (int) $this->connection->fetchOne(
                    'SELECT COALESCE(SUM(quantity), 0) FROM catalog_stock WHERE id_product = ? AND id_product_attribute > 0',
                    [$productId]
                );
                $this->connection->executeStatement(
                    'UPDATE catalog_stock SET quantity = ? WHERE id_product = ? AND id_product_attribute = 0',
                    [$total, $productId]
                );
            }
            $this->audit(self::ACTION_STOCK, $productId, $attributeId, [
                'before' => $before,
                'after' => $quantity,
                'sent_to_prestashop' => $sent,
            ], $userId, $terminalId, $ip);
            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $e;
        }

        return $this->item($productId, $attributeId);
    }

    /**
     * 8 or 13 digits with a correct check digit; empty = no local EAN.
     *
     * @throws CatalogEditException
     */
    public static function normalizeEan(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!\is_string($value) && !\is_int($value)) {
            throw CatalogEditException::invalid('El EAN debe tener 8 o 13 cifras', 'invalid_ean');
        }
        $ean = trim((string) $value);
        if ($ean === '') {
            return null;
        }
        if (preg_match('/\A(?:\d{8}|\d{13})\z/', $ean) !== 1) {
            throw CatalogEditException::invalid('El EAN debe tener 8 o 13 cifras', 'invalid_ean');
        }
        if (!BarcodeSelector::validCheckDigit($ean)) {
            throw CatalogEditException::invalid(sprintf('El EAN «%s» no es válido: el dígito de control no cuadra', $ean), 'invalid_ean_check_digit');
        }

        return $ean;
    }

    /** @throws CatalogEditException */
    public static function normalizeReference(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!\is_string($value) && !\is_int($value)) {
            throw CatalogEditException::invalid('La referencia no es válida', 'invalid_reference');
        }
        $reference = trim((string) $value);
        if ($reference === '') {
            return null;
        }
        if (mb_strlen($reference) > 64 || preg_match('/[\x00-\x1F\x7F]/', $reference) === 1) {
            throw CatalogEditException::invalid('La referencia admite hasta 64 caracteres', 'invalid_reference');
        }

        return $reference;
    }

    /** @throws CatalogEditException */
    public static function normalizeQuantity(mixed $value): int
    {
        $quantity = \is_string($value) ? trim($value) : $value;
        $quantity = filter_var($quantity, \FILTER_VALIDATE_INT);
        if (!\is_int($quantity) || $quantity < 0 || $quantity > 999999) {
            throw CatalogEditException::invalid('El stock debe ser un número entero igual o mayor que 0', 'invalid_quantity');
        }

        return $quantity;
    }

    /**
     * @return array{name: string, combination: ?string, has_combinations: bool, ean13: ?string, reference: ?string}
     *
     * @throws CatalogEditException
     */
    private function synced(int $productId, int $attributeId): array
    {
        if ($productId <= 0 || $attributeId < 0) {
            throw CatalogEditException::notFound();
        }
        $product = $this->connection->fetchAssociative(
            'SELECT p.name, p.ean13, p.reference,
                (SELECT COUNT(*) FROM catalog_combination cc WHERE cc.id_product = p.id) AS combinations
             FROM catalog_product p WHERE p.id = ?',
            [$productId]
        );
        if (!\is_array($product)) {
            throw CatalogEditException::notFound();
        }
        $values = $product;
        $combination = null;
        if ($attributeId > 0) {
            $row = $this->connection->fetchAssociative(
                'SELECT ean13, reference FROM catalog_combination WHERE id = ? AND id_product = ?',
                [$attributeId, $productId]
            );
            if (!\is_array($row)) {
                throw CatalogEditException::notFound();
            }
            $values = $row;
            $attributes = $this->connection->fetchFirstColumn(
                'SELECT attribute_name FROM catalog_combination_attribute WHERE combination_id = ? ORDER BY group_name, attribute_name',
                [$attributeId]
            );
            $combination = $attributes === [] ? null : implode(' · ', array_map('strval', $attributes));
        }

        return [
            'name' => (string) $product['name'],
            'combination' => $combination,
            'has_combinations' => (int) $product['combinations'] > 0,
            'ean13' => self::nullIfEmpty($values['ean13'] ?? null),
            'reference' => self::nullIfEmpty($values['reference'] ?? null),
        ];
    }

    /**
     * @return array{ean13: ?string, reference: ?string, updated_at: string, updated_by_user_id: ?int}|null
     *
     * @throws TableNotFoundException before Version00000050
     */
    private function override(int $productId, int $attributeId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ean13, reference, updated_at, updated_by_user_id FROM catalog_override WHERE id_product = ? AND id_product_attribute = ?',
            [$productId, $attributeId]
        );
        if (!\is_array($row)) {
            return null;
        }

        return [
            'ean13' => self::nullIfEmpty($row['ean13']),
            'reference' => self::nullIfEmpty($row['reference']),
            'updated_at' => (string) $row['updated_at'],
            'updated_by_user_id' => $row['updated_by_user_id'] !== null ? (int) $row['updated_by_user_id'] : null,
        ];
    }

    private function deleteOverride(int $productId, int $attributeId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM catalog_override WHERE id_product = ? AND id_product_attribute = ?',
            [$productId, $attributeId]
        );
    }

    /** @param array<string, mixed> $payload */
    private function audit(string $action, int $productId, int $attributeId, array $payload, ?int $userId, ?int $terminalId, ?string $ip): void
    {
        try {
            $this->connection->insert('audit_log', [
                'user_id' => $userId,
                'terminal_id' => $terminalId,
                'action' => $action,
                'target' => $productId . ':' . $attributeId,
                'payload' => json_encode(['id_product' => $productId, 'id_product_attribute' => $attributeId] + $payload, JSON_THROW_ON_ERROR),
                'ip' => $ip,
                'created_at' => self::now(),
            ]);
        } catch (\Throwable) {
            // Best-effort, like the other audit entries: the change (already in
            // PrestaShop for a stock change) is kept.
        }
    }

    private static function nullIfEmpty(mixed $value): ?string
    {
        $value = \is_string($value) ? trim($value) : null;

        return $value === null || $value === '' ? null : $value;
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
