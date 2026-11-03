<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Stock;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Catalog\CatalogSyncService;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;
use VenPosTPV\Service\Stock\PendingUploadStock;
use VenPosTPV\Service\Sync\StockPushService;
use VenPosTPV\Service\Sync\SyncLogRepository;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * T8 bug 1: the absolute stock push and the inbound stock sync must not count a sale
 * (or a refund with restock) still waiting in the outbox twice. Live repro: offline
 * sale of 1 unit, push-stock (PS 296 -> 295), order upload (PS 295 -> 294).
 */
final class PendingUploadStockMySqlIntegrationTest extends TestCase
{
    private const PRODUCT = 950101;
    private const OTHER = 950102;
    private const PS_STOCK_ID = 950101;
    private const PS_OTHER_STOCK_ID = 950102;
    private const PREFIX = 't8stock-';

    private ?Connection $connection = null;

    /** @var array<int, int> ps_stock_id => quantity pushed */
    private array $pushed = [];

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();
        $this->pushed = [];
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->clean();
            $this->connection->close();
        }
        $this->connection = null;
    }

    private function clean(): void
    {
        $this->connection->executeStatement('DELETE FROM outbox WHERE request_id LIKE ?', [self::PREFIX . '%']);
        $this->connection->executeStatement('DELETE FROM catalog_stock WHERE id_product IN (?, ?)', [self::PRODUCT, self::OTHER]);
    }

    private function localStock(int $quantity, int $product = self::PRODUCT, int $attribute = 0, int $psStockId = self::PS_STOCK_ID): void
    {
        $this->connection->insert('catalog_stock', ['id_product' => $product, 'id_product_attribute' => $attribute, 'quantity' => $quantity, 'ps_stock_id' => $psStockId]);
    }

    /** @param array<string, mixed> $payload */
    private function outbox(string $type, string $status, array $payload): void
    {
        $this->connection->insert('outbox', [
            'type' => $type,
            'order_id' => null,
            'sequence' => 1,
            'request_id' => self::PREFIX . bin2hex(random_bytes(6)),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => $status,
        ]);
    }

    private function sale(string $status, int $quantity, int $product = self::PRODUCT, int $attribute = 0): void
    {
        // The module's order request as CheckoutService::orderPayload builds it.
        $this->outbox('sale', $status, [
            'products' => [['id_product' => $product, 'id_product_attribute' => $attribute, 'quantity' => $quantity, 'price' => 12.1]],
            'id_customer' => 0, 'id_currency' => 1, 'id_shop' => 1, 'payment_method' => 'Efectivo', 'reference' => 'A26000001',
        ]);
    }

    private function refund(string $type, string $status, int $quantity, bool $restock): void
    {
        // RefundService::moduleLines.
        $this->outbox($type, $status, [
            'request_id' => 'r', 'refund_type' => 'partial', 'reason' => '',
            'lines' => [['id_product' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => $quantity, 'amount' => 12.1, 'restock' => $restock]],
            '_local' => ['refund_id' => 1],
        ]);
    }

    private function push(): void
    {
        $api = $this->createMock(PrestaShopApiClient::class);
        $api->method('updateStockAvailable')->willReturnCallback(function (int $id, int $qty): void {
            $this->pushed[$id] = $qty;
        });
        (new StockPushService($this->connection, $api, $this->createMock(SyncLogRepository::class)))->pushPending();
    }

    /** @param list<array<string, int>> $psStock */
    private function pull(array $psStock): void
    {
        $api = $this->createMock(PrestaShopApiClient::class);
        $api->method('getStockAvailables')->willReturn($psStock);
        (new CatalogSyncService($api, $this->connection, $this->createMock(SyncLogRepository::class)))->syncStock();
    }

    private function quantity(int $product = self::PRODUCT): int
    {
        return (int) $this->connection->fetchOne('SELECT quantity FROM catalog_stock WHERE id_product = ? AND id_product_attribute = 0', [$product]);
    }

    public function testPushWithAPendingSaleSendsWhatPrestaShopShowsBeforeTheUpload(): void
    {
        // PS 296, offline sale of 1: local 295, the order not uploaded yet.
        $this->localStock(295);
        $this->localStock(40, self::OTHER, 0, self::PS_OTHER_STOCK_ID);
        $this->sale('pending', 1);

        $this->push();

        $this->assertSame(296, $this->pushed[self::PS_STOCK_ID], 'the upload lowers PS to 295 itself');
        $this->assertSame(40, $this->pushed[self::PS_OTHER_STOCK_ID], 'stock without pending uploads is pushed as before');
    }

    public function testProcessingAndIncidentSalesStillCountAndDoneSalesDoNot(): void
    {
        $this->localStock(290);
        $this->sale('processing', 2);
        $this->sale('incident', 3);
        $this->sale('done', 4);
        // A row given up for good (a discarded incident) will never lower PrestaShop.
        $this->sale('discarded', 5);

        $this->push();

        $this->assertSame(295, $this->pushed[self::PS_STOCK_ID]);
    }

    public function testPendingRefundAndRestockWithRestockAreTakenOffThePush(): void
    {
        // Local 300 already includes +2 (refund) and +1 (no-ticket restock) not uploaded;
        // a refund line without restock moves nothing.
        $this->localStock(300);
        $this->refund('refund', 'pending', 2, true);
        $this->refund('restock', 'pending', 1, true);
        $this->refund('refund', 'pending', 5, false);
        $this->refund('refund', 'done', 7, true);

        $this->push();

        $this->assertSame(297, $this->pushed[self::PS_STOCK_ID]);
    }

    public function testPullKeepsTheLocalDecrementOfAPendingSale(): void
    {
        $this->localStock(295);
        $this->sale('pending', 1);
        $this->sale('done', 9);

        $this->pull([
            ['id' => self::PS_STOCK_ID, 'id_product' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 296],
            ['id' => self::PS_OTHER_STOCK_ID, 'id_product' => self::OTHER, 'id_product_attribute' => 0, 'quantity' => 40],
        ]);

        $this->assertSame(295, $this->quantity(), 'PS 296 minus the sale still to upload');
        $this->assertSame(40, $this->quantity(self::OTHER), 'stock without pending uploads is copied as before');
    }

    public function testFullCatalogSyncAlsoKeepsTheLocalDecrementOfAPendingSale(): void
    {
        $this->localStock(295);
        $this->sale('pending', 1);
        $api = $this->createMock(PrestaShopApiClient::class);
        $api->method('getStockAvailables')->willReturn([
            ['id' => self::PS_STOCK_ID, 'id_product' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 296],
        ]);

        (new CatalogSyncService($api, $this->connection, $this->createMock(SyncLogRepository::class)))->syncAll();

        $this->assertSame(295, $this->quantity());
    }

    public function testPullKeepsTheLocalRaiseOfAPendingRefundWithRestock(): void
    {
        $this->localStock(298);
        $this->refund('refund', 'pending', 2, true);

        $this->pull([['id' => self::PS_STOCK_ID, 'id_product' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 296]]);

        $this->assertSame(298, $this->quantity());
    }

    public function testSaleThenPushThenUploadThenPushEndsAtTheRightStock(): void
    {
        // The live repro, end to end: PS 296, sale of 1 offline.
        $ps = 296;
        $this->localStock(295);
        $this->sale('pending', 1);

        $this->push();
        $ps = $this->pushed[self::PS_STOCK_ID];
        // The module creates the order: PS lowers its own stock; the row is done.
        --$ps;
        $this->connection->executeStatement("UPDATE outbox SET status = 'done' WHERE request_id LIKE ?", [self::PREFIX . '%']);
        $this->push();

        $this->assertSame(295, $ps);
        $this->assertSame(295, $this->pushed[self::PS_STOCK_ID]);
    }

    public function testOwedIsPerProductAndAttribute(): void
    {
        $this->sale('pending', 2, self::PRODUCT, 7);
        $this->sale('pending', 1, self::PRODUCT, 0);
        $this->refund('refund', 'pending', 1, true);
        $this->outbox('customer', 'pending', ['firstname' => 'Ana', 'lastname' => 'Pérez', 'email' => 'a@example.com']);

        $owed = (new PendingUploadStock($this->connection))->owedByPendingUploads();

        $this->assertSame(2, $owed[PendingUploadStock::key(self::PRODUCT, 7)]);
        $this->assertArrayNotHasKey(PendingUploadStock::key(self::PRODUCT, 0), $owed, 'a sale of 1 and a restock of 1 cancel out');
    }
}
