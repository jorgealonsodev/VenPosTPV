<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\PrintAgent;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use VenPosTPV\Service\PrintAgent\DrawerAudit;
use VenPosTPV\Service\PrintAgent\PrintJobException;
use VenPosTPV\Service\PrintAgent\PrintJobQueue;
use VenPosTPV\Service\PrintAgent\PrintRetention;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * docs/prd/agente-impresion.md T2-T7, T11, S4: the queue of each till for its print
 * agent, on MySQL with a mock clock.
 */
final class PrintJobQueueMySqlIntegrationTest extends TestCase
{
    use PrintAgentFixtures;

    /** Every byte value, as ESC/POS uses them (0x80 is "€" in WPC1252). */
    private string $bytes;

    private ?Connection $connection = null;
    private MockClock $clock;
    private PrintJobQueue $queue;
    private int $tillA = 0;
    private int $tillB = 0;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->cleanPrintAgentFixtures($this->connection);
        $this->clock = new MockClock('2026-11-03 10:00:00');
        $this->queue = new PrintJobQueue($this->connection, $this->clock);
        $this->tillA = $this->till($this->connection, 'Caja agente A');
        $this->tillB = $this->till($this->connection, 'Caja agente B');
        $this->bytes = "\x1B@\x1Bt\x10" . implode('', array_map('chr', range(0, 255))) . "\x1DV\x41\x03";
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->cleanPrintAgentFixtures($this->connection);
            $this->connection->close();
        }
    }

    public function testTheAgentGetsTheExactBytesWithTheirHashAndAClaimToken(): void
    {
        $id = $this->queue->enqueue($this->tillA, 'ticket', $this->bytes, 'sale', '-15', 3);

        $jobs = $this->queue->claim($this->tillA);

        $this->assertCount(1, $jobs);
        $this->assertSame($id, $jobs[0]['id']);
        $this->assertSame('ticket', $jobs[0]['type']);
        $this->assertSame('ticket', $jobs[0]['target']);
        $this->assertSame($this->bytes, base64_decode($jobs[0]['payload_b64'], true));
        $this->assertSame(hash('sha256', $this->bytes), $jobs[0]['sha256']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $jobs[0]['claim_token']);
        $this->assertSame((new \DateTimeImmutable('2026-11-03 10:02:00', $this->clock->now()->getTimezone()))->format(\DATE_ATOM), $jobs[0]['expires_at'], 'a ticket expires after 2 minutes');
        $row = $this->connection->fetchAssociative('SELECT status, lease_until, created_by_user_id, payload_size FROM print_job WHERE id = ?', [$id]);
        $this->assertSame('claimed', $row['status']);
        $this->assertSame('2026-11-03 10:01:00', $row['lease_until'], '60 s lease');
        $this->assertSame(3, (int) $row['created_by_user_id']);
        $this->assertSame(\strlen($this->bytes), (int) $row['payload_size']);
        $this->assertSame([], $this->queue->claim($this->tillA), 'a claimed job is not handed out twice');
    }

    public function testExpiryDependsOnTheType(): void
    {
        $drawer = $this->queue->enqueue($this->tillA, 'drawer', 'ignored', 'drawer');
        $label = $this->queue->enqueue($this->tillA, 'label', 'LBL', 'label');

        $rows = $this->connection->fetchAllKeyValue('SELECT id, expires_at FROM print_job WHERE terminal_id = ?', [$this->tillA]);
        $this->assertSame('2026-11-03 10:00:15', $rows[$drawer], 'drawer 15 s');
        $this->assertSame('2026-11-04 10:00:00', $rows[$label], 'labels 24 h');
        $this->assertNull($this->connection->fetchOne('SELECT payload FROM print_job WHERE id = ?', [$drawer]), 'a drawer job carries no data (T8)');

        $jobs = $this->queue->claim($this->tillA);
        $this->assertSame('', $jobs[0]['payload_b64']);
        $this->assertSame(hash('sha256', ''), $jobs[0]['sha256']);
        $this->assertSame('label', $jobs[1]['target']);
    }

    public function testDataLimits(): void
    {
        try {
            $this->queue->enqueue($this->tillA, 'ticket', str_repeat('x', PrintJobQueue::MAX_PAYLOAD_BYTES + 1), 'sale');
            $this->fail('1 MB per job');
        } catch (\LengthException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->queue->enqueue($this->tillA, 'ticket', '', 'sale');
    }

    public function testTicketsAndDrawerGoBeforeLabels(): void
    {
        $label1 = $this->queue->enqueue($this->tillA, 'label', 'L1', 'label');
        $label2 = $this->queue->enqueue($this->tillA, 'label', 'L2', 'label');
        $ticket = $this->queue->enqueue($this->tillA, 'ticket', 'T', 'sale');
        $drawer = $this->queue->enqueue($this->tillA, 'drawer', null, 'drawer');

        $this->assertSame([$ticket, $drawer, $label1], array_column($this->queue->claim($this->tillA), 'id'), 'at most one label per poll');
        $later = $this->queue->enqueue($this->tillA, 'ticket', 'T2', 'sale');
        $this->assertSame([$later, $label2], array_column($this->queue->claim($this->tillA), 'id'), 'AC14: a new ticket never waits behind the labels');
    }

    public function testTheAgentReportingAnExpiredJobIsAccepted(): void
    {
        $id = $this->queue->enqueue($this->tillA, 'drawer', null, 'drawer');
        $token = $this->queue->claim($this->tillA)[0]['claim_token'];

        $this->assertSame('expired', $this->queue->complete($this->tillA, $id, $token, 'failed', 'job_expired', 'expired before printing'), 'the agent clock said so: no retry');
        $this->assertSame('expired', $this->connection->fetchOne('SELECT status FROM print_job WHERE id = ?', [$id]));
        $this->clock->sleep(20);
        $this->assertSame('expired', $this->queue->complete($this->tillA, $id, $token, 'failed', 'job_expired', null), 'also after the server expired it');
    }

    public function testAnAgentOnlySeesAndConfirmsItsOwnTillsJobs(): void
    {
        $jobB = $this->queue->enqueue($this->tillB, 'ticket', 'B', 'sale');

        $this->assertSame([], $this->queue->claim($this->tillA));
        $claimB = $this->queue->claim($this->tillB)[0];

        foreach (['complete' => fn () => $this->queue->complete($this->tillA, $jobB, $claimB['claim_token'], 'done'), 'extend' => fn () => $this->queue->extend($this->tillA, $jobB, $claimB['claim_token'])] as $what => $call) {
            try {
                $call();
                $this->fail($what . ' of another till must fail');
            } catch (PrintJobException $e) {
                $this->assertSame(404, $e->httpStatus, $what);
            }
        }
        $this->assertSame('claimed', $this->connection->fetchOne('SELECT status FROM print_job WHERE id = ?', [$jobB]));
    }

    public function testConfirmingIsIdempotentAndMarksTheSalePrinted(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $this->connection->insert('order_meta', ['reference' => null, 'terminal_id' => $this->tillA, 'session_id' => 0, 'user_id' => 1, 'payment_breakdown' => '{}']);
        $orderId = (int) $this->connection->lastInsertId();
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        try {
            $id = $this->queue->enqueue($this->tillA, 'ticket', 'T', 'sale', (string) -$orderId);
            $token = $this->queue->claim($this->tillA)[0]['claim_token'];

            $this->assertSame('done', $this->queue->complete($this->tillA, $id, $token, 'done'));
            $this->assertSame('done', $this->queue->complete($this->tillA, $id, $token, 'done'), 'repeated');
            $this->assertSame('done', $this->queue->complete($this->tillA, $id, $token, 'failed', 'x', 'late'), 'a later failure does not undo it');

            $row = $this->connection->fetchAssociative('SELECT status, finished_at, error FROM print_job WHERE id = ?', [$id]);
            $this->assertSame(['status' => 'done', 'finished_at' => '2026-11-03 10:00:00', 'error' => null], $row);
            $this->assertSame(1, (int) $this->connection->fetchOne('SELECT printed FROM order_meta WHERE id = ?', [$orderId]));
            $this->assertSame(1, (int) $this->connection->fetchOne('SELECT print_attempts FROM order_meta WHERE id = ?', [$orderId]), 'marked once');
        } finally {
            $this->connection->executeStatement('DELETE FROM order_meta WHERE id = ?', [$orderId]);
        }
    }

    public function testAnUnconfirmedLeaseReturnsTheJobAndTheOldClaimIsStale(): void
    {
        $id = $this->queue->enqueue($this->tillA, 'label', 'L', 'label');
        $first = $this->queue->claim($this->tillA)[0]['claim_token'];

        $this->clock->sleep(59);
        $this->assertSame([], $this->queue->claim($this->tillA), 'still leased');
        $this->clock->sleep(1);
        $again = $this->queue->claim($this->tillA);
        $this->assertSame([$id], array_column($again, 'id'), 'back in the queue after 60 s (T4)');
        $this->assertNotSame($first, $again[0]['claim_token']);

        try {
            $this->queue->complete($this->tillA, $id, $first, 'done');
            $this->fail('the first claim is stale');
        } catch (PrintJobException $e) {
            $this->assertSame([409, 'claim_stale'], [$e->httpStatus, $e->errorCode]);
        }
        $this->assertSame('done', $this->queue->complete($this->tillA, $id, $again[0]['claim_token'], 'done'));
    }

    public function testExtendingKeepsTheLease(): void
    {
        $id = $this->queue->enqueue($this->tillA, 'label', 'L', 'label');
        $token = $this->queue->claim($this->tillA)[0]['claim_token'];

        $this->clock->sleep(50);
        $lease = $this->queue->extend($this->tillA, $id, $token);
        $this->assertSame('2026-11-03 10:01:50', $lease->format('Y-m-d H:i:s'));
        $this->clock->sleep(30);
        $this->assertSame([], $this->queue->claim($this->tillA), 'not returned: the lease was extended');

        try {
            $this->queue->extend($this->tillA, $id, 'not-the-token');
            $this->fail('another token cannot extend');
        } catch (PrintJobException $e) {
            $this->assertSame(409, $e->httpStatus);
        }
    }

    public function testAResultAfterExpiryIsRefusedAndNothingExpiredIsHandedOut(): void
    {
        $drawer = $this->queue->enqueue($this->tillA, 'drawer', null, 'drawer');
        $token = $this->queue->claim($this->tillA)[0]['claim_token'];
        $this->queue->enqueue($this->tillA, 'ticket', 'T', 'sale');

        $this->clock->sleep(121);
        try {
            $this->queue->complete($this->tillA, $drawer, $token, 'done');
            $this->fail('expired');
        } catch (PrintJobException $e) {
            $this->assertSame([410, 'job_expired'], [$e->httpStatus, $e->errorCode]);
        }
        $this->assertSame([], $this->queue->claim($this->tillA), 'the expired ticket is not printed (T6)');
        $this->assertSame(['expired', 'expired'], $this->connection->fetchFirstColumn('SELECT status FROM print_job WHERE terminal_id = ? ORDER BY id', [$this->tillA]));
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM print_job WHERE terminal_id = ? AND finished_at IS NULL', [$this->tillA]));
        try {
            $this->queue->complete($this->tillA, $drawer, $token, 'done');
            $this->fail('still expired');
        } catch (PrintJobException $e) {
            $this->assertSame(410, $e->httpStatus);
        }
    }

    public function testAFailureIsRetriedAfter2And5And15SecondsWithinItsExpiry(): void
    {
        $id = $this->queue->enqueue($this->tillA, 'ticket', 'T', 'sale');
        $long = str_repeat('é', 600);

        foreach ([2, 5, 15] as $delay) {
            $token = $this->queue->claim($this->tillA)[0]['claim_token'];
            $this->assertSame('pending', $this->queue->complete($this->tillA, $id, $token, 'failed', 'paper_out', $long));
            $this->assertSame('pending', $this->queue->complete($this->tillA, $id, $token, 'failed', 'paper_out', $long), 'repeated failure');
            $this->clock->sleep($delay - 1);
            $this->assertSame([], $this->queue->claim($this->tillA), 'not before ' . $delay . ' s');
            $this->clock->sleep(1);
        }
        $token = $this->queue->claim($this->tillA)[0]['claim_token'];
        $this->assertSame('failed', $this->queue->complete($this->tillA, $id, $token, 'failed', 'paper_out', $long));

        $row = $this->connection->fetchAssociative('SELECT status, attempts, error_code, error FROM print_job WHERE id = ?', [$id]);
        $this->assertSame('failed', $row['status']);
        $this->assertSame(4, (int) $row['attempts']);
        $this->assertSame('paper_out', $row['error_code']);
        $this->assertSame(500, mb_strlen($row['error']), 'error text cut to 500 characters (E8)');
    }

    public function testADoneAfterAnEarlierFailureClearsTheOldError(): void
    {
        $id = $this->queue->enqueue($this->tillA, 'ticket', 'T', 'sale');
        $token = $this->queue->claim($this->tillA)[0]['claim_token'];
        $this->assertSame('pending', $this->queue->complete($this->tillA, $id, $token, 'failed', 'spooler_unavailable', 'Spooler down'));
        $this->clock->sleep(2);
        $token = $this->queue->claim($this->tillA)[0]['claim_token'];

        $this->assertSame('done', $this->queue->complete($this->tillA, $id, $token, 'done'));

        $row = $this->connection->fetchAssociative('SELECT status, attempts, error_code, error FROM print_job WHERE id = ?', [$id]);
        $this->assertSame(['done', 1, null, null], [$row['status'], (int) $row['attempts'], $row['error_code'], $row['error']], 'a printed job shows no error');
        $this->assertSame([null, null], [$this->queue->find($id)['error_code'], $this->queue->find($id)['error']]);
    }

    public function testADoneJustAfterExpiryIsAcceptedForTicketsAndLabelsButNotTheDrawer(): void
    {
        $ticket = $this->queue->enqueue($this->tillA, 'ticket', 'T', 'sale');
        $label = $this->queue->enqueue($this->tillA, 'label', 'L', 'label');
        $drawer = $this->queue->enqueue($this->tillA, 'drawer', null, 'drawer');
        $tokens = array_column($this->queue->claim($this->tillA), 'claim_token', 'id');
        $this->connection->executeStatement('UPDATE print_job SET expires_at = ? WHERE id = ?', ['2026-11-03 10:00:10', $label]);

        // The ticket expires at 10:02:00; it printed and the answer arrives 30 s later.
        $this->clock->sleep(150);
        $this->queue->resolveDue($this->tillA);
        $this->assertSame('expired', $this->connection->fetchOne('SELECT status FROM print_job WHERE id = ?', [$ticket]));
        $this->assertSame('done', $this->queue->complete($this->tillA, $ticket, $tokens[$ticket], 'done'), 'T6: it did print');
        $this->assertSame('done', $this->queue->complete($this->tillA, $ticket, $tokens[$ticket], 'done'), 'repeated');
        $this->assertNotNull($this->connection->fetchOne('SELECT finished_at FROM print_job WHERE id = ?', [$ticket]));

        try {
            $this->queue->complete($this->tillA, $drawer, $tokens[$drawer], 'done');
            $this->fail('the drawer keeps strict expiry');
        } catch (PrintJobException $e) {
            $this->assertSame([410, 'job_expired'], [$e->httpStatus, $e->errorCode]);
        }
        try {
            $this->queue->complete($this->tillA, $ticket + 1000, $tokens[$ticket], 'done');
            $this->fail('unknown job');
        } catch (PrintJobException $e) {
            $this->assertSame(404, $e->httpStatus);
        }
        // The label expired at 10:00:10: 140 s ago, past the 30 s grace.
        try {
            $this->queue->complete($this->tillA, $label, $tokens[$label], 'done');
            $this->fail('past the grace');
        } catch (PrintJobException $e) {
            $this->assertSame(410, $e->httpStatus);
        }
        try {
            $this->queue->complete($this->tillA, $ticket, 'another-token', 'done');
            $this->fail('another token');
        } catch (PrintJobException $e) {
            $this->assertContains($e->httpStatus, [409, 410]);
        }
    }

    public function testADoneWithinTheGraceAfterExpiryWithoutAnySweepIsAccepted(): void
    {
        $label = $this->queue->enqueue($this->tillA, 'label', 'L', 'label');
        $token = $this->queue->claim($this->tillA)[0]['claim_token'];
        $this->connection->executeStatement('UPDATE print_job SET expires_at = ? WHERE id = ?', ['2026-11-03 10:00:30', $label]);
        $this->queue->extend($this->tillA, $label, $token);
        $this->clock->sleep(55);

        $this->assertSame('done', $this->queue->complete($this->tillA, $label, $token, 'done'), '25 s after expiry, still claimed');
        $this->clock->sleep(10);
        $this->assertSame('done', $this->queue->complete($this->tillA, $label, $token, 'done'), 'repeated after the grace answers what the first did');
    }

    public function testALongPollClaimHasAShorterLease(): void
    {
        $id = $this->queue->enqueue($this->tillA, 'ticket', 'T', 'sale');
        $token = $this->queue->claim($this->tillA, PrintJobQueue::CLAIM_LIMIT, PrintJobQueue::LONG_POLL_LEASE_SECONDS)[0]['claim_token'];
        $this->assertSame('2026-11-03 10:00:30', $this->connection->fetchOne('SELECT lease_until FROM print_job WHERE id = ?', [$id]));

        $this->clock->sleep(30);
        $again = $this->queue->claim($this->tillA);
        $this->assertSame([$id], array_column($again, 'id'), 'back in the queue after 30 s');
        $this->assertNotSame($token, $again[0]['claim_token']);
        $this->assertSame('2026-11-03 10:01:30', $this->connection->fetchOne('SELECT lease_until FROM print_job WHERE id = ?', [$id]), 'a short poll keeps 60 s');
    }

    public function testNoRetryPastTheExpiry(): void
    {
        $id = $this->queue->enqueue($this->tillA, 'drawer', null, 'drawer');
        $this->clock->sleep(14);
        $token = $this->queue->claim($this->tillA)[0]['claim_token'];

        $this->assertSame('failed', $this->queue->complete($this->tillA, $id, $token, 'failed', 'offline', 'Printer offline'), 'a 2 s retry would pass the 15 s expiry');
    }

    public function testDrawerAuditFollowsTheJob(): void
    {
        $audit = new DrawerAudit($this->connection, null, $this->clock);
        $done = $this->queue->enqueue($this->tillA, 'drawer', null, 'drawer');
        $audit->record($this->tillA, 'agent', DrawerAudit::RESULT_QUEUED, 'sale', 7, 12, 'A26000012', $done, true);
        $lost = $this->queue->enqueue($this->tillA, 'drawer', null, 'drawer');
        $audit->record($this->tillA, 'agent', DrawerAudit::RESULT_QUEUED, 'button', 7, null, null, $lost, true);

        $jobs = $this->queue->claim($this->tillA);
        $this->queue->complete($this->tillA, $done, $jobs[0]['claim_token'], 'done');
        $this->clock->sleep(16);
        $this->queue->resolveDue($this->tillA);

        $rows = $this->connection->fetchAllAssociative('SELECT job_id, user_id, reason, order_id, sale_reference, result FROM drawer_audit WHERE terminal_id = ? ORDER BY id', [$this->tillA]);
        $this->assertSame([
            ['job_id' => $done, 'user_id' => 7, 'reason' => 'sale', 'order_id' => 12, 'sale_reference' => 'A26000012', 'result' => 'done'],
            ['job_id' => $lost, 'user_id' => 7, 'reason' => 'button', 'order_id' => null, 'sale_reference' => null, 'result' => 'expired'],
        ], array_map(static fn (array $r): array => ['job_id' => (int) $r['job_id'], 'user_id' => (int) $r['user_id'], 'reason' => $r['reason'], 'order_id' => $r['order_id'] !== null ? (int) $r['order_id'] : null, 'sale_reference' => $r['sale_reference'], 'result' => $r['result']], $rows));
    }

    public function testRetentionClearsDataAfter24HoursAndJobsAfter30DaysButNeverTheDrawerAudit(): void
    {
        $retention = new PrintRetention($this->connection, $this->queue, $this->clock);
        $old = $this->queue->enqueue($this->tillA, 'ticket', 'customer data', 'sale');
        $this->queue->complete($this->tillA, $old, $this->queue->claim($this->tillA)[0]['claim_token'], 'done');
        (new DrawerAudit($this->connection, null, $this->clock))->record($this->tillA, 'agent', DrawerAudit::RESULT_QUEUED, 'button', null, null, null, $old, true);
        $pending = $this->queue->enqueue($this->tillA, 'label', 'label data', 'label');

        $this->clock->sleep(23 * 3600);
        $retention->run();
        $this->assertNotNull($this->connection->fetchOne('SELECT payload FROM print_job WHERE id = ?', [$old]), 'kept for 24 h');

        $this->clock->sleep(3600);
        $retention->run();
        $this->assertNull($this->connection->fetchOne('SELECT payload FROM print_job WHERE id = ?', [$old]), 'data removed 24 h after it finished');
        $this->assertSame('done', $this->connection->fetchOne('SELECT status FROM print_job WHERE id = ?', [$old]), 'the job stays in the history');

        $this->clock->sleep(3600);
        $retention->run();
        $this->assertSame('expired', $this->connection->fetchOne('SELECT status FROM print_job WHERE id = ?', [$pending]), 'expired labels are closed');

        $this->clock->sleep(29 * 86400);
        $retention->run();
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM print_job WHERE terminal_id = ?', [$this->tillA]), 'jobs deleted after 30 days');
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM drawer_audit WHERE terminal_id = ?', [$this->tillA]), 'the drawer audit is never purged (T11)');
    }

    public function testHistoryFiltersByTillAndStatusWithACursor(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; ++$i) {
            $ids[] = $this->queue->enqueue($this->tillA, 'ticket', 'T' . $i, 'sale');
        }
        $this->queue->enqueue($this->tillB, 'ticket', 'B', 'sale');

        $page = $this->queue->history($this->tillA, null, null, 2);
        $this->assertSame([$ids[4], $ids[3]], array_column($page['items'], 'id'));
        $this->assertSame($ids[3], $page['next_cursor']);
        $page = $this->queue->history($this->tillA, null, $page['next_cursor'], 2);
        $this->assertSame([$ids[2], $ids[1]], array_column($page['items'], 'id'));
        $last = $this->queue->history($this->tillA, null, $page['next_cursor'], 2);
        $this->assertSame([$ids[0]], array_column($last['items'], 'id'));
        $this->assertNull($last['next_cursor']);

        $this->assertSame([], $this->queue->history($this->tillA, 'done', null, 50)['items']);
        $item = $this->queue->history($this->tillB, 'pending', null, 50)['items'][0];
        $this->assertSame('Caja agente B', $item['terminal_name']);
        $this->assertArrayNotHasKey('payload', $item, 'never the data of a job');
    }

    public function testFindReportsAnExpiredJobAsExpired(): void
    {
        $id = $this->queue->enqueue($this->tillA, 'ticket', 'T', 'sale');
        $this->assertSame('pending', $this->queue->find($id)['status']);
        $this->clock->sleep(120);

        $job = $this->queue->find($id);
        $this->assertSame('expired', $job['status']);
        $this->assertNull($job['error']);
        $this->assertNull($this->queue->find(PHP_INT_MAX));
    }
}
