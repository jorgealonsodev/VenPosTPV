<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Catalog;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Catalog\CatalogEditService;
use VenPosTPV\Service\Catalog\PsPriceClient;
use VenPosTPV\Service\Catalog\Exception\CatalogEditException;
use VenPosTPV\Service\Catalog\ProductSearchService;
use VenPosTPV\Service\Label\LabelBarcode;
use VenPosTPV\Service\Label\LabelCatalog;
use VenPosTPV\Service\PrestaShop\Exception\PsApiException;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * «Modo edición» of the sale screen: local EAN and reference (catalog_override)
 * applied wherever the till reads the catalog, and stock written to PrestaShop
 * first, then locally.
 *
 * @group integration
 */
final class CatalogEditMySqlIntegrationTest extends TestCase
{
    private const SINGLE = 996001;   // a product without combinations
    private const SIZED = 996002;    // a product with one combination
    private const SIZE_M = 996101;
    private const SINGLE_PS_STOCK = 9960011;
    private const SIZE_M_PS_STOCK = 9961011;

    private const SINGLE_EAN = '2995001000013';
    private const SIZE_M_EAN = '2995001000020';
    private const LOCAL_EAN = '2995001000037';
    private const LOCAL_SIZE_EAN = '2995001000044';

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->cleanup();
        $this->seed();
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->cleanup();
            $this->connection->close();
        }
    }

    public function testALocalEanAndReferenceAreShownAndFoundWhileTheSyncedOnesStillFindTheProduct(): void
    {
        $item = $this->service()->saveOverride(self::SINGLE, 0, self::LOCAL_EAN, ' OVR-LOCAL-1 ', 7);

        self::assertSame(['ean13' => self::SINGLE_EAN, 'reference' => 'OVR-SYNC-1'], $item['synced']);
        self::assertSame(self::LOCAL_EAN, $item['local']['ean13']);
        self::assertSame('OVR-LOCAL-1', $item['local']['reference']);
        self::assertSame(['ean13' => self::LOCAL_EAN, 'reference' => 'OVR-LOCAL-1'], $item['effective']);

        $search = new ProductSearchService($this->db());
        $found = $this->row($search->search(self::LOCAL_EAN, null, 10), self::SINGLE);
        self::assertSame(self::LOCAL_EAN, $found['ean13']);
        self::assertSame('OVR-LOCAL-1', $found['reference']);
        self::assertNotNull($this->row($search->search('OVR-LOCAL-1', null, 10), self::SINGLE));
        self::assertNotNull($this->row($search->search(self::SINGLE_EAN, null, 10), self::SINGLE), 'the synced EAN keeps finding it');
        self::assertSame(self::LOCAL_EAN, $search->findById(self::SINGLE)['ean13']);

        $labels = new LabelCatalog($search, $this->db());
        self::assertSame(['product_id' => self::SINGLE, 'id_product_attribute' => 0], $labels->findByCode(self::LOCAL_EAN));
        self::assertSame(['product_id' => self::SINGLE, 'id_product_attribute' => 0], $labels->findByCode(self::SINGLE_EAN));
        self::assertSame(self::LOCAL_EAN, $labels->label(self::SINGLE, 0)->barcode->data);

        $audit = $this->db()->fetchAssociative('SELECT user_id, payload FROM audit_log WHERE action = ? AND target = ?', [CatalogEditService::ACTION_OVERRIDE_SAVE, self::SINGLE . ':0']);
        self::assertIsArray($audit);
        self::assertSame(7, (int) $audit['user_id']);
        self::assertSame(['ean13' => self::LOCAL_EAN, 'reference' => 'OVR-LOCAL-1'], json_decode((string) $audit['payload'], true)['after']);
    }

    public function testACombinationEanOverrideIsScannedAndPrintedOnItsLabel(): void
    {
        $this->service()->saveOverride(self::SIZED, self::SIZE_M, self::LOCAL_SIZE_EAN, '', 7);

        $search = new ProductSearchService($this->db());
        self::assertNotNull($this->row($search->search(self::LOCAL_SIZE_EAN, null, 10), self::SIZED));
        $combination = $search->findById(self::SIZED)['combinations'][0];
        self::assertSame(self::LOCAL_SIZE_EAN, $combination['ean13']);
        self::assertSame('OVR-SYNC-M', $combination['reference'], 'an empty reference keeps the synced one');

        $labels = new LabelCatalog($search, $this->db());
        self::assertSame(['product_id' => self::SIZED, 'id_product_attribute' => self::SIZE_M], $labels->findByCode(self::LOCAL_SIZE_EAN));
        self::assertSame(['product_id' => self::SIZED, 'id_product_attribute' => self::SIZE_M], $labels->findByCode(self::SIZE_M_EAN));
        $label = $labels->label(self::SIZED, self::SIZE_M);
        self::assertSame(LabelBarcode::EAN13, $label->barcode->type);
        self::assertSame(self::LOCAL_SIZE_EAN, $label->barcode->data);
    }

    public function testALocalEanEqualToAnotherItemsSyncedEanWinsTheScan(): void
    {
        $this->service()->saveOverride(self::SINGLE, 0, self::SIZE_M_EAN, null, 7);

        $labels = new LabelCatalog(new ProductSearchService($this->db()), $this->db());
        self::assertSame(['product_id' => self::SINGLE, 'id_product_attribute' => 0], $labels->findByCode(self::SIZE_M_EAN));
    }

    public function testRestablecerRemovesTheLocalValues(): void
    {
        $service = $this->service();
        $service->saveOverride(self::SINGLE, 0, self::LOCAL_EAN, 'OVR-LOCAL-1', 7);

        $item = $service->resetOverride(self::SINGLE, 0, 7);

        self::assertNull($item['local']);
        self::assertSame(['ean13' => self::SINGLE_EAN, 'reference' => 'OVR-SYNC-1'], $item['effective']);
        self::assertSame(self::SINGLE_EAN, (new ProductSearchService($this->db()))->findById(self::SINGLE)['ean13']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM catalog_override WHERE id_product = ?', [self::SINGLE]));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ? AND target = ?', [CatalogEditService::ACTION_OVERRIDE_RESET, self::SINGLE . ':0']));
    }

    public function testBothValuesEmptyRemoveTheOverride(): void
    {
        $service = $this->service();
        $service->saveOverride(self::SINGLE, 0, self::LOCAL_EAN, null, 7);

        self::assertNull($service->saveOverride(self::SINGLE, 0, '', '  ', 7)['local']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM catalog_override WHERE id_product = ?', [self::SINGLE]));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidEans(): iterable
    {
        yield 'wrong check digit' => ['2995001000014', 'invalid_ean_check_digit'];
        yield '12 digits (UPC-A is not asked for)' => ['299500100001', 'invalid_ean'];
        yield 'letters' => ['ABCDEFGH', 'invalid_ean'];
        yield 'not a string' => [['x'], 'invalid_ean'];
    }

    #[DataProvider('invalidEans')]
    public function testAnInvalidEanIsRefusedAndNothingIsStored(mixed $ean, string $code): void
    {
        try {
            $this->service()->saveOverride(self::SINGLE, 0, $ean, 'X', 7);
            self::fail('accepted');
        } catch (CatalogEditException $e) {
            self::assertSame(422, $e->status);
            self::assertSame($code, $e->errorCode);
        }
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM catalog_override WHERE id_product = ?', [self::SINGLE]));
    }

    public function testAnEan8WithItsCheckDigitIsAccepted(): void
    {
        self::assertSame('96385074', $this->service()->saveOverride(self::SINGLE, 0, '96385074', null, 7)['local']['ean13']);
    }

    public function testAnUnknownItemIsNotFound(): void
    {
        $this->expectExceptionObject(CatalogEditException::notFound());
        $this->service()->saveOverride(self::SIZED, 999999, self::LOCAL_EAN, null, 7);
    }

    public function testANewStockGoesToPrestaShopThenToTheTillWithAnAuditEntry(): void
    {
        $ps = $this->createMock(PrestaShopApiClient::class);
        $ps->expects(self::once())->method('updateStockAvailable')->with(self::SIZE_M_PS_STOCK, 10);

        $item = $this->service($ps)->setStock(self::SIZED, self::SIZE_M, '10', 7, 3, '10.0.0.1');

        self::assertSame(10, $item['stock']['quantity']);
        self::assertSame(10, $this->stock(self::SIZED, self::SIZE_M));
        self::assertSame(10, $this->stock(self::SIZED, 0), 'the product total is the sum of its combinations, as PrestaShop recomputes it');
        $audit = $this->db()->fetchAssociative('SELECT user_id, terminal_id, ip, payload FROM audit_log WHERE action = ? AND target = ?', [CatalogEditService::ACTION_STOCK, self::SIZED . ':' . self::SIZE_M]);
        self::assertIsArray($audit);
        self::assertSame([7, 3, '10.0.0.1'], [(int) $audit['user_id'], (int) $audit['terminal_id'], $audit['ip']]);
        $payload = json_decode((string) $audit['payload'], true);
        self::assertSame([3, 10, 10], [$payload['before'], $payload['after'], $payload['sent_to_prestashop']]);
    }

    public function testWhenPrestaShopFailsTheStockDoesNotChangeAnywhere(): void
    {
        $ps = $this->createMock(PrestaShopApiClient::class);
        $ps->method('updateStockAvailable')->willThrowException(PsApiException::httpError(500, 'boom'));

        try {
            $this->service($ps)->setStock(self::SINGLE, 0, 9, 7);
            self::fail('accepted');
        } catch (CatalogEditException $e) {
            self::assertSame(502, $e->status);
            self::assertSame('prestashop_stock_failed', $e->errorCode);
        }

        self::assertSame(5, $this->stock(self::SINGLE, 0));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ? AND target = ?', [CatalogEditService::ACTION_STOCK, self::SINGLE . ':0']));
    }

    public function testTheStockOfAProductWithCombinationsIsSetPerCombination(): void
    {
        $ps = $this->createMock(PrestaShopApiClient::class);
        $ps->expects(self::never())->method('updateStockAvailable');

        try {
            $this->service($ps)->setStock(self::SIZED, 0, 9, 7);
            self::fail('accepted');
        } catch (CatalogEditException $e) {
            self::assertSame('stock_per_combination', $e->errorCode);
        }
        self::assertFalse($this->service($ps)->item(self::SIZED, 0)['stock']['editable']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidQuantities(): iterable
    {
        yield 'negative' => [-1];
        yield 'decimal' => ['2.5'];
        yield 'text' => ['diez'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidQuantities')]
    public function testAQuantityThatIsNotAWholeNumberFromZeroIsRefused(mixed $quantity): void
    {
        $ps = $this->createMock(PrestaShopApiClient::class);
        $ps->expects(self::never())->method('updateStockAvailable');

        try {
            $this->service($ps)->setStock(self::SINGLE, 0, $quantity, 7);
            self::fail('accepted');
        } catch (CatalogEditException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('invalid_quantity', $e->errorCode);
        }
    }

    private function service(?PrestaShopApiClient $ps = null, ?PsPriceClient $prices = null): CatalogEditService
    {
        return new CatalogEditService($this->db(), $ps ?? $this->createMock(PrestaShopApiClient::class), $prices);
    }

    /** @param list<array{int, int, float, float}> $calls */
    private function priceClient(array &$calls, ?\Throwable $failure = null): PsPriceClient
    {
        $client = $this->createMock(PsPriceClient::class);
        $client->method('setPrice')->willReturnCallback(function (int $p, int $a, float $price, float $rate) use (&$calls, $failure): array {
            $calls[] = [$p, $a, $price, $rate];
            if ($failure !== null) {
                throw $failure;
            }

            return ['price_net' => round($price / (1 + $rate / 100), 6)];
        });

        return $client;
    }

    /** @return array<string, mixed> */
    private function prices(string $table, int $id): array
    {
        $cols = $table === 'catalog_product' ? 'price, price_net, price_ttc' : 'price_impact, price_net, price_ttc';

        return array_map('floatval', (array) $this->db()->fetchAssociative('SELECT ' . $cols . ' FROM ' . $table . ' WHERE id = ?', [$id]));
    }

    public function testThePriceWithVatOfAProductGoesToPrestaShopThenToTheCatalogAndIsAudited(): void
    {
        $calls = [];
        $item = $this->service(null, $this->priceClient($calls))->setPrice(self::SINGLE, 0, '270,00', 21, 7, 2, '10.0.0.1');

        self::assertSame([[self::SINGLE, 0, 270.0, 21.0]], $calls);
        self::assertSame(['price' => 223.140496, 'price_net' => 223.140496, 'price_ttc' => 270.0], $this->prices('catalog_product', self::SINGLE));
        self::assertSame(['price' => 270.0, 'vat_rate' => 21.0], $item['price']);
        $audit = $this->db()->fetchAssociative("SELECT user_id, terminal_id, payload FROM audit_log WHERE action = 'catalog_price_set' AND target = ?", [self::SINGLE . ':0']);
        self::assertSame([7, 2], [(int) $audit['user_id'], (int) $audit['terminal_id']]);
        self::assertEquals(['price' => 12.1, 'vat_rate' => 21], json_decode((string) $audit['payload'], true)['before']);
    }

    public function testACombinationGetsItsOwnPriceAndTheProductRateAppliesToAllItsSizes(): void
    {
        $calls = [];
        // The product costs 20 without VAT; size M at 30,25 with 10 % is 27,50 without: +7,50.
        $item = $this->service(null, $this->priceClient($calls))->setPrice(self::SIZED, self::SIZE_M, 30.25, 10, 7);

        self::assertSame([[self::SIZED, self::SIZE_M, 30.25, 10.0]], $calls);
        self::assertSame(['price_impact' => 7.5, 'price_net' => 27.5, 'price_ttc' => 30.25], $this->prices('catalog_combination', self::SIZE_M));
        self::assertSame(['price' => 20.0, 'price_net' => 20.0, 'price_ttc' => 22.0], $this->prices('catalog_product', self::SIZED), 'the product keeps its price at the new rate');
        self::assertSame(['price' => 30.25, 'vat_rate' => 10.0], $item['price']);
    }

    public function testWhenPrestaShopRefusesThePriceNothingChangesLocally(): void
    {
        $calls = [];
        $service = $this->service(null, $this->priceClient($calls, new CatalogEditException('PrestaShop no ha aceptado el cambio de precio; no se ha cambiado nada', 502, 'prestashop_price_failed')));

        try {
            $service->setPrice(self::SINGLE, 0, 99, 21, 7);
            self::fail('A refused price must not change the catalog');
        } catch (CatalogEditException $e) {
            self::assertSame(502, $e->status);
        }
        self::assertSame(['price' => 10.0, 'price_net' => 10.0, 'price_ttc' => 12.1], $this->prices('catalog_product', self::SINGLE));
        self::assertFalse($this->db()->fetchOne("SELECT 1 FROM audit_log WHERE action = 'catalog_price_set' AND target = ?", [self::SINGLE . ':0']));
    }

    /** @return iterable<string, array{mixed, mixed, string}> */
    public static function invalidPrices(): iterable
    {
        yield 'negative' => ['-1', 21, 'invalid_price'];
        yield 'three decimals' => ['1.234', 21, 'invalid_price'];
        yield 'text' => ['abc', 21, 'invalid_price'];
        yield 'too high' => ['100000', 21, 'invalid_price'];
        yield 'unknown rate' => ['10', 7, 'invalid_vat_rate'];
        yield 'no rate' => ['10', null, 'invalid_vat_rate'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPrices')]
    public function testAnInvalidPriceOrRateIsRefusedBeforeAskingPrestaShop(mixed $price, mixed $rate, string $code): void
    {
        $calls = [];
        try {
            $this->service(null, $this->priceClient($calls))->setPrice(self::SINGLE, 0, $price, $rate, 7);
            self::fail('Must be refused');
        } catch (CatalogEditException $e) {
            self::assertSame([422, $code], [$e->status, $e->errorCode]);
        }
        self::assertSame([], $calls);
    }

    public function testAProductWithoutVatShowsARateOfZero(): void
    {
        $this->db()->update('catalog_product', ['price_ttc' => 270, 'price_net' => 270, 'price' => 270], ['id' => self::SINGLE]);

        self::assertSame(['price' => 270.0, 'vat_rate' => 0.0], $this->service()->item(self::SINGLE, 0)['price']);
    }

    private function db(): Connection
    {
        \assert($this->connection !== null);

        return $this->connection;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, mixed>|null
     */
    private function row(array $rows, int $id): ?array
    {
        foreach ($rows as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    private function stock(int $productId, int $attributeId): int
    {
        return (int) $this->db()->fetchOne('SELECT quantity FROM catalog_stock WHERE id_product = ? AND id_product_attribute = ?', [$productId, $attributeId]);
    }

    private function seed(): void
    {
        $db = $this->db();
        $db->insert('catalog_product', ['id' => self::SINGLE, 'name' => 'Override Gorro', 'reference' => 'OVR-SYNC-1', 'ean13' => self::SINGLE_EAN, 'price' => 10, 'price_ttc' => 12.1, 'price_net' => 10, 'active' => 1]);
        $db->insert('catalog_product', ['id' => self::SIZED, 'name' => 'Override Camiseta', 'reference' => 'OVR-SYNC-2', 'ean13' => '', 'price' => 20, 'price_ttc' => 24.2, 'price_net' => 20, 'active' => 1]);
        $db->insert('catalog_combination', ['id' => self::SIZE_M, 'id_product' => self::SIZED, 'reference' => 'OVR-SYNC-M', 'ean13' => self::SIZE_M_EAN, 'price_impact' => 0, 'price_ttc' => 24.2, 'price_net' => 20]);
        $db->insert('catalog_combination_attribute', ['combination_id' => self::SIZE_M, 'group_name' => 'Talla', 'attribute_name' => 'M']);
        $db->insert('catalog_stock', ['id_product' => self::SINGLE, 'id_product_attribute' => 0, 'quantity' => 5, 'ps_stock_id' => self::SINGLE_PS_STOCK]);
        $db->insert('catalog_stock', ['id_product' => self::SIZED, 'id_product_attribute' => 0, 'quantity' => 7, 'ps_stock_id' => 9960021]);
        $db->insert('catalog_stock', ['id_product' => self::SIZED, 'id_product_attribute' => self::SIZE_M, 'quantity' => 3, 'ps_stock_id' => self::SIZE_M_PS_STOCK]);
    }

    private function cleanup(): void
    {
        $db = $this->db();
        $ids = [self::SINGLE, self::SIZED];
        $db->executeStatement('DELETE FROM catalog_override WHERE id_product IN (?, ?)', $ids);
        $db->executeStatement('DELETE FROM catalog_stock WHERE id_product IN (?, ?)', $ids);
        $db->executeStatement('DELETE FROM catalog_combination_attribute WHERE combination_id = ?', [self::SIZE_M]);
        $db->executeStatement('DELETE FROM catalog_combination WHERE id_product IN (?, ?)', $ids);
        $db->executeStatement('DELETE FROM catalog_product WHERE id IN (?, ?)', $ids);
        $db->executeStatement("DELETE FROM audit_log WHERE action LIKE 'catalog\\_%' AND (target LIKE '996001:%' OR target LIKE '996002:%')");
    }
}
