<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Catalog;

use Closure;
use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Catalog\Exception\CatalogSyncException;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;
use VenPosTPV\Service\Sync\SyncLogRepository;

class CatalogSyncService
{
    public function __construct(
        private readonly PrestaShopApiClient $apiClient,
        private readonly Connection $connection,
        private readonly SyncLogRepository $syncLogRepo,
        private readonly string $psShopUrl = '',
        private readonly string $psApiKey = ''
    ) {
    }

    /**
     * @return array{products: int, combinations: int, combination_attributes: int, stock: int, categories: int, ttc_prices: int}
     */
    public function syncAll(): array
    {
        // Remote reads happen before the write transaction: the request sequence is
        // long and holding a transaction open across HTTP calls blocks the catalog,
        // and a failed read then leaves the stored catalog untouched.
        try {
            $categories = $this->apiClient->getCategories();
            $products = $this->apiClient->getProducts();
            $combinations = $this->apiClient->getCombinations();
            $options = $this->apiClient->getProductOptions();
            $optionValues = $this->apiClient->getProductOptionValues();
            $stockAvailables = $this->apiClient->getStockAvailables();
            $authoritativePrices = $this->fetchAuthoritativePrices($products, $combinations);
        } catch (CatalogSyncException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw CatalogSyncException::fromPrevious($e);
        }

        $taxIncludedPriceCount = 0;
        foreach (['products', 'combinations'] as $kind) {
            foreach ($authoritativePrices[$kind] as $prices) {
                if ($prices['ttc'] !== null) {
                    ++$taxIncludedPriceCount;
                }
            }
        }

        $this->connection->beginTransaction();

        try {
            $this->truncateTable('catalog_stock');
            $this->truncateTable('catalog_combination_attribute');
            $this->truncateTable('catalog_combination');
            $this->truncateTable('catalog_product');
            $this->truncateTable('catalog_category');

            $categoryCount = $this->batchInsert(
                'catalog_category',
                $categories,
                static function (array $cat): array {
                    return [
                        'id' => (int) $cat['id'],
                        'name' => PrestaShopApiClient::extractTranslations($cat['name'] ?? '', '1') ?? '',
                        'id_parent' => (int) ($cat['id_parent'] ?? 0),
                        'active' => (int) ($cat['active'] ?? 1),
                    ];
                }
            );

            $psShopUrl = $this->psShopUrl;
            $psApiKey = $this->psApiKey;
            $productPrices = $authoritativePrices['products'];
            $productCount = $this->batchInsert(
                'catalog_product',
                $products,
                static function (array $prod) use ($psShopUrl, $psApiKey, $productPrices): array {
                    $name = PrestaShopApiClient::extractTranslations($prod['name'] ?? '', '1');
                    $imageId = (int) ($prod['id_default_image'] ?? 0);
                    $imageUrl = null;
                    if ($imageId > 0 && $psShopUrl !== '') {
                        $imageUrl = rtrim($psShopUrl, '/') . '/api/images/products/' . $prod['id'] . '/' . $imageId . '?ws_key=' . $psApiKey;
                    }

                    return [
                        'id' => (int) $prod['id'],
                        'name' => \is_string($name) ? $name : '',
                        'reference' => isset($prod['reference']) && \is_string($prod['reference']) ? $prod['reference'] : null,
                        'ean13' => isset($prod['ean13']) && \is_string($prod['ean13']) ? $prod['ean13'] : null,
                        'price' => (float) ($prod['price'] ?? 0),
                        'price_ttc' => $productPrices[(int) $prod['id']]['ttc'] ?? null,
                        'price_net' => $productPrices[(int) $prod['id']]['net'] ?? null,
                        'id_category_default' => isset($prod['id_category_default']) ? (int) $prod['id_category_default'] : null,
                        'active' => (int) ($prod['active'] ?? 1),
                        'image_url' => $imageUrl,
                    ];
                }
            );

            $combinationPrices = $authoritativePrices['combinations'];
            $combinationCount = $this->batchInsert(
                'catalog_combination',
                $combinations,
                static function (array $comb) use ($combinationPrices): array {
                    return [
                        'id' => (int) $comb['id'],
                        'id_product' => (int) ($comb['id_product'] ?? 0),
                        'reference' => isset($comb['reference']) && \is_string($comb['reference']) ? $comb['reference'] : null,
                        'ean13' => isset($comb['ean13']) && \is_string($comb['ean13']) ? $comb['ean13'] : null,
                        'price_impact' => (float) ($comb['price'] ?? 0),
                        'price_ttc' => $combinationPrices[(int) $comb['id']]['ttc'] ?? null,
                        'price_net' => $combinationPrices[(int) $comb['id']]['net'] ?? null,
                    ];
                }
            );

            // Build option group map: id → name
            $optionMap = [];
            foreach ($options as $opt) {
                $name = PrestaShopApiClient::extractTranslations($opt['name'] ?? $opt['public_name'] ?? '');
                $optionMap[(int) $opt['id']] = \is_string($name) ? $name : 'Attribute';
            }

            // Build option value map: id → [group_name, attribute_name]
            $valueMap = [];
            foreach ($optionValues as $val) {
                $groupId = (int) ($val['id_attribute_group'] ?? 0);
                $attrName = PrestaShopApiClient::extractTranslations($val['name'] ?? '');
                $valueMap[(int) $val['id']] = [
                    'group_name' => $optionMap[$groupId] ?? 'Attribute',
                    'attribute_name' => \is_string($attrName) ? $attrName : '',
                ];
            }

            // Insert combination attributes
            $combinationAttributeCount = 0;
            foreach ($combinations as $comb) {
                $combinationId = (int) $comb['id'];
                $povAssoc = $comb['associations']['product_option_values'] ?? [];

                if (!\is_array($povAssoc) || $povAssoc === []) {
                    continue;
                }

                // PS may return a single item (not wrapped in array of arrays)
                if (isset($povAssoc['id'])) {
                    $povAssoc = [$povAssoc];
                }

                $attrRows = [];
                foreach ($povAssoc as $pov) {
                    $valueId = (int) ($pov['id'] ?? 0);
                    if ($valueId === 0 || !isset($valueMap[$valueId])) {
                        continue;
                    }
                    $attrRows[] = [
                        'combination_id' => $combinationId,
                        'group_name' => $valueMap[$valueId]['group_name'],
                        'attribute_name' => $valueMap[$valueId]['attribute_name'],
                    ];
                }

                if ($attrRows !== []) {
                    $combinationAttributeCount += $this->executeBatchInsert('catalog_combination_attribute', $attrRows);
                }
            }

            $stockCount = $this->batchInsert(
                'catalog_stock',
                $stockAvailables,
                static function (array $stock): array {
                    return [
                        'id_product' => (int) ($stock['id_product'] ?? 0),
                        'id_product_attribute' => (int) ($stock['id_product_attribute'] ?? 0),
                        'quantity' => (int) ($stock['quantity'] ?? 0),
                        'ps_stock_id' => (int) ($stock['id'] ?? 0) ?: null,
                    ];
                }
            );

            $this->connection->commit();

            $this->syncLogRepo->upsert('catalog', 'inbound', new \DateTimeImmutable(), $productCount);
            $this->syncLogRepo->upsert('stock', 'inbound', new \DateTimeImmutable(), $stockCount);
            // The row count reports how many products and combinations got an
            // authoritative tax-included price, so a partial price lookup is visible.
            $this->syncLogRepo->upsert('prices', 'inbound', new \DateTimeImmutable(), $taxIncludedPriceCount);

            return [
                'products' => $productCount,
                'combinations' => $combinationCount,
                'combination_attributes' => $combinationAttributeCount,
                'stock' => $stockCount,
                'categories' => $categoryCount,
                'ttc_prices' => $taxIncludedPriceCount,
            ];
        } catch (CatalogSyncException $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw CatalogSyncException::fromPrevious($e);
        }
    }

