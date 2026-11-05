<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Backup;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Backup\DatabaseHealthCheck;
use VenPosTPV\Service\Fiscal\FiscalChainVerifier;
use VenPosTPV\Service\Fiscal\FiscalRecorder;
use VenPosTPV\Tests\Support\TestDatabase;

final class DatabaseHealthCheckMySqlIntegrationTest extends TestCase
{
    private const TERMINAL = 95101;
    private const ORDER = 95111;

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->reset();
        $this->connection->insert('terminal', ['id' => self::TERMINAL, 'ps_shop_id' => 1, 'name' => 'Backup', 'tax_id' => 'G12345678', 'active' => 1]);
        $this->connection->insert('order_meta', [
            'id' => self::ORDER,
            'reference' => 'B26000001',
            'terminal_id' => self::TERMINAL,
            'session_id' => 0,
            'user_id' => 1,
            'payment_breakdown' => '{}',
            'printed' => 0,
            'synced' => 0,
            'created_at' => '2026-11-05 10:00:00',
        ]);
        $this->connection->transactional(fn () => (new FiscalRecorder($this->connection))->recordSale(
            self::ORDER, self::TERMINAL, 'B26000001', 12.10, 2.10, new \DateTimeImmutable('2026-11-05 10:00:00')
        ));
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->reset();
            $this->connection->close();
        }
    }

    /** Emptied with DDL: the records refuse DELETE when the triggers exist. */
    private function reset(): void
    {
        \assert($this->connection !== null);
        $this->connection->executeStatement('DROP TRIGGER IF EXISTS fiscal_record_no_update');
        $this->connection->executeStatement('DROP TRIGGER IF EXISTS fiscal_record_no_delete');
        $this->connection->executeStatement('TRUNCATE TABLE fiscal_record');
        $this->connection->executeStatement('UPDATE fiscal_chain SET last_record_id = NULL, last_hash = NULL WHERE id = 1');
        $this->connection->executeStatement('DELETE FROM order_meta WHERE id = ?', [self::ORDER]);
        $this->connection->executeStatement('DELETE FROM terminal WHERE id = ?', [self::TERMINAL]);
    }

    private function healthCheck(): DatabaseHealthCheck
    {
        \assert($this->connection !== null);

        return new DatabaseHealthCheck($this->connection, new FiscalChainVerifier($this->connection));
    }

    public function testAHealthyDatabasePassesEveryTableAndTheFiscalChain(): void
    {
        $report = $this->healthCheck()->run();

        $this->assertSame([], $report->problems);
        $this->assertSame(1, $report->fiscalRecords);
        $this->assertGreaterThan(10, $report->tables);
    }

    public function testATamperedFiscalRecordIsReportedWithItsId(): void
    {
        \assert($this->connection !== null);
        $id = (int) $this->connection->fetchOne('SELECT id FROM fiscal_record ORDER BY id LIMIT 1');
        $this->connection->update('fiscal_record', ['total' => '1.00'], ['id' => $id]);

        $report = $this->healthCheck()->run();

        $this->assertFalse($report->ok());
        $this->assertSame([sprintf(
            'Fiscal chain broken at record %d: La huella no coincide con los datos del registro (registro modificado)',
            $id
        )], $report->problems);
    }
}
