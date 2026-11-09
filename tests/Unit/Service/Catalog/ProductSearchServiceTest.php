<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\TableNotFoundException;
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
        $this->assertSame('base', $results[0]['price_basis']);
    }

    public function testSearchProjectsTtcPriceAndBasisMarker(): void
    {
        $rows = [[
            'id' => '1',
            'name' => 'Producto IVA',
            'reference' => 'IVA-001',
            'ean13' => '1234567890123',
            'price' => '23.90',
            'price_ttc' => '23.14',
            'category_id' => '2',
            'stock' => '7',
            'has_combinations' => '0',
        ]];

        $service = new ProductSearchService($this->createConnectionWithQueryBuilders([
            $this->createQueryBuilderForRows($rows),
        ]));

        $results = $service->search(null, null, 50);

        $this->assertSame(23.14, $results[0]['price']);
        $this->assertSame(23.14, $results[0]['price_ttc']);
        $this->assertSame('ttc', $results[0]['price_basis']);
        $this->assertSame(7, $results[0]['stock']);
    }

    public function testSearchKeepsBasePriceWhenTtcPriceIsNull(): void
    {
        $rows = [[
            'id' => '2',
            'name' => 'Producto sin IVA sincronizado',
            'reference' => 'NOP-002',
            'ean13' => '',
            'price' => '23.90',
            'price_ttc' => null,
            'category_id' => '2',
            'stock' => '3',
            'has_combinations' => '0',
        ]];

        $service = new ProductSearchService($this->createConnectionWithQueryBuilders([
            $this->createQueryBuilderForRows($rows),
        ]));

        $results = $service->search(null, null, 50);

        $this->assertSame(23.90, $results[0]['price']);
        $this->assertNull($results[0]['price_ttc']);
        $this->assertSame('base', $results[0]['price_basis']);
    }

    public function testSearchReturnsEmptyArrayWhenNoRows(): void
    {
        $service = new ProductSearchService($this->createConnectionWithQueryBuilders([
            $this->createQueryBuilderForRows([]),
        ]));

        $this->assertSame([], $service->search(null, null, 50));
    }