    /**
     * Resolves both authoritative prices PrestaShop would charge for every product
     * and combination: the tax-included price the shop books and the tax-excluded
     * price with reductions applied, whose difference is the VAT actually charged.
     *
     * It runs before the write transaction and never fails the sync: a product
     * without resolved prices keeps NULL, so the checkout falls back to the base
     * price instead of blocking a sale.
     *
     * @param array<int, array<string, mixed>> $products
     * @param array<int, array<string, mixed>> $combinations
     *
     * @return array{products: array<int, array{ttc: float|null, net: float|null}>, combinations: array<int, array{ttc: float|null, net: float|null}>}
     */
    private function fetchAuthoritativePrices(array $products, array $combinations): array
    {
        $productPrices = [];
        foreach ($products as $product) {
            $productId = (int) ($product['id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $prices = $this->fetchAuthoritativePrice($productId);
            if ($prices !== null) {
                $productPrices[$productId] = $prices;
            }
        }

        $combinationPrices = [];
        foreach ($combinations as $combination) {
            $combinationId = (int) ($combination['id'] ?? 0);
            $productId = (int) ($combination['id_product'] ?? 0);
            if ($combinationId <= 0 || $productId <= 0) {
                continue;
            }

            $prices = $this->fetchAuthoritativePrice($productId, $combinationId);
            if ($prices !== null) {
                $combinationPrices[$combinationId] = $prices;
            }
        }

        return ['products' => $productPrices, 'combinations' => $combinationPrices];
    }

    /**
     * @return array{ttc: float|null, net: float|null}|null
     */
    private function fetchAuthoritativePrice(int $productId, int $combinationId = 0): ?array
    {
        try {
            return $this->apiClient->getAuthoritativePrices($productId, $combinationId);
        } catch (\Throwable) {
            // Best effort: the base price keeps the sale possible and the checkout
            // records which basis it used.
            return null;
        }
    }

    /**
     * @return array{stock: int}
     */
    public function syncStock(): array
    {
        $this->connection->beginTransaction();

        try {
            $this->truncateTable('catalog_stock');

            $stockAvailables = $this->apiClient->getStockAvailables();
            $stockCount = $this->batchInsert(
                'catalog_stock',
                $stockAvailables,
                static function (array $stock): array {
                    return [
                        'id_product' => (int) ($stock['id_product'] ?? 0),
                        'id_product_attribute' => (int) ($stock['id_product_attribute'] ?? 0),
                        'quantity' => (int) ($stock['quantity'] ?? 0),
                        'ps_stock_id' => (int) ($stock['id'] ?? 0) ?: null,
                    ];
                }
            );

            $this->connection->commit();

            $this->syncLogRepo->upsert('stock', 'inbound', new \DateTimeImmutable(), $stockCount);

            return ['stock' => $stockCount];
        } catch (CatalogSyncException $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw CatalogSyncException::fromPrevious($e);
        }
    }

    private function truncateTable(string $table): void
    {
        $this->connection->executeStatement("DELETE FROM `{$table}`");
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param Closure(array<string, mixed>): array<string, mixed> $rowToParams
     */
    private function batchInsert(string $table, array $rows, Closure $rowToParams): int
    {
        if ($rows === []) {
            return 0;
        }

        $count = 0;
        $batchSize = 100;
        $batch = [];

        foreach ($rows as $row) {
            $batch[] = $rowToParams($row);

            if (\count($batch) >= $batchSize) {
                $count += $this->executeBatchInsert($table, $batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $count += $this->executeBatchInsert($table, $batch);
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function executeBatchInsert(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $columns = array_keys($rows[0]);
        $columnList = implode(', ', array_map(static fn (string $col): string => "`{$col}`", $columns));
        $placeholders = '(' . implode(', ', array_fill(0, \count($columns), '?')) . ')';

        $allValues = [];
        $allParams = [];

        foreach ($rows as $row) {
            $allValues[] = $placeholders;
            foreach ($columns as $col) {
                $allParams[] = $row[$col];
            }
        }

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES %s',
            $table,
            $columnList,
            implode(', ', $allValues)
        );

        return $this->connection->executeStatement($sql, $allParams);
    }
}
