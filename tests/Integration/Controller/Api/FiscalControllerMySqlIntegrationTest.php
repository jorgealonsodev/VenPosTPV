<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Controller\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\FiscalController;
use VenPosTPV\Service\Fiscal\FiscalRecorder;
use VenPosTPV\Service\Fiscal\VerifactuSettings;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketVerificationService;
use VenPosTPV\Tests\Support\SessionRequest;
use VenPosTPV\Tests\Support\TestDatabase;

final class FiscalControllerMySqlIntegrationTest extends TestCase
{
    private const TERMINAL = 94201;
    private const ORDER = 94211;
    private const ADMIN = ['user_id' => 1, 'role' => 'admin'];

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->reset();
        $this->connection->insert('terminal', ['id' => self::TERMINAL, 'ps_shop_id' => 1, 'name' => 'Fiscal API', 'tax_id' => 'G12345678', 'active' => 1]);
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->reset();
            $this->connection->close();
        }
    }

    private function reset(): void
    {
        \assert($this->connection !== null);
        $this->connection->executeStatement('DROP TRIGGER IF EXISTS fiscal_record_no_update');
        $this->connection->executeStatement('DROP TRIGGER IF EXISTS fiscal_record_no_delete');
        $this->connection->executeStatement('TRUNCATE TABLE fiscal_record');
        $this->connection->executeStatement('UPDATE fiscal_chain SET last_record_id = NULL, last_hash = NULL WHERE id = 1');
        $this->connection->executeStatement("DELETE FROM app_setting WHERE name = 'verifactu.enabled'");
        $this->connection->executeStatement('DELETE FROM order_meta WHERE id = ?', [self::ORDER]);
        $this->connection->executeStatement('DELETE FROM terminal WHERE id = ?', [self::TERMINAL]);
    }

    public function testAdminSeesTheVerifiedChain(): void
    {
        \assert($this->connection !== null);
        $this->connection->transactional(fn () => (new FiscalRecorder($this->connection))->recordSale(self::ORDER, self::TERMINAL, 'Y26000001', 10.0, 1.74, new \DateTimeImmutable()));

        $response = (new FiscalController($this->connection))->chain(SessionRequest::create([], ['user_id' => 1, 'role' => 'admin']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true)['data'];
        self::assertTrue($data['ok']);
        self::assertSame(1, $data['records']);
        self::assertNull($data['broken_id']);
        self::assertFalse($data['protected']);
    }

    public function testCashierIsRefused(): void
    {
        \assert($this->connection !== null);
        $response = (new FiscalController($this->connection))->chain(SessionRequest::create([], ['user_id' => 2, 'role' => 'cashier']));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    private function put(mixed $body, array $session = self::ADMIN): Response
    {
        \assert($this->connection !== null);

        return (new FiscalController($this->connection))->saveSettings(SessionRequest::create([], $session, json_encode($body, JSON_THROW_ON_ERROR)));
    }

    private function enabled(): bool
    {
        \assert($this->connection !== null);
        $response = (new FiscalController($this->connection))->settings(SessionRequest::create([], self::ADMIN));
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        return json_decode((string) $response->getContent(), true)['data']['enabled'];
    }

    public function testTheSwitchIsOffUntilTheAdminTurnsItOn(): void
    {
        self::assertFalse($this->enabled());

        self::assertSame(Response::HTTP_OK, $this->put(['enabled' => true])->getStatusCode());
        self::assertTrue($this->enabled());

        self::assertSame(Response::HTTP_OK, $this->put(['enabled' => false])->getStatusCode());
        self::assertFalse($this->enabled());
    }

    public function testInvalidValuesAndCashiersChangeNothing(): void
    {
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->put(['enabled' => 'yes'])->getStatusCode());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->put([])->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->put(['enabled' => true], ['user_id' => 2, 'role' => 'cashier'])->getStatusCode());
        self::assertFalse($this->enabled());
    }

    public function testTheTicketCarriesTheTaxQrOnlyWithTheSwitchOn(): void
    {
        \assert($this->connection !== null);
        $this->connection->insert('order_meta', [
            'id' => self::ORDER,
            'reference' => 'Y26000007',
            'terminal_id' => self::TERMINAL,
            'session_id' => 0,
            'user_id' => 1,
            'payment_breakdown' => json_encode(['reference' => 'Y26000007', 'total' => 12.1, 'tax' => 2.1, 'lines' => []], JSON_THROW_ON_ERROR),
            'printed' => 0,
            'synced' => 0,
            'created_at' => '2026-11-05 10:00:00',
        ]);
        $this->connection->transactional(fn () => (new FiscalRecorder($this->connection))->recordSale(self::ORDER, self::TERMINAL, 'Y26000007', 12.1, 2.1, new \DateTimeImmutable('2026-11-05 10:00:00')));
        $settings = new VerifactuSettings($this->connection);
        $resolver = new TicketDataResolver($this->connection, new TicketVerificationService('test-secret', 'http://tpv'), 'Tienda', 'Calle 1', $settings);

        self::assertNull($resolver->resolve(TicketDataResolver::saleKey(self::ORDER))?->fiscalQrUrl);

        $settings->setEnabled(true);
        self::assertSame(
            'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=G12345678&numserie=Y26000007&fecha=05-11-2026&importe=12.10',
            $resolver->resolve(TicketDataResolver::saleKey(self::ORDER))?->fiscalQrUrl
        );
    }
}
