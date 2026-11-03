<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Customer;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use VenPosTPV\Service\Cart\CartService;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\TerminalService;
use VenPosTPV\Service\Checkout\CheckoutService;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Customer\CustomerDirectory;
use VenPosTPV\Service\Customer\CustomerSyncService;
use VenPosTPV\Service\Customer\Exception\CustomerException;
use VenPosTPV\Service\PrestaShop\Exception\PsApiException;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Service\Sync\Outbox;
use VenPosTPV\Service\Sync\OutboxSync;
use VenPosTPV\Service\Sync\OutboxSyncTrigger;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * PRD C1, C2: "Copia local de clientes; las altas nuevas se crean en local y se
 * suben con la venta." The copy is synced like the catalog and searched offline; a
 * customer created at the till is uploaded before the sale that uses it, and the
 * sale is sent with the PrestaShop customer id known at that moment.
 */
final class LocalCustomersMySqlIntegrationTest extends TestCase
{
    private const TERMINAL_ID = 9421;
    private const LETTER = 'J';
    private const SESSION_ID = 9421;
    private const PRODUCT = 942101;
    private const PRICE = 12.10;
    private const PS_IDS = [942150, 942151, 942152, 942160];
    private const EMAIL_DOMAIN = '@t6-customers.test';

    private ?Connection $connection = null;

    private \DateTimeImmutable $now;

    /** @var list<string> what reached PrestaShop, in order */
    private array $calls = [];

    /** @var list<array<string, mixed>> */
    private array $orders = [];

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();
        $c = $this->connection;
        $c->insert('terminal', ['id' => self::TERMINAL_ID, 'ps_shop_id' => 1, 'name' => 'Customers', 'ticket_letter' => self::LETTER]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->insert('session', ['id' => self::SESSION_ID, 'terminal_id' => self::TERMINAL_ID, 'user_id' => 1, 'opened_at' => '2026-11-03 09:00:00', 'opening_cash' => 100]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $c->insert('catalog_product', ['id' => self::PRODUCT, 'name' => 'Toalla', 'price' => 10.0, 'price_ttc' => self::PRICE, 'price_net' => 10.0]);
        $c->insert('catalog_stock', ['id_product' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 5]);
        $_SESSION = ['shop_id' => 1, 'cash_session_id' => self::SESSION_ID, 'user_id' => 1, 'terminal_id' => self::TERMINAL_ID];
        $this->now = new \DateTimeImmutable(date('Y-m-d H:i:s', time() + 60));
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->clean();
            $this->connection->close();
        }
        $this->connection = null;
        $_SESSION = [];
    }

    private function clean(): void
    {
        $c = $this->connection;
        foreach ($c->fetchFirstColumn('SELECT id FROM order_meta WHERE session_id = ?', [self::SESSION_ID]) as $id) {
            $c->executeStatement('DELETE FROM outbox WHERE order_id = ?', [(int) $id]);
        }
        foreach ($c->fetchFirstColumn("SELECT outbox_id FROM customer WHERE email LIKE ? AND outbox_id IS NOT NULL", ['%' . self::EMAIL_DOMAIN]) as $id) {
            $c->executeStatement('DELETE FROM outbox WHERE id = ?', [(int) $id]);
        }
        $c->executeStatement('DELETE FROM customer WHERE email LIKE ? OR ps_customer_id IN (?, ?, ?, ?)', ['%' . self::EMAIL_DOMAIN, ...self::PS_IDS]);
        $c->executeStatement('DELETE FROM movement WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM order_meta WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM session WHERE id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM ticket_sequence WHERE letter = ?', [self::LETTER]);
        $c->executeStatement('DELETE FROM terminal WHERE id = ?', [self::TERMINAL_ID]);
        $c->executeStatement('DELETE FROM catalog_stock WHERE id_product = ?', [self::PRODUCT]);
        $c->executeStatement('DELETE FROM catalog_product WHERE id = ?', [self::PRODUCT]);
    }

    private function email(string $name): string
    {
        return $name . self::EMAIL_DOMAIN;
    }

    private function directory(): CustomerDirectory
    {
        return new CustomerDirectory($this->connection, new Outbox($this->connection), new OutboxSyncTrigger());
    }

