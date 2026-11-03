<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Catalog;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Catalog\Exception\ProductNotInCatalogException;

/**
 * Current tax-included unit price of a product or combination from the synced
 * catalog, shared by checkout (price validation) and refunds without a ticket.
 *
 * The tax-included price wins because it is what PrestaShop charges and books:
 * `catalog_product.price_ttc` for a plain product, and
 * `catalog_combination.price_ttc` (absolute, it already includes the product
 * price and the impact) when a combination is selected. Base `price` +
 * `price_impact` is kept only while the TTC value is NULL.
 */
class CatalogPriceResolver
{
    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * Returns null for a non-catalog id (<= 0) or when the product row exists
     * without a usable price.
     *
     * @throws ProductNotInCatalogException when the product has no catalog_product row
     */
    public function unitPrice(int $productId, int $combinationId): ?float
    {
        if ($productId <= 0) {
            return null;
        }

        $product = $this->conn->fetchAssociative('SELECT price, price_ttc FROM catalog_product WHERE id = ?', [$productId]);
        if ($product === false) {
            throw new ProductNotInCatalogException($productId);
        }

        $basePrice = isset($product['price']) ? (float) $product['price'] : null;
        $productTtcPrice = isset($product['price_ttc']) && is_numeric($product['price_ttc'])
            ? (float) $product['price_ttc']
            : null;

        if ($combinationId <= 0) {
            if ($productTtcPrice !== null) {
                return round($productTtcPrice, 2);
            }

            return $basePrice !== null ? round($basePrice, 2) : null;
        }

        $combination = $this->conn->fetchAssociative(
            'SELECT price_impact, price_ttc FROM catalog_combination WHERE id = ? AND id_product = ?',
            [$combinationId, $productId]
        );

        if ($combination !== false && isset($combination['price_ttc']) && is_numeric($combination['price_ttc'])) {
            return round((float) $combination['price_ttc'], 2);
        }

        if ($basePrice === null) {
            return null;
        }

        $impact = $combination !== false && isset($combination['price_impact'])
            ? (float) $combination['price_impact']
            : 0.0;

        return round($basePrice + $impact, 2);
    }
}