public function testSearchExposesTheTaxExcludedReducedPrice(): void
    {
        $service = new ProductSearchService($this->createConnectionWithQueryBuilders([
            $this->createQueryBuilderForRows([
                [
                    'id' => '1',
                    'name' => 'Camiseta',
                    'reference' => 'CAM-001',
                    'ean13' => '',
                    'price' => '23.90',
                    'price_ttc' => '23.14',
                    'price_net' => '19.12',
                    'category_id' => '2',
                    'stock' => '4',
                    'has_combinations' => '0',
                ],
            ]),
        ]));

        $results = $service->search(null, null, 10);

        $this->assertSame(23.14, $results[0]['price']);
        $this->assertSame(19.12, $results[0]['net_price'], 'VAT is price - net_price, so the net price must be projected');
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
            $this->createAttributeQueryBuilder(10, [
                ['group_name' => 'Color', 'attribute_name' => 'Rojo'],
            ]),
        ]));

        $product = $service->findById(1);

        $this->assertNotNull($product);
        $this->assertSame(1, $product['id']);
        $this->assertSame(2, $product['category_id']);
        $this->assertSame(19.95, $product['price']);
        $this->assertSame(42, $product['stock']);
        $this->assertSame('base', $product['price_basis']);
        $this->assertCount(1, $product['combinations']);
        $this->assertSame(10, $product['combinations'][0]['id']);
        $this->assertSame(2.0, $product['combinations'][0]['price_impact']);
        $this->assertSame('base', $product['combinations'][0]['price_basis']);
        $this->assertSame(5, $product['combinations'][0]['stock']);
        $this->assertSame([
            ['group_name' => 'Color', 'attribute_name' => 'Rojo'],
        ], $product['combinations'][0]['attributes']);
    }

    public function testFindByIdProjectsTtcPriceAndCombinationImpactFromTtc(): void
    {
        $service = new ProductSearchService($this->createConnectionWithQueryBuilders([
            $this->createQueryBuilderForRow([
                'id' => '1',
                'name' => 'Producto IVA',
                'reference' => 'IVA-001',
                'ean13' => '',
                'price' => '23.90',
                'price_ttc' => '23.14',
                'category_id' => '2',
                'stock' => '42',
            ]),
            $this->createQueryBuilderForRows([
                [
                    'id' => '10',
                    'reference' => 'IVA-001-GDE',
                    'ean13' => '',
                    'price_impact' => '0.76',
                    'price_ttc' => '25.50',
                    'stock' => '5',
                ],
            ]),
            $this->createAttributeQueryBuilder(10, []),
        ]));

        $product = $service->findById(1);

        $this->assertNotNull($product);
        $this->assertSame(23.14, $product['price']);
        $this->assertSame('ttc', $product['price_basis']);
        $this->assertSame(2.36, $product['combinations'][0]['price_impact']);
        $this->assertSame('ttc', $product['combinations'][0]['price_basis']);
        // The UI contract: product price + combination impact is the TTC unit price.
        $this->assertSame(25.50, $product['price'] + $product['combinations'][0]['price_impact']);
    }

    public function testMixedBasisKeepsTheProjectedSumEqualToTheAmountTheCheckoutResolves(): void
    {
        $service = new ProductSearchService($this->createConnectionWithQueryBuilders([
            $this->createQueryBuilderForRow([
                'id' => '1',
                'name' => 'Producto parcialmente sincronizado',
                'reference' => 'PAR-001',
                'ean13' => '',
                'price' => '23.90',
                'price_ttc' => '23.14',
                'category_id' => '2',
                'stock' => '42',
            ]),
            $this->createQueryBuilderForRows([
                [
                    'id' => '10',
                    'reference' => 'PAR-001-GDE',
                    'ean13' => '',
                    'price_impact' => '0.76',
                    'price_ttc' => null,
                    'stock' => '5',
                ],
            ]),
            $this->createAttributeQueryBuilder(10, []),
        ]));

        $product = $service->findById(1);

        $this->assertNotNull($product);
        $this->assertSame(23.14, $product['price']);
        $this->assertSame('ttc', $product['price_basis']);
        $this->assertSame(23.90, $product['base_price']);

        $combination = $product['combinations'][0];
        $this->assertNull($combination['price_ttc']);
        $this->assertSame('base', $combination['price_basis']);
        $this->assertSame(0.76, $combination['base_price_impact']);

        // The checkout cannot read a TTC price for this combination, so it falls
        // back to the base product price plus the base impact. The projected impact
        // must compensate the difference, otherwise the sum CatalogPanel sends (and
        // every other consumer computes) would be rejected as a price mismatch and
        // the combination would be unsellable until the next sync.
        $checkoutAmount = round($product['base_price'] + $combination['base_price_impact'], 2);
        $this->assertSame(24.66, $checkoutAmount);
        $this->assertSame(
            $checkoutAmount,
            round($product['price'] + $combination['price_impact'], 2),
            'the projected sum must equal the amount the checkout resolves'
        );
    }

    public function testFindByIdKeepsBaseImpactWhenCombinationTtcPriceIsMissing(): void
    {
        $service = new ProductSearchService($this->createConnectionWithQueryBuilders([
            $this->createQueryBuilderForRow([
                'id' => '1',
                'name' => 'Producto sin IVA sincronizado',
                'reference' => 'NOP-001',
                'ean13' => '',
                'price' => '23.90',
                'price_ttc' => null,
                'category_id' => '2',
                'stock' => '42',
            ]),
            $this->createQueryBuilderForRows([
                [
                    'id' => '10',
                    'reference' => 'NOP-001-GDE',
                    'ean13' => '',
                    'price_impact' => '0.76',
                    'price_ttc' => null,
                    'stock' => '5',
                ],
            ]),
            $this->createAttributeQueryBuilder(10, []),
        ]));

        $product = $service->findById(1);

        $this->assertNotNull($product);
        $this->assertSame(23.90, $product['price']);
        $this->assertSame('base', $product['price_basis']);
        $this->assertSame(0.76, $product['combinations'][0]['price_impact']);
        $this->assertSame('base', $product['combinations'][0]['price_basis']);
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

    /** @param array<int, array{group_name: string, attribute_name: string}> $rows */
    private function createAttributeQueryBuilder(int $combinationId, array $rows): QueryBuilder
    {
        $result = $this->createMock(Result::class);
        $result->expects($this->once())->method('fetchAllAssociative')->willReturn($rows);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects($this->once())
            ->method('select')
            ->with(['group_name', 'attribute_name', 'color'])
            ->willReturnSelf();
        $qb->expects($this->once())
            ->method('from')
            ->with('catalog_combination_attribute')
            ->willReturnSelf();
        $qb->expects($this->once())
            ->method('where')
            ->with('combination_id = :combinationId')
            ->willReturnSelf();
        $qb->expects($this->once())
            ->method('setParameter')
            ->with('combinationId', $combinationId)
            ->willReturnSelf();
        $qb->expects($this->once())
            ->method('orderBy')
            ->with('group_name', 'ASC')
            ->willReturnSelf();
        $qb->expects($this->once())
            ->method('addOrderBy')
            ->with('attribute_name', 'ASC')
            ->willReturnSelf();
        $qb->expects($this->once())->method('executeQuery')->willReturn($result);

        return $qb;
    }

    /**
     * Before Version00000050 there is no catalog_override table: the search runs
     * again without the local overrides, and later reads skip them at once.
     */
    public function testWithoutTheOverrideTableTheSyncedCatalogIsStillSearched(): void
    {
        $missing = $this->createMock(QueryBuilder::class);
        foreach (['select', 'from', 'leftJoin', 'where', 'andWhere', 'setParameter', 'setMaxResults', 'orderBy'] as $method) {
            $missing->method($method)->willReturnSelf();
        }
        $missing->method('expr')->willReturn($this->createMock(ExpressionBuilder::class));
        $missing->method('executeQuery')->willThrowException(new TableNotFoundException($this->createMock(DriverException::class), null));
        $rows = [['id' => '1', 'name' => 'Camiseta', 'reference' => 'CAM', 'ean13' => '4006381333931', 'price' => '10', 'category_id' => null, 'stock' => '2']];

        $service = new ProductSearchService($this->createConnectionWithQueryBuilders([
            $missing,
            $this->createQueryBuilderForRows($rows),
            $this->createQueryBuilderForRows($rows),
        ]));

        $this->assertSame('4006381333931', $service->search('4006381333931', null, 50)[0]['ean13']);
        $this->assertSame(1, $service->search(null, null, 50)[0]['id']);
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