    private function checkout(): CheckoutService
    {
        $c = $this->connection;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createReferencedOrder');
        $cash = new CashSessionService($c, $this->createMock(TerminalService::class), new RequestStack());

        return new CheckoutService($this->createMock(CartService::class), $client, $c, $cash, $bus, $this->createMock(PrinterService::class), false, null, new TicketNumberer($c), new Outbox($c), new OutboxSyncTrigger(), $this->directory());
    }

    /**
     * @param \Closure(string, array<string, mixed>): mixed $customers what PrestaShop answers for find/create
     */
    private function sync(\Closure $customers, bool $ordersOnline = true): OutboxSync
    {
        $orders = $this->createMock(PsOrderClient::class);
        $orders->method('createReferencedOrder')->willReturnCallback(function (array $payload) use ($ordersOnline): array {
            $this->calls[] = 'order';
            if (!$ordersOnline) {
                throw new \RuntimeException('Connection refused');
            }
            $this->orders[] = $payload;

            return ['order_id' => 942199, 'reference' => (string) $payload['reference'], 'total_paid' => self::PRICE, 'replayed' => false];
        });
        $api = $this->createMock(PrestaShopApiClient::class);
        $api->method('findCustomerIdByEmail')->willReturnCallback(function (string $email) use ($customers): ?int {
            $this->calls[] = 'find';

            return $customers('find', ['email' => $email]);
        });
        $api->method('createCustomer')->willReturnCallback(function (string $firstname, string $lastname, string $email) use ($customers): int {
            $this->calls[] = 'create';

            return $customers('create', ['firstname' => $firstname, 'lastname' => $lastname, 'email' => $email]);
        });

        return new OutboxSync($this->connection, $orders, null, fn (): \DateTimeImmutable => $this->now, customerClient: $api);
    }

    /** @return array<string, mixed> */
    private function cart(array $customer): array
    {
        return ['lines' => [['product_id' => self::PRODUCT, 'id_product_attribute' => 0, 'qty' => 1, 'price' => self::PRICE, 'name' => 'Toalla']], 'total' => self::PRICE, 'cash_amount' => self::PRICE, 'customer' => $customer];
    }

    /** @return array<string, mixed> */
    private function saleRow(int $orderId): array
    {
        return $this->connection->fetchAssociative('SELECT * FROM outbox WHERE order_id = ? AND sequence = 1', [$orderId]);
    }

    public function testTheLocalCopyIsSyncedLikeTheCatalogAndSearchedWithoutPrestaShop(): void
    {
        $c = $this->connection;
        $c->insert('customer', ['ps_customer_id' => self::PS_IDS[2], 'firstname' => 'Gone', 'lastname' => 'Away', 'email' => $this->email('gone'), 'active' => 1]);
        $c->insert('customer', ['ps_customer_id' => self::PS_IDS[0], 'firstname' => 'Ana', 'lastname' => 'Old', 'email' => $this->email('ana'), 'active' => 1]);
        $local = $this->directory()->create(['firstname' => 'Lucía', 'lastname' => 'Local', 'email' => $this->email('lucia'), 'phone' => '600 123 123']);

        $api = $this->createMock(PrestaShopApiClient::class);
        $api->method('getCustomers')->willReturn([
            ['id' => self::PS_IDS[0], 'firstname' => 'Ana', 'lastname' => 'Martínez', 'email' => $this->email('ana'), 'active' => '1'],
            ['id' => self::PS_IDS[1], 'firstname' => 'Roberto', 'lastname' => 'Sánchez', 'email' => $this->email('roberto'), 'active' => '0'],
        ]);
        $counts = (new CustomerSyncService($api, $c))->syncAll();

        $this->assertSame(2, $counts['customers']);
        $this->assertSame(['Martínez', 1], [$c->fetchOne('SELECT lastname FROM customer WHERE ps_customer_id = ?', [self::PS_IDS[0]]), (int) $c->fetchOne('SELECT active FROM customer WHERE ps_customer_id = ?', [self::PS_IDS[0]])]);
        $this->assertSame(0, (int) $c->fetchOne('SELECT active FROM customer WHERE ps_customer_id = ?', [self::PS_IDS[1]]), 'inactive in PrestaShop');
        $this->assertSame(0, (int) $c->fetchOne('SELECT active FROM customer WHERE ps_customer_id = ?', [self::PS_IDS[2]]), 'no longer in PrestaShop');
        $this->assertSame('Local', $c->fetchOne('SELECT lastname FROM customer WHERE id = ?', [$local['local_id']]), 'a customer created at the till is left alone');

        // No PrestaShop at all from here: the search reads the local copy.
        $directory = $this->directory();
        $byName = $directory->search('martí');
        $this->assertSame([self::PS_IDS[0]], array_column($byName, 'id'));
        $this->assertSame('Ana Martínez', $byName[0]['name']);
        $this->assertSame([$local['local_id']], array_column($directory->search('600 123'), 'local_id'), 'by phone, also before its upload');
        $this->assertSame([], $directory->search('roberto'), 'inactive customers are not offered');
        $this->assertSame([], $directory->search('gone' . self::EMAIL_DOMAIN));
    }

