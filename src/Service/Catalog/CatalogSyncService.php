<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Catalog;

use Closure;
use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Catalog\Exception\CatalogSyncException;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;

class CatalogSyncService
{
    public function __construct(
        private readonly PrestaShopApiClient $apiClient,
        private readonly Connection $connection
    ) {
    }

    /**
     * @return array{products: int, combinations: int, stock: int, categories: int}
     */
    public function syncAll(): array
    {
        $this->connection->beginTransaction();

        try {
            $this->truncateTable('catalog_stock');
            $this->truncateTable('catalog_combination');
            $this->truncateTable('catalog_product');
            $this->truncateTable('catalog_category');

            $categories = $this->apiClient->getCategories();
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

            $products = $this->apiClient->getProducts();
            $productCount = $this->batchInsert(
                'catalog_product',
                $products,
                static function (array $prod): array {
                    $name = PrestaShopApiClient::extractTranslations($prod['name'] ?? '', '1');

                    return [
                        'id' => (int) $prod['id'],
                        'name' => \is_string($name) ? $name : '',
                        'reference' => isset($prod['reference']) && \is_string($prod['reference']) ? $prod['reference'] : null,
                        'ean13' => isset($prod['ean13']) && \is_string($prod['ean13']) ? $prod['ean13'] : null,
                        'price' => (float) ($prod['price'] ?? 0),
                        'id_category_default' => isset($prod['id_category_default']) ? (int) $prod['id_category_default'] : null,
                        'active' => (int) ($prod['active'] ?? 1),
                    ];
                }
            );

            $combinations = $this->apiClient->getCombinations();
            $combinationCount = $this->batchInsert(
                'catalog_combination',
                $combinations,
                static function (array $comb): array {
                    return [
                        'id' => (int) $comb['id'],
                        'id_product' => (int) ($comb['id_product'] ?? 0),
                        'reference' => isset($comb['reference']) && \is_string($comb['reference']) ? $comb['reference'] : null,
                        'ean13' => isset($comb['ean13']) && \is_string($comb['ean13']) ? $comb['ean13'] : null,
                        'price_impact' => (float) ($comb['price'] ?? 0),
                    ];
                }
            );

            $stockAvailables = $this->apiClient->getStockAvailables();
            $stockCount = $this->batchInsert(
                'catalog_stock',
                $stockAvailables,
                static function (array $stock): array {
                    return [
                        'id_product' => (int) ($stock['id_product'] ?? 0),
                        'id_product_attribute' => (int) ($stock['id_product_attribute'] ?? 0),
                        'quantity' => (int) ($stock['quantity'] ?? 0),
                    ];
                }
            );

            $this->connection->commit();

            return [
                'products' => $productCount,
                'combinations' => $combinationCount,
                'stock' => $stockCount,
                'categories' => $categoryCount,
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
                    ];
                }
            );

            $this->connection->commit();

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
