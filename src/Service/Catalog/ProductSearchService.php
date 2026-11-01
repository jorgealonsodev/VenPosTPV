<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Catalog;

use Doctrine\DBAL\Connection;

class ProductSearchService
{
    public const PRICE_BASIS_TTC = 'ttc';
    public const PRICE_BASIS_BASE = 'base';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Projects the catalog read model the POS grid consumes.
     *
     * `price` is the tax-included price when the sync resolved it, because that is
     * what PrestaShop charges; `price_basis` tells which basis was used. The
     * tax-excluded base price is kept as `base_price` for callers that need it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(?string $query, ?int $categoryId, int $limit = 50): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select([
                'p.id',
                'p.name',
                'p.reference',
                'p.ean13',
                'p.price',
                'p.price_ttc',
                'p.price_net',
                'p.id_category_default AS category_id',
                'p.image_url',
                'COALESCE(s.quantity, 0) AS stock',
                '(SELECT COUNT(*) FROM catalog_combination cc WHERE cc.id_product = p.id) AS has_combinations',
            ])
            ->from('catalog_product', 'p')
            ->leftJoin('p', 'catalog_stock', 's', 's.id_product = p.id AND s.id_product_attribute = 0')
            ->where('p.active = 1')
            ->setMaxResults($limit);