    public function testANewCustomerIsCreatedLocallyWithItsUploadQueuedAndAnEmailIsNotDuplicated(): void
    {
        $directory = $this->directory();
        $created = $directory->create(['firstname' => ' Marta ', 'lastname' => 'Ruiz', 'email' => $this->email('MARTA'), 'phone' => '611 222 333']);

        $this->assertNull($created['id'], 'no PrestaShop id yet');
        $this->assertFalse($created['synced']);
        $this->assertFalse($created['existing']);
        $this->assertSame('Marta Ruiz', $created['name']);
        $row = $this->connection->fetchAssociative('SELECT * FROM customer WHERE id = ?', [$created['local_id']]);
        $this->assertSame(['Marta', 'Ruiz', $this->email('marta'), '611 222 333'], [$row['firstname'], $row['lastname'], $row['email'], $row['phone']]);
        $outbox = $this->connection->fetchAssociative('SELECT * FROM outbox WHERE id = ?', [(int) $row['outbox_id']]);
        $this->assertSame(['customer', null, 'pending'], [$outbox['type'], $outbox['order_id'], $outbox['status']]);
        $this->assertSame(['firstname' => 'Marta', 'lastname' => 'Ruiz', 'email' => $this->email('marta'), '_local' => ['customer_id' => $created['local_id']]], json_decode((string) $outbox['payload'], true));

        $again = $directory->create(['firstname' => 'Marta', 'lastname' => 'Otra', 'email' => $this->email('marta')]);
        $this->assertTrue($again['existing']);
        $this->assertSame($created['local_id'], $again['local_id']);
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM customer WHERE email = ?', [$this->email('marta')]));

        foreach ([['firstname' => '', 'lastname' => 'Ruiz', 'email' => $this->email('x')], ['firstname' => 'R2D2', 'lastname' => 'Ruiz', 'email' => $this->email('y')], ['firstname' => 'Eva', 'lastname' => 'Ruiz', 'email' => 'not-an-email']] as $invalid) {
            try {
                $directory->create($invalid);
                $this->fail('Invalid customer accepted');
            } catch (CustomerException $e) {
                $this->assertSame(422, $e->getCode());
            }
        }
    }

    public function testANewCustomerAndItsSaleOfflineUploadCustomerFirstAndTheSaleWithItsPrestaShopId(): void
    {
        $customer = $this->directory()->create(['firstname' => 'Pablo', 'lastname' => 'Nuevo', 'email' => $this->email('pablo')]);
        $sale = $this->checkout()->createOrder(1, $this->cart($customer), 'cash', 20.0);
        $customerRow = (int) $this->connection->fetchOne('SELECT outbox_id FROM customer WHERE id = ?', [$customer['local_id']]);
        $this->assertSame($customerRow, (int) $this->saleRow($sale['order_id'])['depends_on'], 'the sale waits for its customer');

        // PrestaShop down: the customer is retried and the sale is not even attempted.
        $this->sync(static fn (): never => throw PsApiException::networkError('Connection refused', new \RuntimeException('down')))->run();
        $this->assertSame(['find'], $this->calls);
        $this->assertSame(['pending', 0], [$this->saleRow($sale['order_id'])['status'], (int) $this->saleRow($sale['order_id'])['attempts']]);

        // PrestaShop back: customer first, then the sale with the PrestaShop customer id.
        $this->now = $this->now->modify('+10 minutes');
        $this->calls = [];
        $counts = $this->sync(static fn (string $op): ?int => $op === 'find' ? null : self::PS_IDS[3])->run();

        $this->assertSame(['find', 'create', 'order'], $this->calls);
        $this->assertSame(2, $counts['uploaded']);
        $this->assertSame(self::PS_IDS[3], (int) $this->connection->fetchOne('SELECT ps_customer_id FROM customer WHERE id = ?', [$customer['local_id']]));
        $this->assertSame(self::PS_IDS[3], $this->orders[0]['id_customer']);
        $this->assertArrayNotHasKey('_customer_local_id', $this->orders[0], 'local bookkeeping never reaches the module');
        $this->assertSame('done', $this->saleRow($sale['order_id'])['status']);
    }

