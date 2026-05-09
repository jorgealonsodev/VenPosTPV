<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Catalog;

use Doctrine\DBAL\Connection;

class ProductSearchService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
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
            $row['id'] = (int) $row['id'];
            $row['category_id'] = $row['category_id'] !== null ? (int) $row['category_id'] : null;
            $row['price'] = (float) $row['price'];
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

        $row['id'] = (int) $row['id'];
        $row['category_id'] = $row['category_id'] !== null ? (int) $row['category_id'] : null;
        $row['price'] = (float) $row['price'];
        $row['stock'] = (int) $row['stock'];

        // Load combinations
        $combinations = $this->connection->createQueryBuilder()
            ->select([
                'c.id',
                'c.reference',
                'c.ean13',
                'c.price_impact',
                'COALESCE(cs.quantity, 0) AS stock',
            ])
            ->from('catalog_combination', 'c')
            ->leftJoin('c', 'catalog_stock', 'cs', 'cs.id_product = c.id_product AND cs.id_product_attribute = c.id')
            ->where('c.id_product = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAllAssociative();

        $row['combinations'] = array_map(function (array $c): array {
            $c['id'] = (int) $c['id'];
            $c['price_impact'] = (float) $c['price_impact'];
            $c['stock'] = (int) $c['stock'];

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
