<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Catalog;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Catalog\ProductSearchService;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * @group integration
 */
final class ProductSearchServiceMySqlIntegrationTest extends TestCase
{
    private const CAT_ACTIVE_ID = 991101;
    private const CAT_INACTIVE_ID = 991102;
    private const PRODUCT_NAME_ID = 992001;
    private const PRODUCT_REFERENCE_ID = 992002;
    private const PRODUCT_EAN_ID = 992003;
    private const COMBINATION_ID = 993001;

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();

        if ($this->isMySqlAvailable()) {
            $this->cleanupFixtures();
            $this->seedFixtures();
        }
    }

    protected function tearDown(): void
    {
        if ($this->isMySqlAvailable()) {
            $this->cleanupFixtures();
        }

        $this->connection = null;
    }

    public function testSearchMatchesByNameReferenceAndEan(): void
    {
        $service = $this->createServiceOrSkip();

        $byName = $service->search('Edge Producto Nombre', null, 20);
        $this->assertContains(self::PRODUCT_NAME_ID, array_column($byName, 'id'));

        $byReference = $service->search('EDGE-REF-001', null, 20);
        $this->assertContains(self::PRODUCT_REFERENCE_ID, array_column($byReference, 'id'));

        $byEan = $service->search('7701234567890', null, 20);
        $this->assertContains(self::PRODUCT_EAN_ID, array_column($byEan, 'id'));
    }

    public function testSearchAppliesCategoryFilterAndReturnsStockJoin(): void
    {
        $service = $this->createServiceOrSkip();

        $results = $service->search(null, self::CAT_ACTIVE_ID, 20);
        $ids = array_column($results, 'id');
        $this->assertContains(self::PRODUCT_NAME_ID, $ids);
        $this->assertContains(self::PRODUCT_REFERENCE_ID, $ids);
        $this->assertContains(self::PRODUCT_EAN_ID, $ids);

        foreach ($results as $row) {
            $this->assertSame(self::CAT_ACTIVE_ID, $row['category_id']);
            $this->assertIsInt($row['stock']);
        }
    }

    public function testPagesWithAnOffsetCoverTheCategoryWithoutRepeats(): void
    {
        // The sale screen shows 50 products and loads the next ones on scroll.
        $service = $this->createServiceOrSkip();
        $all = array_column($service->search(null, self::CAT_ACTIVE_ID, 20), 'id');

        $paged = [];
        for ($offset = 0; $offset < \count($all) + 1; ++$offset) {
            $paged = array_merge($paged, array_column($service->search(null, self::CAT_ACTIVE_ID, 1, $offset), 'id'));
        }

        $this->assertSame($all, $paged);
        $this->assertSame([], $service->search(null, self::CAT_ACTIVE_ID, 20, \count($all)));
    }

    public function testOnlyInStockHidesProductsWithoutStock(): void
    {
        $service = $this->createServiceOrSkip();
        \assert($this->connection !== null);
        $this->connection->executeStatement('UPDATE catalog_stock SET quantity = 0 WHERE id_product = ? AND id_product_attribute = 0', [self::PRODUCT_EAN_ID]);

        $all = array_column($service->search(null, self::CAT_ACTIVE_ID, 20), 'id');
        $inStock = array_column($service->search(null, self::CAT_ACTIVE_ID, 20, 0, true), 'id');

        $this->assertContains(self::PRODUCT_EAN_ID, $all, 'the default keeps showing products without stock');
        $this->assertNotContains(self::PRODUCT_EAN_ID, $inStock);
        $this->assertContains(self::PRODUCT_NAME_ID, $inStock);
        $this->assertContains(self::PRODUCT_REFERENCE_ID, $inStock);
    }

    public function testFindByIdReturnsCombinationAndCombinationStock(): void
    {
        $service = $this->createServiceOrSkip();

        $product = $service->findById(self::PRODUCT_NAME_ID);

        $this->assertNotNull($product);
        $this->assertSame(self::PRODUCT_NAME_ID, $product['id']);
        $this->assertSame(12, $product['stock']);
        $this->assertCount(1, $product['combinations']);
        $this->assertSame(self::COMBINATION_ID, $product['combinations'][0]['id']);
        $this->assertSame(4, $product['combinations'][0]['stock']);
    }

    public function testGetActiveCategoriesExcludesInactiveFixtures(): void
    {
        $service = $this->createServiceOrSkip();

        $categories = $service->getActiveCategories();
        $ids = array_column($categories, 'id');

        $this->assertContains(self::CAT_ACTIVE_ID, $ids);
        $this->assertNotContains(self::CAT_INACTIVE_ID, $ids);
    }

    private function isMySqlAvailable(): bool
    {
        return $this->connection !== null;
    }

    private function createServiceOrSkip(): ProductSearchService
    {
        if (!$this->isMySqlAvailable()) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        return new ProductSearchService($this->connection);
    }

    private function seedFixtures(): void
    {
        $this->connection->insert('catalog_category', [
            'id' => self::CAT_ACTIVE_ID,
            'name' => 'Edge Category Active',
            'id_parent' => 0,
            'active' => 1,
        ]);
        $this->connection->insert('catalog_category', [
            'id' => self::CAT_INACTIVE_ID,
            'name' => 'Edge Category Inactive',
            'id_parent' => 0,
            'active' => 0,
        ]);

        $this->connection->insert('catalog_product', [
            'id' => self::PRODUCT_NAME_ID,
            'name' => 'Edge Producto Nombre',
            'reference' => 'EDGE-NAME-001',
            'ean13' => '1111111111111',
            'price' => 10.5,
            'price_ttc' => 12.71,
            'price_net' => 10.5,
            'id_category_default' => self::CAT_ACTIVE_ID,
            'active' => 1,
        ]);
        $this->connection->insert('catalog_product', [
            'id' => self::PRODUCT_REFERENCE_ID,
            'name' => 'Edge Producto Ref',
            'reference' => 'EDGE-REF-001',
            'ean13' => '2222222222222',
            'price' => 15,
            'price_ttc' => 18.15,
            'price_net' => 15,
            'id_category_default' => self::CAT_ACTIVE_ID,
            'active' => 1,
        ]);
        $this->connection->insert('catalog_product', [
            'id' => self::PRODUCT_EAN_ID,
            'name' => 'Edge Producto Ean',
            'reference' => 'EDGE-EAN-001',
            'ean13' => '7701234567890',
            'price' => 20,
            'price_ttc' => 24.2,
            'price_net' => 20,
            'id_category_default' => self::CAT_ACTIVE_ID,
            'active' => 1,
        ]);

        $this->connection->insert('catalog_stock', [
            'id_product' => self::PRODUCT_NAME_ID,
            'id_product_attribute' => 0,
            'quantity' => 12,
        ]);
        $this->connection->insert('catalog_stock', [
            'id_product' => self::PRODUCT_REFERENCE_ID,
            'id_product_attribute' => 0,
            'quantity' => 2,
        ]);
        $this->connection->insert('catalog_stock', [
            'id_product' => self::PRODUCT_EAN_ID,
            'id_product_attribute' => 0,
            'quantity' => 7,
        ]);

        $this->connection->insert('catalog_combination', [
            'id' => self::COMBINATION_ID,
            'id_product' => self::PRODUCT_NAME_ID,
            'reference' => 'EDGE-COMB-001',
            'ean13' => '9999999999999',
            'price_impact' => 1.5,
            'price_ttc' => 14.52,
            'price_net' => 12,
        ]);
        $this->connection->insert('catalog_combination_attribute', [
            'combination_id' => self::COMBINATION_ID,
            'group_name' => 'Color',
            'attribute_name' => 'Black',
        ]);
        $this->connection->insert('catalog_stock', [
            'id_product' => self::PRODUCT_NAME_ID,
            'id_product_attribute' => self::COMBINATION_ID,
            'quantity' => 4,
        ]);
    }

    private function cleanupFixtures(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM catalog_stock WHERE (id_product = ? AND id_product_attribute = ?) OR (id_product IN (?, ?, ?) AND id_product_attribute = 0)',
            [self::PRODUCT_NAME_ID, self::COMBINATION_ID, self::PRODUCT_NAME_ID, self::PRODUCT_REFERENCE_ID, self::PRODUCT_EAN_ID]
        );
        $this->connection->executeStatement('DELETE FROM catalog_combination_attribute WHERE combination_id = ?', [self::COMBINATION_ID]);
        $this->connection->executeStatement('DELETE FROM catalog_combination WHERE id = ?', [self::COMBINATION_ID]);
        $this->connection->executeStatement('DELETE FROM catalog_product WHERE id IN (?, ?, ?)', [self::PRODUCT_NAME_ID, self::PRODUCT_REFERENCE_ID, self::PRODUCT_EAN_ID]);
        $this->connection->executeStatement('DELETE FROM catalog_category WHERE id IN (?, ?)', [self::CAT_ACTIVE_ID, self::CAT_INACTIVE_ID]);
    }
}
