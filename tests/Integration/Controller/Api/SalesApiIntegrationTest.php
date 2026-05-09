<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Controller\Api;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use VenPosTPV\Controller\Api\CartController;
use VenPosTPV\Controller\Api\CashCloseController;
use VenPosTPV\Controller\Api\CashCurrentController;
use VenPosTPV\Controller\Api\CashMovementController;
use VenPosTPV\Controller\Api\CashOpenController;
use VenPosTPV\Controller\Api\CashReportXController;
use VenPosTPV\Controller\Api\CheckoutController;
use VenPosTPV\Controller\Api\ProductController;
use VenPosTPV\Service\Cart\CartService;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\TerminalService;
use VenPosTPV\Service\Catalog\ProductSearchService;
use VenPosTPV\Service\Checkout\CheckoutService;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Print\PrinterService;

/**
 * @group integration
 */
class SalesApiIntegrationTest extends TestCase
{
    private ?\Doctrine\DBAL\Connection $connection = null;

    protected function setUp(): void
    {
        $config = [
            'driver' => 'pdo_mysql',
            'host' => $_ENV['TPV_DB_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['TPV_DB_PORT'] ?? 3306),
            'dbname' => $_ENV['TPV_DB_NAME'] ?? 'tpv',
            'user' => $_ENV['TPV_DB_USER'] ?? 'tpv_user',
            'password' => $_ENV['TPV_DB_PASSWORD'] ?? '',
            'charset' => 'utf8mb4',
        ];

        try {
            $conn = DriverManager::getConnection($config);
            $conn->executeQuery('SELECT 1');
            $this->connection = $conn;
        } catch (\Throwable) {
            $this->connection = null;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        session_start();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function testCatalogSearchRequiresAuthentication(): void
    {
        if ($this->connection === null || !$this->connection->getDatabasePlatform() instanceof MySQLPlatform) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        $service = new ProductSearchService($this->connection);
        $controller = new ProductController($service);
        $request = new Request(['q' => 'test']);
        $response = $controller->search($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testCatalogSearchReturnsProductsWithSession(): void
    {
        if ($this->connection === null || !$this->connection->getDatabasePlatform() instanceof MySQLPlatform) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        $_SESSION['user_id'] = 1;

        $service = new ProductSearchService($this->connection);
        $controller = new ProductController($service);
        $request = new Request(['q' => 'Hummingbird']);
        $response = $controller->search($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('data', $data);
    }

    public function testCategoriesReturnsActiveCategoriesWithSession(): void
    {
        if ($this->connection === null || !$this->connection->getDatabasePlatform() instanceof MySQLPlatform) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        $_SESSION['user_id'] = 1;

        $service = new ProductSearchService($this->connection);
        $controller = new ProductController($service);
        $response = $controller->categories();

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('data', $data);
    }

    public function testCartCrudFlow(): void
    {
        if ($this->connection === null || !$this->connection->getDatabasePlatform() instanceof MySQLPlatform) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        $_SESSION['user_id'] = 1;

        $productSearch = new ProductSearchService($this->connection);
        $cartService = new CartService();
        $controller = new CartController($cartService, $productSearch);

        // 1. Get empty cart
        $response = $controller->get();
        $this->assertSame(200, $response->getStatusCode());

        // 2. Clear cart (idempotent)
        $response = $controller->clear();
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertEmpty($data['data']['lines']);

        // 3. Apply discount
        $request = new Request([], [], [], [], [], [], json_encode(['type' => 'pct', 'value' => 10]));
        $response = $controller->applyDiscount($request);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCartAddFailsWithoutSession(): void
    {
        if ($this->connection === null || !$this->connection->getDatabasePlatform() instanceof MySQLPlatform) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        $productSearch = new ProductSearchService($this->connection);
        $cartService = new CartService();
        $controller = new CartController($cartService, $productSearch);

        $request = new Request([], [], [], [], [], [], json_encode(['product_id' => 1]));
        $response = $controller->addItem($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testCashSessionEndToEndFlowOpenCheckoutMovementReportCurrentClose(): void
    {
        if ($this->connection === null || !$this->connection->getDatabasePlatform() instanceof MySQLPlatform) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        $shopId = 91001;
        $userId = 91001;
        $productId = 991001;

        $_SESSION['user_id'] = $userId;
        $_SESSION['shop_id'] = $shopId;

        $this->connection->executeStatement('DELETE FROM catalog_stock WHERE id_product = ?', [$productId]);
        $this->connection->insert('catalog_stock', [
            'id_product' => $productId,
            'id_product_attribute' => 0,
            'quantity' => 10,
        ]);

        $terminalService = new TerminalService($this->connection);
        $cashService = new CashSessionService($this->connection, $terminalService);

        $openResponse = (new CashOpenController($cashService))(
            new Request([], [], [], [], [], [], json_encode(['opening_cash' => 100.00], JSON_THROW_ON_ERROR))
        );
        $this->assertSame(200, $openResponse->getStatusCode());

        $psClient = $this->createMock(PsOrderClient::class);
        $psClient->method('createOrder')->willReturn([
            'order_id' => 500001,
            'reference' => 'INT-500001',
            'total_paid' => 30.00,
        ]);

        $checkoutService = new CheckoutService(new CartService(), $psClient, $this->connection, $cashService, $this->createMock(MessageBusInterface::class), $this->createMock(PrinterService::class));
        $checkoutController = new CheckoutController($checkoutService, $cashService);
        $checkoutResponse = $checkoutController->checkout(
            new Request([], [], [], [], [], [], json_encode([
                'payment_method' => 'cash',
                'amount_tendered' => 40.00,
                'cart_data' => [
                    'lines' => [[
                        'product_id' => $productId,
                        'qty' => 1,
                        'price' => 30.00,
                    ]],
                    'total' => 30.00,
                ],
            ], JSON_THROW_ON_ERROR))
        );
        $this->assertSame(200, $checkoutResponse->getStatusCode());

        $movementResponse = (new CashMovementController($cashService))(
            new Request([], [], [], [], [], [], json_encode([
                'type' => 'cash_out',
                'amount' => 10.00,
                'concept' => 'Petty cash',
            ], JSON_THROW_ON_ERROR))
        );
        $this->assertSame(200, $movementResponse->getStatusCode());

        $reportResponse = (new CashReportXController($cashService))();
        $this->assertSame(200, $reportResponse->getStatusCode());
        $report = json_decode((string) $reportResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(100.0, $report['data']['opening_cash']);
        $this->assertSame(30.0, $report['data']['sales_cash']);
        $this->assertSame(10.0, $report['data']['cash_out_total']);
        $this->assertSame(120.0, $report['data']['expected_cash']);

        $currentResponse = (new CashCurrentController($cashService))();
        $this->assertSame(200, $currentResponse->getStatusCode());
        $current = json_decode((string) $currentResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($current['data']['open']);
        $this->assertSame(120.0, $current['data']['expected_cash']);

        $closeResponse = (new CashCloseController($cashService))(
            new Request([], [], [], [], [], [], json_encode(['counted_cash' => 118.00], JSON_THROW_ON_ERROR))
        );
        $this->assertSame(200, $closeResponse->getStatusCode());
        $close = json_decode((string) $closeResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(120.0, $close['data']['expected_cash']);
        $this->assertSame(118.0, $close['data']['counted_cash']);
        $this->assertSame(-2.0, $close['data']['difference']);
    }
}
