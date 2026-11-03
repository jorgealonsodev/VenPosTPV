<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use VenPosTPV\Migrations\Version00000031LocalFirstOrders;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * PRD §5 "Migración de datos": existing sales keep their PrestaShop order id and
 * reference and are marked synced; order_meta gets a local key.
 */
final class Version00000031LocalFirstOrdersTest extends TestCase
{
    private const SESSION_ID = 9402;

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        require_once \dirname(__DIR__, 3) . '/migrations/Version00000031LocalFirstOrders.php';
        $this->clean();
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->clean();
            // Leave the schema as the rest of the suite expects it.
            $this->migrate();
            $this->connection->close();
        }
    }

    private function clean(): void
    {
        $this->connection->executeStatement('DELETE FROM movement WHERE session_id = ?', [self::SESSION_ID]);
        $this->connection->executeStatement('DELETE FROM order_meta WHERE session_id = ?', [self::SESSION_ID]);
    }

    private function migrate(): void
    {
        $migration = new Version00000031LocalFirstOrders($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement((string) $query);
        }
    }

    /** Puts order_meta and movement back in their pre-V31 shape (no rows without a PrestaShop id may exist). */
    private function revertToLegacyShape(): void
    {
        $c = $this->connection;
        $this->assertSame(0, (int) $c->fetchOne('SELECT COUNT(*) FROM order_meta WHERE ps_order_id IS NULL'), 'other tests left local sales behind');
        $c->executeStatement('DROP TABLE IF EXISTS outbox');
        $c->executeStatement('ALTER TABLE order_meta DROP INDEX uk_order_meta_reference, DROP INDEX uk_order_meta_ps_order, DROP COLUMN reference, DROP COLUMN ps_reference, MODIFY id BIGINT UNSIGNED NOT NULL');
        $c->executeStatement('ALTER TABLE order_meta DROP PRIMARY KEY, DROP COLUMN id, MODIFY ps_order_id INT UNSIGNED NOT NULL, ADD PRIMARY KEY (ps_order_id)');
        $c->executeStatement('ALTER TABLE movement DROP INDEX idx_movement_order, DROP COLUMN order_id');
    }

    private function legacySale(int $psOrderId, string $reference): void
    {
        $this->connection->insert('order_meta', [
            'ps_order_id' => $psOrderId, 'terminal_id' => 1, 'session_id' => self::SESSION_ID, 'user_id' => 1,
            'payment_breakdown' => json_encode(['reference' => $reference, 'total' => 10.0, 'lines' => []], JSON_THROW_ON_ERROR),
            'created_at' => '2026-10-01 10:00:00',
        ]);
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$table]
        );
    }

    /** @return array<string, string> index name => comma-separated columns, unique flagged */
    private function indexes(string $table): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? GROUP BY INDEX_NAME, NON_UNIQUE ORDER BY INDEX_NAME',
            [$table]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['INDEX_NAME']] = ((int) $row['NON_UNIQUE'] === 0 ? 'unique:' : '') . $row['cols'];
        }

        return $result;
    }

    public function testExistingSalesKeepTheirIdsAndReferencesAndAreSyncedAndRerunChangesNothing(): void
    {
        $this->revertToLegacyShape();
        $this->legacySale(940101, 'XKQWERTYU');
        $this->legacySale(940102, 'TPV-REFERENCE-TOO-LONG');
        // A split PrestaShop cart shares one reference between its orders.
        $this->legacySale(940103, 'XKSPLITAB');
        $this->legacySale(940104, 'XKSPLITAB');
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $this->connection->insert('movement', ['session_id' => self::SESSION_ID, 'type' => 'sale', 'amount' => 10, 'ps_order_id' => 940101, 'created_at' => '2026-10-01 10:00:00']);
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->migrate();

        $rows = $this->connection->fetchAllAssociative('SELECT id, ps_order_id, reference, ps_reference, synced FROM order_meta WHERE session_id = ? ORDER BY ps_order_id', [self::SESSION_ID]);
        $this->assertCount(4, $rows);
        $this->assertSame([940101, 940102, 940103, 940104], array_map('intval', array_column($rows, 'ps_order_id')));
        $this->assertSame(['XKQWERTYU', null, null, null], array_column($rows, 'reference'), 'only a unique reference of at most 9 characters is copied');
        $this->assertSame([null, null, null, null], array_column($rows, 'ps_reference'));
        $this->assertSame([1, 1, 1, 1], array_map('intval', array_column($rows, 'synced')));
        foreach ($rows as $row) {
            $this->assertGreaterThan(0, (int) $row['id']);
        }
        $this->assertSame((int) $rows[0]['id'], (int) $this->connection->fetchOne('SELECT order_id FROM movement WHERE session_id = ?', [self::SESSION_ID]));

        $this->assertSame('id', $this->columns('order_meta')[0]);
        $indexes = $this->indexes('order_meta');
        $this->assertSame('unique:id', $indexes['PRIMARY']);
        $this->assertSame('unique:ps_order_id', $indexes['uk_order_meta_ps_order']);
        $this->assertSame('unique:reference', $indexes['uk_order_meta_reference']);
        $this->assertSame('YES', $this->connection->fetchOne("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_meta' AND COLUMN_NAME = 'ps_order_id'"));

        $this->assertSame(
            ['id', 'type', 'order_id', 'sequence', 'request_id', 'payload', 'status', 'attempts', 'next_attempt_at', 'claim_token', 'claimed_at', 'last_error', 'created_at', 'updated_at', 'done_at'],
            $this->columns('outbox')
        );
        $outboxIndexes = $this->indexes('outbox');
        $this->assertSame('unique:request_id', $outboxIndexes['uk_outbox_request']);
        $this->assertSame('unique:order_id,sequence', $outboxIndexes['uk_outbox_order_sequence']);

        $schema = [$this->columns('order_meta'), $indexes, $this->columns('outbox'), $outboxIndexes, $this->columns('movement')];
        $this->migrate();

        $this->assertSame($rows, $this->connection->fetchAllAssociative('SELECT id, ps_order_id, reference, ps_reference, synced FROM order_meta WHERE session_id = ? ORDER BY ps_order_id', [self::SESSION_ID]));
        $this->assertSame($schema, [$this->columns('order_meta'), $this->indexes('order_meta'), $this->columns('outbox'), $this->indexes('outbox'), $this->columns('movement')]);
    }

    public function testRerunLeavesLocalSalesWaitingForUploadUntouched(): void
    {
        $this->migrate();
        $this->connection->insert('order_meta', [
            'reference' => 'Q26000077', 'terminal_id' => 1, 'session_id' => self::SESSION_ID, 'user_id' => 1, 'synced' => 0,
            'payment_breakdown' => json_encode(['reference' => 'Q26000077', 'total' => 10.0, 'lines' => []], JSON_THROW_ON_ERROR),
        ]);

        $this->migrate();

        $this->assertEquals(
            ['ps_order_id' => null, 'reference' => 'Q26000077', 'synced' => 0],
            $this->connection->fetchAssociative('SELECT ps_order_id, reference, synced FROM order_meta WHERE session_id = ?', [self::SESSION_ID])
        );
    }
}
