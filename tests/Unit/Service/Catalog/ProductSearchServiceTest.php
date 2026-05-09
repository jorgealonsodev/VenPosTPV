<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Catalog\ProductSearchService;

class ProductSearchServiceTest extends TestCase
{
    public function testSearchMapsResultTypesAndNullableCategory(): void
    {
        $rows = [[
            'id' => '1',
            'name' => 'Camiseta Básica',
            'reference' => 'CAM-001',
            'ean13' => '1234567890123',
            'price' => '19.95',
            'category_id' => null,
            'stock' => '42',
        ]];

        $service = new ProductSearchService($this->createConnectionWithQueryBuilders([
            $this->createQueryBuilderForRows($rows),
        ]));

        $results = $service->search(null, null, 50);

        $this->assertSame(1, $results[0]['id']);
        $this->assertNull($results[0]['category_id']);
        $this->assertSame(19.95, $results[0]['price']);
        $this->assertSame(42, $results[0]['stock']);
    }

    public function testSearchReturnsEmptyArrayWhenNoRows(): void
    {
        $service = new ProductSearchService($this->createConnectionWithQueryBuilders([
            $this->createQueryBuilderForRows([]),
        ]));

        $this->assertSame([], $service->search(null, null, 50));
    }

    public function testFindByIdReturnsNullForMissingProduct(): void
    {
        $service = new ProductSearchService($this->createConnectionWithQueryBuilders([
            $this->createQueryBuilderForRow(false),
        ]));
        $product = $service->findById(999);

        $this->assertNull($product);
    }

    public function testFindByIdMapsCombinationsAndTypes(): void
    {
        $service = new ProductSearchService($this->createConnectionWithQueryBuilders([
            $this->createQueryBuilderForRow([
                'id' => '1',
                'name' => 'Camiseta Básica',
                'reference' => 'CAM-001',
                'ean13' => '1234567890123',
                'price' => '19.95',
                'category_id' => '2',
                'stock' => '42',
            ]),
            $this->createQueryBuilderForRows([
                [
                    'id' => '10',
                    'reference' => 'CAM-001-RJA',
                    'ean13' => '',
                    'price_impact' => '2.00',
                    'stock' => '5',
                ],
            ]),
        ]));

        $product = $service->findById(1);

        $this->assertNotNull($product);
        $this->assertSame(1, $product['id']);
        $this->assertSame(2, $product['category_id']);
        $this->assertSame(19.95, $product['price']);
        $this->assertSame(42, $product['stock']);
        $this->assertCount(1, $product['combinations']);
        $this->assertSame(10, $product['combinations'][0]['id']);
        $this->assertSame(2.0, $product['combinations'][0]['price_impact']);
        $this->assertSame(5, $product['combinations'][0]['stock']);
    }

    public function testGetActiveCategoriesMapsPrimitiveTypes(): void
    {
        $service = new ProductSearchService($this->createConnectionWithQueryBuilders([
            $this->createQueryBuilderForRows([
                ['id' => '1', 'name' => 'Calzado', 'id_parent' => '0', 'active' => '1'],
            ]),
        ]));

        $categories = $service->getActiveCategories();

        $this->assertSame(1, $categories[0]['id']);
        $this->assertSame(0, $categories[0]['id_parent']);
        $this->assertTrue($categories[0]['active']);
    }

    private function createConnectionWithQueryBuilders(array $queryBuilders): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(count($queryBuilders)))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls(...$queryBuilders);

        return $connection;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function createQueryBuilderForRows(array $rows): QueryBuilder
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        return $this->createQueryBuilderWithResult($result);
    }

    /** @param array<string, mixed>|false $row */
    private function createQueryBuilderForRow(array|false $row): QueryBuilder
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn($row);

        return $this->createQueryBuilderWithResult($result);
    }

    private function createQueryBuilderWithResult(Result $result): QueryBuilder
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('leftJoin')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('executeQuery')->willReturn($result);
        $qb->method('expr')->willReturn($this->createMock(ExpressionBuilder::class));

        return $qb;
    }
}
