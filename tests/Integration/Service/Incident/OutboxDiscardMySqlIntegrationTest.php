<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Incident;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Incident\OutboxIncidents;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * PRD I3: an administrator discards an incident PrestaShop will never accept (a
 * deleted product). The row becomes `discarded` with who, when and why; what could
 * never upload behind it (the rest of its sale, the sales of a discarded customer)
 * is discarded with it, so nothing waits for ever. The counts and the incident list
 * leave discarded rows out; the recent discarded list keeps a trace of them.
 */
final class OutboxDiscardMySqlIntegrationTest extends TestCase
{
    private const SESSION_ID = 9736;

    private ?Connection $connection = null;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();
        $this->now = new \DateTimeImmutable('2026-11-03 12:40:00');
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->clean();
            $this->connection->close();
        }
        $this->connection = null;
    }

    private function clean(): void
    {
        $c = $this->connection;
        $c->executeStatement('DELETE FROM outbox WHERE request_id LIKE ?', ['t36-%']);
        $c->executeStatement('DELETE FROM order_meta WHERE session_id = ?', [self::SESSION_ID]);
    }

    private function incidents(): OutboxIncidents
    {
        return new OutboxIncidents($this->connection, fn (): \DateTimeImmutable => $this->now);
    }

    private function sale(string $reference): int
    {
        $this->connection->insert('order_meta', [
            'reference' => $reference, 'terminal_id' => 1, 'session_id' => self::SESSION_ID, 'user_id' => 1, 'synced' => 0,
            'payment_breakdown' => json_encode(['reference' => $reference, 'total' => 10.0, 'lines' => []], JSON_THROW_ON_ERROR),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    private function row(?int $orderId, int $sequence, string $status, string $type = 'sale', ?int $dependsOn = null): int
    {
        $this->connection->insert('outbox', [
            'type' => $type, 'order_id' => $orderId, 'sequence' => $sequence, 'depends_on' => $dependsOn,
            'request_id' => 't36-' . bin2hex(random_bytes(8)), 'payload' => '{}', 'status' => $status, 'attempts' => 1,
            'next_attempt_at' => '2026-11-03 09:00:00', 'last_error' => $status === 'incident' ? 'PrestaShop rejected the order (HTTP 422): {"products.0.id_product":"Product not found"}' : null,
            'created_at' => '2026-11-03 09:00:00', 'updated_at' => '2026-11-03 09:30:00',
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function outbox(int $id): array
    {
        return $this->connection->fetchAssociative('SELECT * FROM outbox WHERE id = ?', [$id]);
    }

    public function testDiscardStoresWhoWhenAndWhyAndKeepsTheError(): void
    {
        $incident = $this->row($this->sale('Z36000001'), 1, 'incident');

        $this->assertSame([$incident], $this->incidents()->discard($incident, 4, 'Producto borrado en PrestaShop'));

        $row = $this->outbox($incident);
        $this->assertSame('discarded', $row['status']);
        $this->assertSame('2026-11-03 12:40:00', $row['discarded_at']);
        $this->assertSame(4, (int) $row['discarded_by_user_id']);
        $this->assertSame('Producto borrado en PrestaShop', $row['discard_reason']);
        $this->assertStringContainsString('Product not found', (string) $row['last_error'], 'the error stays for the trace');
        $this->assertSame(1, (int) $row['attempts']);
    }

    public function testOnlyAnIncidentIsDiscarded(): void
    {
        $sale = $this->sale('Z36000002');
        $pending = $this->row($sale, 1, 'pending');
        $done = $this->row($this->sale('Z36000003'), 1, 'done');
        $incident = $this->row($this->sale('Z36000004'), 1, 'incident');

        $this->assertNull($this->incidents()->discard($pending, 4, null));
        $this->assertNull($this->incidents()->discard($done, 4, null));
        $this->assertNotNull($this->incidents()->discard($incident, 4, null));
        $this->assertNull($this->incidents()->discard($incident, 4, null), 'discarded already');
        $this->assertNull($this->incidents()->discard(999999999, 4, null));
        $this->assertSame('pending', $this->outbox($pending)['status']);
        $this->assertSame('done', $this->outbox($done)['status']);
        $this->assertNull($this->outbox($incident)['discard_reason'], 'no reason given');
    }

    public function testDiscardingASaleDiscardsItsLaterRowsAndWhatDependsOnIt(): void
    {
        $sale = $this->sale('Z36000010');
        $saleRow = $this->row($sale, 1, 'incident');
        $refund = $this->row($sale, 2, 'pending', 'refund');
        $exchange = $this->row($sale, 3, 'incident', 'refund');
        $dependant = $this->row(null, 1, 'pending', 'restock', $saleRow);
        $other = $this->row($this->sale('Z36000011'), 1, 'pending');

        $this->assertSame(2 + 1, $this->listed($saleRow)['dependants'], 'the confirmation says how many go with it');

        $discarded = $this->incidents()->discard($saleRow, 4, 'Producto borrado');

        $this->assertSame([$saleRow, $refund, $exchange, $dependant], $discarded);
        foreach ([$refund, $exchange, $dependant] as $id) {
            $row = $this->outbox($id);
            $this->assertSame('discarded', $row['status']);
            $this->assertSame(4, (int) $row['discarded_by_user_id']);
            $this->assertSame('2026-11-03 12:40:00', $row['discarded_at']);
        }
        $this->assertSame('pending', $this->outbox($other)['status'], 'other sales are untouched');
    }

    public function testDiscardingACustomerDiscardsItsSalesAndWhatWaitsBehindThem(): void
    {
        $customer = $this->row(null, 1, 'incident', 'customer');
        $sale = $this->sale('Z36000020');
        $saleRow = $this->row($sale, 1, 'pending', 'sale', $customer);
        $refund = $this->row($sale, 2, 'pending', 'refund');
        $free = $this->row($this->sale('Z36000021'), 1, 'pending');

        $this->assertSame(2, $this->listed($customer)['dependants']);
        $this->assertSame([$customer, $saleRow, $refund], $this->incidents()->discard($customer, 4, null));
        $this->assertSame('pending', $this->outbox($free)['status']);
    }

    public function testDiscardingARefundLeavesItsSaleAlone(): void
    {
        $sale = $this->sale('Z36000030');
        $saleRow = $this->row($sale, 1, 'done');
        $refund = $this->row($sale, 2, 'incident', 'refund');
        $later = $this->row($sale, 3, 'pending', 'refund');

        $this->assertSame([$refund, $later], $this->incidents()->discard($refund, 4, null), 'a later row would wait for ever behind it');
        $this->assertSame('done', $this->outbox($saleRow)['status']);
    }

    public function testCountsAndTheIncidentListLeaveDiscardedRowsOut(): void
    {
        $this->connection->beginTransaction();
        $before = $this->incidents()->counts();

        $sale = $this->sale('Z36000040');
        $saleRow = $this->row($sale, 1, 'incident');
        $this->row($sale, 2, 'pending', 'refund');
        $customer = $this->row(null, 1, 'incident', 'customer');
        $this->row($this->sale('Z36000041'), 1, 'pending', 'sale', $customer);
        $this->incidents()->discard($saleRow, 4, null);
        $this->incidents()->discard($customer, 4, null);
        $after = $this->incidents()->counts();
        $listed = array_column($this->incidents()->list(), 'id');
        $this->connection->rollBack();

        $this->assertSame(['pending' => 0, 'incidents' => 0, 'blocked' => 0], [
            'pending' => $after['pending'] - $before['pending'],
            'incidents' => $after['incidents'] - $before['incidents'],
            'blocked' => $after['blocked'] - $before['blocked'],
        ]);
        $this->assertNotContains($saleRow, $listed);
        $this->assertNotContains($customer, $listed);
    }

    public function testWhatWaitsBehindLeavesDiscardedRowsOut(): void
    {
        $sale = $this->sale('Z36000050');
        $saleRow = $this->row($sale, 1, 'incident');
        $this->row($sale, 2, 'discarded', 'refund');
        $this->row($sale, 3, 'pending', 'refund');

        $this->assertSame(1, $this->listed($saleRow)['waiting_behind']);
    }

    public function testTheRecentDiscardedListSaysWhoWhenAndWhyNewestFirst(): void
    {
        $roleId = $this->connection->fetchOne('SELECT id FROM role ORDER BY id LIMIT 1');
        if ($roleId === false) {
            $this->connection->insert('role', ['name' => 't36-role', 'permissions' => '[]']);
            $roleId = $this->connection->lastInsertId();
        }
        $this->connection->insert('user', ['role_id' => (int) $roleId, 'name' => 'Admin T36', 'pin_hash' => 'x']);
        $userId = (int) $this->connection->lastInsertId();
        try {
            $first = $this->row($this->sale('Z36000060'), 1, 'incident');
            $second = $this->row($this->sale('Z36000061'), 1, 'incident');
            $this->incidents()->discard($first, $userId, 'Producto borrado');
            $this->now = $this->now->modify('+5 minutes');
            $this->incidents()->discard($second, $userId, null);

            $mine = array_values(array_filter($this->incidents()->discarded(), static fn (array $r): bool => in_array($r['id'], [$first, $second], true)));

            $this->assertSame([$second, $first], array_column($mine, 'id'));
            $this->assertSame('Z36000060', $mine[1]['reference']);
            $this->assertSame('sale', $mine[1]['type']);
            $this->assertSame('Admin T36', $mine[1]['discarded_by']);
            $this->assertSame('2026-11-03 12:40:00', $mine[1]['discarded_at']);
            $this->assertSame('Producto borrado', $mine[1]['discard_reason']);
            $this->assertStringContainsString('Product not found', (string) $mine[1]['last_error']);
            $this->assertNull($mine[0]['discard_reason']);
            $this->assertLessThanOrEqual(20, count($this->incidents()->discarded()));
        } finally {
            $this->connection->executeStatement('DELETE FROM user WHERE id = ?', [$userId]);
        }
    }

    public function testRetryStillOnlyRequeuesAnIncidentNotADiscardedRow(): void
    {
        $incident = $this->row($this->sale('Z36000070'), 1, 'incident');
        $this->incidents()->discard($incident, 4, null);

        $this->assertFalse($this->incidents()->retry($incident));
        $this->assertSame('discarded', $this->outbox($incident)['status']);
    }

    /** @return array<string, mixed> */
    private function listed(int $id): array
    {
        $rows = array_values(array_filter($this->incidents()->list(), static fn (array $r): bool => $r['id'] === $id));
        $this->assertCount(1, $rows);

        return $rows[0];
    }
}