    public function testACustomerAlreadyInPrestaShopIsLinkedInsteadOfCreatedTwiceAndItsSyncedCopyMerged(): void
    {
        $customer = $this->directory()->create(['firstname' => 'Ana', 'lastname' => 'Doble', 'email' => $this->email('doble')]);
        // The catalog-like sync brought the same PrestaShop customer meanwhile.
        $this->connection->insert('customer', ['ps_customer_id' => self::PS_IDS[1], 'firstname' => 'Ana', 'lastname' => 'Doble', 'email' => $this->email('doble'), 'active' => 1]);

        $this->sync(static fn (string $op): ?int => $op === 'find' ? self::PS_IDS[1] : throw new \LogicException('must not create'))->run();

        $this->assertSame(['find'], $this->calls);
        $this->assertEquals(
            [['id' => $customer['local_id'], 'ps_customer_id' => self::PS_IDS[1]]],
            $this->connection->fetchAllAssociative('SELECT id, ps_customer_id FROM customer WHERE email = ?', [$this->email('doble')])
        );
    }

    public function testARejectedCustomerBecomesAnIncidentAndItsSaleWaits(): void
    {
        $customer = $this->directory()->create(['firstname' => 'Rosa', 'lastname' => 'Rechazada', 'email' => $this->email('rosa')]);
        $sale = $this->checkout()->createOrder(1, $this->cart($customer), 'card', 0.0);

        $this->sync(static fn (string $op): ?int => $op === 'find' ? null : throw PsApiException::httpError(400, 'Validation error: "lastname"'))->run();
        $this->now = $this->now->modify('+2 hours');
        $this->sync(static fn (): never => throw new \LogicException('incident rows are not retried'))->run();

        $customerRow = $this->connection->fetchAssociative('SELECT o.status, o.last_error FROM outbox o JOIN customer c ON c.outbox_id = o.id WHERE c.id = ?', [$customer['local_id']]);
        $this->assertSame('incident', $customerRow['status']);
        $this->assertStringContainsString('lastname', $customerRow['last_error']);
        $this->assertSame(['pending', 0], [$this->saleRow($sale['order_id'])['status'], (int) $this->saleRow($sale['order_id'])['attempts']]);
        $this->assertSame(['find', 'create'], $this->calls, 'neither the incident nor its sale is sent again');
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM order_meta WHERE id = ?', [$sale['order_id']]), 'the local sale stays');
    }

    public function testASaleForASyncedCustomerIsQueuedWithItsPrestaShopIdAndWaitsForNothing(): void
    {
        $this->connection->insert('customer', ['ps_customer_id' => self::PS_IDS[0], 'firstname' => 'Ana', 'lastname' => 'Sync', 'email' => $this->email('anasync'), 'active' => 1]);
        $localId = (int) $this->connection->lastInsertId();

        $sale = $this->checkout()->createOrder(1, $this->cart(['local_id' => $localId, 'id' => 1, 'name' => 'Ana Sync']), 'card', 0.0);
        $legacy = $this->checkout()->createOrder(1, $this->cart(['id' => 77, 'name' => 'PrestaShop id only']), 'card', 0.0);

        $row = $this->saleRow($sale['order_id']);
        $this->assertNull($row['depends_on']);
        $this->assertSame(self::PS_IDS[0], json_decode((string) $row['payload'], true)['id_customer'], 'the local copy is authoritative, not the browser id');
        $this->assertSame(77, json_decode((string) $this->saleRow($legacy['order_id'])['payload'], true)['id_customer'], 'a cart with only a PrestaShop id behaves as before');
    }
}
