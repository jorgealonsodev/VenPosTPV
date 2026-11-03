<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Incident;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Incident\OutboxIncidents;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * PRD I1, I3: the sync indicator counts the uploads still to do and the incidents;
 * the administrator lists the incidents and retries them, one or a whole sale in order.
 */
final class OutboxIncidentsMySqlIntegrationTest extends TestCase
{
    private const SESSION_ID = 9707;

    private ?Connection $connection = null;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();
        $this->now = new \DateTimeImmutable('2026-11-03 10:15:00');
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
        foreach ($c->fetchFirstColumn('SELECT id FROM order_meta WHERE session_id = ?', [self::SESSION_ID]) as $id) {
            $c->executeStatement('DELETE FROM outbox WHERE order_id = ?', [(int) $id]);
        }
        $c->executeStatement('DELETE FROM outbox WHERE request_id LIKE ?', ['t7-%']);
        $c->executeStatement('DELETE FROM refund WHERE request_id LIKE ?', ['t7-%']);
        $c->executeStatement('DELETE FROM order_meta WHERE session_id = ?', [self::SESSION_ID]);
    }

    private function incidents(): OutboxIncidents
    {
        return new OutboxIncidents($this->connection, fn (): \DateTimeImmutable => $this->now);
    }

    private function sale(string $reference, ?string $psReference = null): int
    {
        $this->connection->insert('order_meta', [
            'reference' => $reference, 'ps_reference' => $psReference, 'terminal_id' => 1, 'session_id' => self::SESSION_ID, 'user_id' => 1, 'synced' => 0,
            'payment_breakdown' => json_encode(['reference' => $reference, 'total' => 10.0, 'lines' => []], JSON_THROW_ON_ERROR),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    private function row(?int $orderId, int $sequence, string $status, string $type = 'sale', int $attempts = 3, ?string $error = 'HTTP 422: Product 77 does not exist'): int
    {
        $this->connection->insert('outbox', [
            'type' => $type, 'order_id' => $orderId, 'sequence' => $sequence, 'request_id' => 't7-' . bin2hex(random_bytes(8)),
            'payload' => '{}', 'status' => $status, 'attempts' => $attempts, 'next_attempt_at' => '2026-11-03 09:00:00',
            'claim_token' => $status === 'processing' ? 'tok' : null, 'last_error' => $error,
            'created_at' => '2026-11-03 09:00:00', 'updated_at' => '2026-11-03 09:30:00',
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function outbox(int $id): array
    {
        return $this->connection->fetchAssociative('SELECT * FROM outbox WHERE id = ?', [$id]);
    }

    public function testTheIndicatorCountsPendingAndProcessingUploadsAndIncidents(): void
    {
        // A snapshot: rows other tests commit meanwhile stay out of the difference.
        $this->connection->beginTransaction();
        $before = $this->incidents()->counts();

        $sale = $this->sale('Z26000001');
        $this->row($sale, 1, 'pending');
        $this->row($sale, 2, 'processing', 'refund');
        $other = $this->sale('Z26000002');
        $this->row($other, 1, 'incident');
        $this->row($other, 2, 'pending', 'refund');
        $this->row($this->sale('Z26000003'), 1, 'done');
        $after = $this->incidents()->counts();
        $this->connection->rollBack();

        $this->assertSame(['pending' => 3, 'incidents' => 1], [
            'pending' => $after['pending'] - $before['pending'],
            'incidents' => $after['incidents'] - $before['incidents'],
        ]);
    }

    public function testTheListShowsTypeTicketCodeCreditNoteDateErrorAttemptsAndWhatWaitsBehind(): void
    {
        $sale = $this->sale('Z26000010', 'XKQWERTYU');
        $saleRow = $this->row($sale, 1, 'incident', attempts: 2);
        $refundRow = $this->row($sale, 2, 'incident', 'refund', 1, 'HTTP 404: order not found');
        $this->connection->update('outbox', ['request_id' => 't7-refund-10'], ['id' => $refundRow]);
        $this->connection->insert('refund', [
            'request_id' => 't7-refund-10', 'status' => 'done', 'order_id' => $sale, 'credit_note_number' => 'R2026-T70321',
            'terminal_id' => 1, 'session_id' => self::SESSION_ID, 'user_id' => 1, 'reason_code' => 'defective', 'total' => 10,
        ]);
        $this->row($sale, 3, 'pending', 'refund');
        $this->row($this->sale('Z26000011'), 1, 'pending');

        $mine = array_values(array_filter($this->incidents()->list(), static fn (array $r): bool => \in_array($r['id'], [$saleRow, $refundRow], true)));

        $this->assertCount(2, $mine);
        $this->assertSame([
            'id' => $saleRow, 'type' => 'sale', 'order_id' => $sale, 'sequence' => 1, 'reference' => 'Z26000010', 'ps_reference' => 'XKQWERTYU',
            'refund_id' => null, 'credit_note_number' => null, 'attempts' => 2, 'last_error' => 'HTTP 422: Product 77 does not exist',
            'created_at' => '2026-11-03 09:00:00', 'updated_at' => '2026-11-03 09:30:00', 'waiting_behind' => 2,
        ], $mine[0]);
        $this->assertSame('refund', $mine[1]['type']);
        $this->assertSame('R2026-T70321', $mine[1]['credit_note_number']);
        $this->assertSame((int) $this->connection->fetchOne('SELECT id FROM refund WHERE request_id = ?', ['t7-refund-10']), $mine[1]['refund_id']);
        $this->assertSame('HTTP 404: order not found', $mine[1]['last_error']);
        $this->assertSame(1, $mine[1]['waiting_behind']);
    }

    public function testRetryPutsAnIncidentBackInTheQueueNowWithItsAttemptsReset(): void
    {
        $id = $this->row($this->sale('Z26000020'), 1, 'incident', attempts: 4);

        $this->assertTrue($this->incidents()->retry($id));

        $row = $this->outbox($id);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, (int) $row['attempts']);
        $this->assertSame('2026-11-03 10:15:00', $row['next_attempt_at']);
        $this->assertNull($row['claim_token']);
        $this->assertSame('2026-11-03 10:15:00', $row['updated_at']);
    }

    public function testRetryOnlyTouchesARowThatIsStillAnIncident(): void
    {
        $sale = $this->sale('Z26000030');
        $pending = $this->row($sale, 1, 'pending', attempts: 2);
        $processing = $this->row($this->sale('Z26000031'), 1, 'processing', attempts: 1);
        $done = $this->row($this->sale('Z26000032'), 1, 'done', attempts: 1);
        $before = array_map(fn (int $id): array => $this->outbox($id), [$pending, $processing, $done]);

        foreach ([$pending, $processing, $done, 99999999] as $id) {
            $this->assertFalse($this->incidents()->retry($id), 'row ' . $id);
        }
        $this->assertSame($before, array_map(fn (int $id): array => $this->outbox($id), [$pending, $processing, $done]));

        $incident = $this->row($this->sale('Z26000033'), 1, 'incident');
        $this->assertTrue($this->incidents()->retry($incident));
        $this->assertFalse($this->incidents()->retry($incident), 'a second retry finds it pending already');
    }

    public function testRetryingASaleRetriesAllItsIncidentsInSequenceOrder(): void
    {
        $sale = $this->sale('Z26000040');
        $refund2 = $this->row($sale, 3, 'incident', 'refund', 2);
        $saleRow = $this->row($sale, 1, 'incident', attempts: 5);
        $done = $this->row($sale, 2, 'done', 'refund', 1, null);
        $otherSale = $this->row($this->sale('Z26000041'), 1, 'incident');

        $this->assertSame([$saleRow, $refund2], $this->incidents()->retryOrder($sale));

        foreach ([$saleRow, $refund2] as $id) {
            $row = $this->outbox($id);
            $this->assertSame(['pending', 0, '2026-11-03 10:15:00'], [$row['status'], (int) $row['attempts'], $row['next_attempt_at']]);
        }
        $this->assertSame('done', $this->outbox($done)['status']);
        $this->assertSame('incident', $this->outbox($otherSale)['status'], 'another sale is not touched');
        $this->assertSame([], $this->incidents()->retryOrder($sale), 'nothing left to retry');
    }

    /** A sale for a customer created at the till waits through depends_on; its refunds wait behind it. */
    public function testWhatWaitsBehindACustomerIncidentIsCountedToo(): void
    {
        $customer = $this->row(null, 1, 'incident', 'customer', 1, 'HTTP 422: Validation error: "email"');
        $sale = $this->sale('Z26000050');
        $saleRow = $this->row($sale, 1, 'pending', attempts: 0);
        $this->connection->update('outbox', ['depends_on' => $customer], ['id' => $saleRow]);
        $this->row($sale, 2, 'pending', 'refund', 0);
        $this->row($this->sale('Z26000051'), 1, 'pending', attempts: 0);

        $mine = array_values(array_filter($this->incidents()->list(), static fn (array $r): bool => $r['id'] === $customer));

        $this->assertSame('customer', $mine[0]['type']);
        $this->assertSame(2, $mine[0]['waiting_behind'], 'its sale and the refund behind that sale');
    }

    /** Pending uploads blocked by an incident do not upload on their own: the indicator says so. */
    public function testTheCountsSayHowManyPendingUploadsWaitForAnIncident(): void
    {
        $this->connection->beginTransaction();
        $before = $this->incidents()->counts();

        $rejected = $this->sale('Z26000060');
        $this->row($rejected, 1, 'incident');
        $this->row($rejected, 2, 'pending', 'refund', 0);
        $customer = $this->row(null, 1, 'incident', 'customer', 1);
        $waiting = $this->sale('Z26000061');
        $saleRow = $this->row($waiting, 1, 'pending', attempts: 0);
        $this->connection->update('outbox', ['depends_on' => $customer], ['id' => $saleRow]);
        $this->row($waiting, 2, 'pending', 'refund', 0);
        $free = $this->sale('Z26000062');
        $this->row($free, 1, 'pending', attempts: 0);
        $this->row($free, 2, 'processing', 'refund', 1);
        $after = $this->incidents()->counts();
        $this->connection->rollBack();

        $this->assertSame(['pending' => 5, 'incidents' => 2, 'blocked' => 3], [
            'pending' => $after['pending'] - $before['pending'],
            'incidents' => $after['incidents'] - $before['incidents'],
            'blocked' => ($after['blocked'] ?? 0) - ($before['blocked'] ?? 0),
        ]);
    }
}
