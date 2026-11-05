<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Controller\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\FiscalController;
use VenPosTPV\Service\Fiscal\FiscalRecorder;
use VenPosTPV\Tests\Support\SessionRequest;
use VenPosTPV\Tests\Support\TestDatabase;

final class FiscalControllerMySqlIntegrationTest extends TestCase
{
    private const TERMINAL = 94201;

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
        $this->connection->executeStatement('DELETE FROM terminal WHERE id = ?', [self::TERMINAL]);
    }

    public function testAdminSeesTheVerifiedChain(): void
    {
        \assert($this->connection !== null);
        $this->connection->transactional(fn () => (new FiscalRecorder($this->connection))->recordSale(94211, self::TERMINAL, 'Y26000001', 10.0, 1.74, new \DateTimeImmutable()));

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
}
