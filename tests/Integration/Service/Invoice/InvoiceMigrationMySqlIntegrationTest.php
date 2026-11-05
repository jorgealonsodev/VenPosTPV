<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Invoice;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use VenPosTPV\Migrations\Version00000047FullInvoice;
use VenPosTPV\Tests\Support\TestDatabase;

/** Version00000047: nullable customer columns, invoice tables, fiscal_record.invoice_id; F reserved. */
final class InvoiceMigrationMySqlIntegrationTest extends TestCase
{
    private const TILL = 95191;

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->connection->executeStatement('DELETE FROM terminal WHERE id = ?', [self::TILL]);
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->connection->executeStatement('DELETE FROM terminal WHERE id = ?', [self::TILL]);
            $this->connection->close();
        }
    }

    /** @return list<string> */
    private function migrate(): array
    {
        \assert($this->connection !== null);
        $require = \dirname(__DIR__, 4) . '/migrations/Version00000047FullInvoice.php';
        require_once $require;
        $migration = new Version00000047FullInvoice($this->connection, new NullLogger());
        $migration->up(new Schema());
        $sql = array_map('strval', $migration->getSql());
        foreach ($sql as $query) {
            $this->connection->executeStatement($query);
        }

        return $sql;
    }

    public function testTheSchemaIsInPlaceAndRunningAgainChangesNothing(): void
    {
        \assert($this->connection !== null);
        $schema = $this->connection->createSchemaManager();
        $customer = $schema->introspectTable('customer');
        foreach (['tax_name', 'country', 'tax_id_type', 'tax_id', 'address', 'postcode', 'city', 'province'] as $column) {
            self::assertTrue($customer->hasColumn($column), $column);
            self::assertFalse($customer->getColumn($column)->getNotnull(), $column . ' is nullable');
        }
        self::assertTrue($schema->introspectTable('fiscal_record')->hasColumn('invoice_id'));
        self::assertTrue($schema->introspectTable('fiscal_record')->hasIndex('uk_fiscal_record_invoice'));
        self::assertTrue($schema->introspectTable('invoice')->hasIndex('uk_invoice_sale'));
        self::assertSame(['series', 'year'], $schema->introspectTable('invoice_sequence')->getPrimaryKey()?->getColumns());

        $again = $this->migrate();

        self::assertSame([], array_values(array_filter($again, static fn (string $sql): bool => str_starts_with(ltrim($sql), 'ALTER'))), 'every ALTER is guarded');
    }

    public function testATillUsingTheLetterFStopsTheMigration(): void
    {
        \assert($this->connection !== null);
        $this->connection->insert('terminal', ['id' => self::TILL, 'ps_shop_id' => 1, 'name' => 'Caja F', 'ticket_letter' => 'F', 'active' => 1]);

        try {
            $this->migrate();
            self::fail('The migration must stop');
        } catch (AbortMigration $e) {
            self::assertSame('La letra F está reservada para las facturas: cambia la letra de la caja Caja F en Ajustes → Cajas y vuelve a ejecutar la migración.', $e->getMessage());
        }
    }
}
