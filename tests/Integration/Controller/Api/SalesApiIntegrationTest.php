<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
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
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Service\Sync\Outbox;
use VenPosTPV\Service\Sync\OutboxSyncTrigger;
use VenPosTPV\Tests\Support\SessionRequest;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * @group integration
 */
class SalesApiIntegrationTest extends TestCase
{
    private const SHOP_ID = 91001;
    private const USER_ID = 91001;
    private const TERMINAL_ID = 91001;
    private const LETTER = 'S';
    private const PRODUCT_ID = 991001;

    private ?\Doctrine\DBAL\Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();

        // CartService keeps the cart in the native PHP session.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        session_start();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->clean();
            $this->connection->close();
        }
        $this->connection = null;
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** Removes every row the cash end-to-end test owns: its terminal, letter and product. */
    private function clean(): void
    {
        $c = $this->connection;
        $sessionIds = $c->fetchFirstColumn('SELECT id FROM session WHERE terminal_id = ?', [self::TERMINAL_ID]);
        foreach ($sessionIds as $sessionId) {
            $orderIds = $c->fetchFirstColumn('SELECT id FROM order_meta WHERE session_id = ?', [(int) $sessionId]);
            foreach ($orderIds as $orderId) {
                $c->executeStatement('DELETE FROM outbox WHERE order_id = ?', [(int) $orderId]);
                $c->executeStatement('DELETE FROM sale_line WHERE order_id = ?', [(int) $orderId]);
            }
            $c->executeStatement('DELETE FROM movement WHERE session_id = ?', [(int) $sessionId]);
            $c->executeStatement('DELETE FROM z_report WHERE session_id = ?', [(int) $sessionId]);
            $c->executeStatement('DELETE FROM order_meta WHERE session_id = ?', [(int) $sessionId]);
        }
        $c->executeStatement('DELETE FROM session WHERE terminal_id = ?', [self::TERMINAL_ID]);
        $c->executeStatement('DELETE FROM ticket_sequence WHERE letter = ?', [self::LETTER]);
        $c->executeStatement('DELETE FROM terminal WHERE id = ? OR ps_shop_id = ?', [self::TERMINAL_ID, self::SHOP_ID]);
        $c->executeStatement('DELETE FROM catalog_stock WHERE id_product = ?', [self::PRODUCT_ID]);
        $c->executeStatement('DELETE FROM catalog_product WHERE id = ?', [self::PRODUCT_ID]);
        $c->executeStatement('DELETE FROM user WHERE id = ?', [self::USER_ID]);
    }

    public function testCatalogSearchRequiresAuthentication(): void
    {
        $service = new ProductSearchService($this->connection);
        $controller = new ProductController($service);
        $response = $controller->search(SessionRequest::create(['q' => 'test']));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testCatalogSearchReturnsProductsWithSession(): void
    {
        $service = new ProductSearchService($this->connection);
        $controller = new ProductController($service);
        $response = $controller->search(SessionRequest::create(['q' => 'Hummingbird'], ['user_id' => 1]));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('data', $data);
    }

    public function testCategoriesReturnsActiveCategoriesWithSession(): void
    {
        $service = new ProductSearchService($this->connection);
        $controller = new ProductController($service);
        $response = $controller->categories(SessionRequest::create([], ['user_id' => 1]));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('data', $data);
    }

    public function testCartCrudFlow(): void
    {
        $session = ['user_id' => 1];
        $productSearch = new ProductSearchService($this->connection);
        $cartService = new CartService();
        $controller = new CartController($cartService, $productSearch);

        // 1. Get empty cart
        $response = $controller->get(SessionRequest::create([], $session));
        $this->assertSame(200, $response->getStatusCode());

        // 2. Clear cart (idempotent)
        $response = $controller->clear(SessionRequest::create([], $session));
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertEmpty($data['data']['lines']);

        // 3. Apply discount
        $response = $controller->applyDiscount(SessionRequest::json(['type' => 'pct', 'value' => 10], $session));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCartAddFailsWithoutSession(): void
    {
        $productSearch = new ProductSearchService($this->connection);
        $cartService = new CartService();
        $controller = new CartController($cartService, $productSearch);

        $response = $controller->addItem(SessionRequest::json(['product_id' => 1]));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testCashSessionEndToEndFlowOpenCheckoutMovementReportCurrentClose(): void
    {
        $c = $this->connection;
        $roleId = (int) $c->fetchOne('SELECT id FROM role ORDER BY id LIMIT 1');
        $c->insert('user', ['id' => self::USER_ID, 'role_id' => $roleId, 'name' => 'Sales API cashier', 'pin_hash' => 'x', 'active' => 1]);
        $c->insert('terminal', ['id' => self::TERMINAL_ID, 'ps_shop_id' => self::SHOP_ID, 'name' => 'Sales API', 'ticket_letter' => self::LETTER, 'active' => 1]);
        $c->insert('catalog_product', ['id' => self::PRODUCT_ID, 'name' => 'Sales API product', 'price' => 24.79, 'price_ttc' => 30.00, 'price_net' => 24.79]);
        $c->insert('catalog_stock', ['id_product' => self::PRODUCT_ID, 'id_product_attribute' => 0, 'quantity' => 10]);

        // One cashier session shared by every request, as the browser cookie does.
        $session = new Session(new MockArraySessionStorage());
        $session->set('user_id', self::USER_ID);
        $session->set('shop_id', self::SHOP_ID);
        $requestStack = new RequestStack();
        $request = static function (array $body = []) use ($session, $requestStack): Request {
            $request = new Request([], [], [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
            $request->setSession($session);
            $requestStack->push($request);

            return $request;
        };

        $cashService = new CashSessionService($c, new TerminalService($c), $requestStack);

        $openResponse = (new CashOpenController($cashService))($request(['opening_cash' => 100.00]));
        $this->assertSame(200, $openResponse->getStatusCode());
        $opened = json_decode((string) $openResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::TERMINAL_ID, $opened['data']['terminal_id']);

        // Local-first sale: it is stored locally and never waits for PrestaShop.
        $psClient = $this->createMock(PsOrderClient::class);
        $psClient->expects($this->never())->method('createOrder');

        $checkoutService = new CheckoutService(
            new CartService(),
            $psClient,
            $c,
            $cashService,
            $this->createMock(MessageBusInterface::class),
            $this->createMock(PrinterService::class),
            false,
            null,
            new TicketNumberer($c),
            new Outbox($c),
            new OutboxSyncTrigger(),
        );
        $checkoutController = new CheckoutController($checkoutService, $cashService);
        $checkoutResponse = $checkoutController->checkout($request([
            'payment_method' => 'cash',
            'amount_tendered' => 40.00,
            'cart_data' => [
                'lines' => [[
                    'product_id' => self::PRODUCT_ID,
                    'id_product_attribute' => 0,
                    'qty' => 1,
                    'price' => 30.00,
                    'name' => 'Sales API product',
                ]],
                'total' => 30.00,
            ],
        ]));
        $this->assertSame(200, $checkoutResponse->getStatusCode(), (string) $checkoutResponse->getContent());

        $movementResponse = (new CashMovementController($cashService))($request([
            'type' => 'cash_out',
            'amount' => 10.00,
            'concept' => 'Petty cash',
        ]));
        $this->assertSame(200, $movementResponse->getStatusCode());

        $reportResponse = (new CashReportXController($cashService))($request());
        $this->assertSame(200, $reportResponse->getStatusCode());
        // JsonResponse writes 100.0 as 100, so amounts are compared by value.
        $report = json_decode((string) $reportResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals(100.0, $report['data']['opening_cash']);
        $this->assertEquals(30.0, $report['data']['sales_cash']);
        $this->assertEquals(10.0, $report['data']['cash_out_total']);
        $this->assertEquals(120.0, $report['data']['expected_cash']);

        $currentResponse = (new CashCurrentController($cashService))($request());
        $this->assertSame(200, $currentResponse->getStatusCode());
        $current = json_decode((string) $currentResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($current['data']['open']);
        $this->assertEquals(120.0, $current['data']['expected_cash']);

        $closeResponse = (new CashCloseController($cashService))($request(['counted_cash' => 118.00]));
        $this->assertSame(200, $closeResponse->getStatusCode());
        $close = json_decode((string) $closeResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals(120.0, $close['data']['expected_cash']);
        $this->assertEquals(118.0, $close['data']['counted_cash']);
        $this->assertEquals(-2.0, $close['data']['difference']);
    }
}