        if ($query !== null && $query !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('p.name', ':q'),
                    $qb->expr()->like('p.reference', ':q'),
                    $qb->expr()->like('p.ean13', ':q'),
                )
            )->setParameter('q', '%' . $query . '%');
        }

        if ($categoryId !== null && $categoryId > 0) {
            $qb->andWhere('p.id_category_default = :cat')
                ->setParameter('cat', $categoryId);
        }

        $qb->orderBy('p.name', 'ASC');

        $results = $qb->executeQuery()->fetchAllAssociative();

        return array_map(function (array $row): array {
            $row = $this->applyPriceBasis($row);
            $row['id'] = (int) $row['id'];
            $row['category_id'] = $row['category_id'] !== null ? (int) $row['category_id'] : null;
            $row['stock'] = (int) $row['stock'];
            $row['has_combinations'] = (bool) ($row['has_combinations'] ?? false);

            return $row;
        }, $results);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select([
                'p.id',
                'p.name',
                'p.reference',
                'p.ean13',
                'p.price',
                'p.price_ttc',
                'p.price_net',
                'p.id_category_default AS category_id',
                'p.image_url',
                'COALESCE(s.quantity, 0) AS stock',
            ])
            ->from('catalog_product', 'p')
            ->leftJoin('p', 'catalog_stock', 's', 's.id_product = p.id AND s.id_product_attribute = 0')
            ->where('p.id = :id')
            ->setParameter('id', $id);

        $row = $qb->executeQuery()->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $row = $this->applyPriceBasis($row);
        $row['id'] = (int) $row['id'];
        $row['category_id'] = $row['category_id'] !== null ? (int) $row['category_id'] : null;
        $row['stock'] = (int) $row['stock'];

        $productPrice = (float) $row['price'];
        $productTtcPrice = $row['price_ttc'];
        $productBasePrice = (float) $row['base_price'];

        // Load combinations
        $combinations = $this->connection->createQueryBuilder()
            ->select([
                'c.id',
                'c.reference',
                'c.ean13',
                'c.price_impact',
                'c.price_ttc',
                'c.price_net',
                'COALESCE(cs.quantity, 0) AS stock',
            ])
            ->from('catalog_combination', 'c')
            ->leftJoin('c', 'catalog_stock', 'cs', 'cs.id_product = c.id_product AND cs.id_product_attribute = c.id')
            ->where('c.id_product = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAllAssociative();

        $row['combinations'] = array_map(function (array $c) use ($productPrice, $productTtcPrice, $productBasePrice): array {
            $c['id'] = (int) $c['id'];
            $c['stock'] = (int) $c['stock'];
            $c = $this->applyCombinationPriceBasis($c, $productPrice, $productTtcPrice, $productBasePrice);

            // Load attributes for this combination
            $attributes = $this->connection->createQueryBuilder()
                ->select(['group_name', 'attribute_name'])
                ->from('catalog_combination_attribute')
                ->where('combination_id = :combinationId')
                ->setParameter('combinationId', $c['id'])
                ->orderBy('group_name', 'ASC')
                ->addOrderBy('attribute_name', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();

            $c['attributes'] = $attributes;

            return $c;
        }, $combinations);

        return $row;
    }

    /**
     * Exposes the tax-included price as `price` while keeping the tax-excluded
     * value in `base_price`, and records which basis was used.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function applyPriceBasis(array $row): array
    {
        $basePrice = isset($row['price']) ? (float) $row['price'] : 0.0;
        $ttcPrice = isset($row['price_ttc']) && is_numeric($row['price_ttc']) ? (float) $row['price_ttc'] : null;

        $row['price'] = $ttcPrice ?? $basePrice;
        $row['base_price'] = $basePrice;
        $row['price_ttc'] = $ttcPrice;
        $row['net_price'] = isset($row['price_net']) && is_numeric($row['price_net']) ? (float) $row['price_net'] : null;
        $row['price_basis'] = $ttcPrice !== null ? self::PRICE_BASIS_TTC : self::PRICE_BASIS_BASE;

        return $row;
    }

    /**
     * Projects a combination as an impact relative to the exposed product price.
     *
     * `catalog_combination.price_ttc` is the absolute TTC price of the combination,
     * so the impact the UI adds to the product price is the difference between the
     * two TTC prices. The raw base impact is kept whenever either TTC price is
     * missing, matching the pre-TTC read model.
     *
     * @param array<string, mixed> $combination
     *
     * @return array<string, mixed>
     */
    /**
     * Projects a combination so that the arithmetic every consumer already uses
     * (`product.price + combination.price_impact`) always yields exactly the unit
     * price the checkout resolves for that combination.
     *
     * When the product and the combination both have a tax-included price, the
     * impact is the difference between them. When only the product does, the
     * checkout falls back to the tax-excluded basis for this combination, so the
     * impact is compensated to reach that amount; without the compensation the
     * projected sum would be rejected at the register as a price mismatch and the
     * combination would be unsellable until the next sync.
     *
     * @param array<string, mixed> $combination
     *
     * @return array<string, mixed>
     */
    private function applyCombinationPriceBasis(
        array $combination,
        float $productPrice,
        ?float $productTtcPrice,
        float $productBasePrice,
    ): array {
        $baseImpact = isset($combination['price_impact']) ? (float) $combination['price_impact'] : 0.0;
        $combinationTtcPrice = isset($combination['price_ttc']) && is_numeric($combination['price_ttc'])
            ? (float) $combination['price_ttc']
            : null;

        if ($productTtcPrice !== null && $combinationTtcPrice !== null) {
            $combination['price_impact'] = round($combinationTtcPrice - $productTtcPrice, 2);
            $combination['price_basis'] = self::PRICE_BASIS_TTC;
        } elseif ($productTtcPrice !== null) {
            $combination['price_impact'] = round(($productBasePrice + $baseImpact) - $productTtcPrice, 2);
            $combination['price_basis'] = self::PRICE_BASIS_BASE;
        } else {
            $combination['price_impact'] = $baseImpact;
            $combination['price_basis'] = self::PRICE_BASIS_BASE;
        }

        $combination['base_price_impact'] = $baseImpact;
        $combination['price_ttc'] = $combinationTtcPrice;
        $combination['net_price'] = isset($combination['price_net']) && is_numeric($combination['price_net'])
            ? (float) $combination['price_net']
            : null;

        return $combination;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getActiveCategories(): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select(['id', 'name', 'id_parent', 'active'])
            ->from('catalog_category')
            ->where('active = 1')
            ->orderBy('name', 'ASC');

        $results = $qb->executeQuery()->fetchAllAssociative();

        return array_map(function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['id_parent'] = (int) $row['id_parent'];
            $row['active'] = (bool) $row['active'];

            return $row;
        }, $results);
    }
}
