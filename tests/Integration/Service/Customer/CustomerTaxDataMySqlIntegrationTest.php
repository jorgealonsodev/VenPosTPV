<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Customer;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Customer\CustomerDirectory;
use VenPosTPV\Service\Customer\Exception\CustomerException;
use VenPosTPV\Tests\Support\TestDatabase;

/** docs/prd/factura-completa.md F1: the customer's tax data, edited at the till. */
final class CustomerTaxDataMySqlIntegrationTest extends TestCase
{
    private const DOMAIN = '@taxdata-test.example';

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->connection->executeStatement('DELETE FROM customer WHERE email LIKE ?', ['%' . self::DOMAIN]);
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->connection->executeStatement('DELETE FROM customer WHERE email LIKE ?', ['%' . self::DOMAIN]);
            $this->connection->close();
        }
    }

    private function customer(string $name, array $extra = []): int
    {
        \assert($this->connection !== null);
        $this->connection->insert('customer', $extra + ['firstname' => 'Luis', 'lastname' => 'Gómez', 'email' => $name . self::DOMAIN, 'active' => 1, 'ps_customer_id' => null]);

        return (int) $this->connection->lastInsertId();
    }

    public function testTaxDataAreStoredNormalizedAndShownWithWhatIsMissing(): void
    {
        \assert($this->connection !== null);
        $id = $this->customer('luis');
        $directory = new CustomerDirectory($this->connection);

        $before = $directory->search('luis' . self::DOMAIN)[0];
        self::assertSame([false, ['tax_name', 'tax_id', 'address', 'postcode', 'city'], 'ES'], [$before['tax_complete'], $before['tax_missing'], $before['country']], 'existing customers start empty');

        $view = $directory->updateTaxData($id, ['tax_name' => 'Deportes Ejemplo SL', 'tax_id' => 'b12345674', 'address' => 'Calle Mayor 1', 'postcode' => '33002', 'city' => 'Oviedo']);

        self::assertSame(['Deportes Ejemplo SL', 'B12345674', 'NI', 'ES', true, []], [$view['tax_name'], $view['tax_id'], $view['tax_id_type'], $view['country'], $view['tax_complete'], $view['tax_missing']]);
        self::assertSame(['Luis', 'Gómez', 'luis' . self::DOMAIN], [$view['firstname'], $view['lastname'], $view['email']], 'name and email are not changed here');
        $row = $this->connection->fetchAssociative('SELECT tax_name, tax_id, tax_id_type, country, province FROM customer WHERE id = ?', [$id]);
        self::assertSame(['tax_name' => 'Deportes Ejemplo SL', 'tax_id' => 'B12345674', 'tax_id_type' => 'NI', 'country' => 'ES', 'province' => null], $row);
        self::assertSame([$id], array_column($directory->search('B12345674'), 'local_id'), 'found by NIF');
        self::assertSame([$id], array_column($directory->search('Ejemplo SL'), 'local_id'), 'found by legal name');
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM outbox WHERE type = 'customer' AND JSON_EXTRACT(payload, '$._local.customer_id') = ?", [$id]), 'not uploaded to PrestaShop');
    }

    public function testAnInvalidNifIsRefusedAndNothingChanges(): void
    {
        \assert($this->connection !== null);
        $id = $this->customer('ana', ['tax_id' => 'B12345674']);

        try {
            (new CustomerDirectory($this->connection))->updateTaxData($id, ['tax_id' => 'B12345675', 'city' => 'Gijón']);
            self::fail('Invalid NIF');
        } catch (CustomerException $e) {
            self::assertSame([422, 'El NIF no es válido'], [$e->getCode(), $e->getMessage()]);
        }
        self::assertSame(['B12345674', null], array_values($this->connection->fetchAssociative('SELECT tax_id, city FROM customer WHERE id = ?', [$id]) ?: []));
    }

    public function testAMergedDuplicateEditsTheCustomerItWasMergedInto(): void
    {
        \assert($this->connection !== null);
        $survivor = $this->customer('survivor');
        $duplicate = $this->customer('duplicate', ['active' => 0, 'merged_into' => $survivor]);

        $view = (new CustomerDirectory($this->connection))->updateTaxData($duplicate, ['city' => 'León']);

        self::assertSame([$survivor, 'León'], [$view['local_id'], $view['city']]);
    }

    public function testAnUnknownCustomerIsNotFound(): void
    {
        \assert($this->connection !== null);
        $this->expectExceptionCode(404);
        (new CustomerDirectory($this->connection))->updateTaxData(987654321, ['city' => 'León']);
    }
}
