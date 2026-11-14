<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use VenPosTPV\EventListener\FiscalJournalFlushListener;
use VenPosTPV\Service\Fiscal\Journal\CanonicalJson;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalFlushResult;
use VenPosTPV\Service\Fiscal\Journal\JournalLocation;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * The writer hardening of T1b (fiscal-journal S2, S4, D3, D4) against a real
 * database, kept from its independent verification: any failure of the primary
 * sends the lines to the secondary and they come back once; the after-response
 * flush is bounded; damage is reported and no byte is changed; a document that
 * cannot be read blocks the flush gaplessly; the rows captured at issue survive in
 * the secondary's intent across processes.
 */
final class FiscalJournalWriterFallbackMySqlIntegrationTest extends TestCase
{
    private ?Connection $connection = null;
    private JournalPathFixtures $fixtures;
    private JournalHarness $journal;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->fixtures = new JournalPathFixtures($this->connection, fn (string $class) => $this->createMock($class));
        $this->fixtures->reset();
        $this->fixtures->install();
        $this->journal = new JournalHarness($this->connection);
        $this->journal->writer->createJournal();
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->fixtures->reset();
            $this->connection->close();
            $this->journal->remove();
        }
        $this->connection = null;
    }

    private function issue(int $n, ?JournalHarness $h = null): void
    {
        for ($i = 0; $i < $n; ++$i) {
            JournalIssue::ticket($this->connection, ($h ?? $this->journal)->intents, JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION);
        }
    }

    private function assertComplete(string $msg): void
    {
        $records = $this->connection->fetchAllAssociative('SELECT * FROM fiscal_record ORDER BY id');
        $lines = $this->journal->lines();
        $this->assertSame($records, array_column($lines, 'fiscal_record'), $msg . ': one line per document in chain order');
        $this->assertSame(range(1, \count($records)), array_column($lines, 'seq'), $msg . ': seq');
        $prev = '';
        foreach ($lines as $line) {
            $this->assertTrue(JournalLocation::selfValid($line), $msg);
            $this->assertSame($prev, $line['prev'], $msg);
            $prev = $line['line_hash'];
        }
        $this->assertSame([], $this->journal->secondary()->journalFiles(), $msg . ': secondary emptied');
    }

    private function monthFile(): string
    {
        $files = $this->journal->primary()->journalFiles();

        return end($files);
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->journal->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && basename((string) $f) !== 'journal.lock') {
                $out[substr((string) $f, \strlen($this->journal->root))] = hash_file('sha256', (string) $f);
            }
        }
        ksort($out);

        return $out;
    }

    /** @return array{0: string, 1: list<string>} status and the secondary seqs */
    private function flushOther(): array
    {
        $r = $this->journal->anotherProcess()->writer->flush();

        return [$r->status . ':' . ($r->directory === $this->journal->secondary()->directory() ? 'secondary' : 'primary'), array_column($this->journal->lines($this->journal->secondary()), 'seq')];
    }

    // ---------- (1) any failure of the primary goes to the secondary, and comes back once ----------

    public function testLockUnopenableFallsBackThenMerges(): void
    {
        $this->issue(2);
        $this->journal->writer->flush();
        $lock = $this->journal->primary()->directory() . '/journal.lock';
        @unlink($lock);
        mkdir($lock);
        $this->issue(3);
        $r = $this->journal->writer->flushAfterIssue(20, 2.0);
        $this->assertSame(FiscalJournalFlushResult::OK, $r->status);
        $this->assertSame([3, 4, 5], array_column($this->journal->lines($this->journal->secondary()), 'seq'));
        rmdir($lock);
        $this->issue(1);
        $this->journal->anotherProcess()->writer->flush();
        $this->assertComplete('lock');
    }

    public function testReadOnlyMonthFileFallsBackThenMerges(): void
    {
        $this->issue(2);
        $this->journal->writer->flush();
        $file = $this->monthFile();
        chmod($file, 0400);
        $this->issue(3);
        $this->assertSame(['ok:secondary', [3, 4, 5]], $this->flushOther());
        chmod($file, 0600);
        $this->issue(1);
        $this->journal->anotherProcess()->writer->flush();
        $this->assertComplete('ro-month');
    }

    public function testHeadJsonUnwritableFallsBackThenMerges(): void
    {
        $this->issue(2);
        $this->journal->writer->flush();
        $head = $this->journal->primary()->directory() . '/head.json';
        unlink($head);
        mkdir($head);
        $this->issue(3);
        [$status, $seqs] = $this->flushOther();
        $this->assertSame('ok:secondary', $status);
        $this->assertSame([3, 4, 5], $seqs);
        rmdir($head);
        $this->issue(1);
        $this->journal->anotherProcess()->writer->flush();
        $this->assertComplete('head');
    }

    public function testBothUnwritableNothingWrittenThenRecovers(): void
    {
        $this->issue(2);
        $this->journal->writer->flush();
        $p = $this->journal->primary()->directory();
        $s = $this->journal->secondary()->directory();
        chmod($p, 0500);
        chmod($s, 0500);
        $this->issue(2);
        $r = $this->journal->anotherProcess()->writer->flush();
        chmod($p, 0700);
        chmod($s, 0700);
        $this->assertSame(FiscalJournalFlushResult::UNWRITABLE, $r->status);
        $this->journal->anotherProcess()->writer->flush();
        $this->assertComplete('both');
    }

    // ---------- (2) the after-response flush is bounded and closes the response ----------

    public function testBusyIsBounded(): void
    {
        $this->issue(1);
        $h = fopen($this->journal->primary()->directory() . '/journal.lock', 'c');
        flock($h, \LOCK_EX);
        $t = microtime(true);
        $r = $this->journal->writer->flushAfterIssue(20, 2.0);
        $elapsed = microtime(true) - $t;
        flock($h, \LOCK_UN);
        fclose($h);
        $this->assertSame(FiscalJournalFlushResult::BUSY, $r->status);
        $this->assertGreaterThan(1.9, $elapsed);
        $this->assertLessThan(2.3, $elapsed);
        $this->assertSame([], $this->journal->lines());
        $this->journal->writer->flush();
        $this->assertComplete('busy');
    }

    public function testAfterResponseLimit(): void
    {
        $this->issue(25);
        $r = $this->journal->writer->flushAfterIssue(FiscalJournalFlushListener::AFTER_RESPONSE_LIMIT, FiscalJournalFlushListener::LOCK_WAIT_SECONDS);
        $this->assertSame(20, $r->written);
        $this->assertCount(20, $this->journal->lines());
        $this->journal->writer->flush();
        $this->assertComplete('limit');
    }

    public function testResponseHeaders(): void
    {
        $listener = new FiscalJournalFlushListener($this->journal->intents, $this->journal->writer);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $plain = new Response('{"a":1}');
        $listener->onResponse(new ResponseEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, $plain));
        $this->assertFalse($plain->headers->has('Connection'), 'no document issued: untouched');
        $this->issue(1);
        $response = new Response('{"ok":true}');
        $listener->onResponse(new ResponseEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, $response));
        $this->assertSame('11', $response->headers->get('Content-Length'));
        $this->assertSame('close', $response->headers->get('Connection'));
    }

    // ---------- (3) damage is reported, never rebuilt: no byte changes ----------

    private function damage(callable $how, string $expectStatus, ?string $expectReason): void
    {
        $this->issue(3);
        $this->journal->writer->flush();
        $how();
        $this->issue(1);
        $before = $this->snapshot();
        $r = $this->journal->anotherProcess()->writer->flush();
        $this->assertSame($expectStatus, $r->status);
        if ($expectReason !== null) {
            $this->assertSame($expectReason, $r->reason);
        }
        $this->assertSame($before, $this->snapshot(), 'no byte changed');
    }

    private function rewriteLast(callable $mutate, bool $rehash): void
    {
        $file = $this->monthFile();
        $rows = explode("\n", rtrim((string) file_get_contents($file), "\n"));
        $last = json_decode(array_pop($rows), true);
        $last = $mutate($last);
        if ($rehash) {
            unset($last['line_hash']);
            $last['line_hash'] = CanonicalJson::lineHash($last);
        }
        $rows[] = json_encode($last, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
        file_put_contents($file, implode("\n", $rows) . "\n");
    }

    public function testBadHashLast(): void
    {
        $this->damage(fn () => $this->rewriteLast(function ($l) { $l['fiscal_record']['total'] = '99.99'; return $l; }, false), 'damaged', 'line');
    }

    public function testChainBrokenLast(): void
    {
        $this->damage(fn () => $this->rewriteLast(function ($l) { $l['prev'] = str_repeat('a', 64); return $l; }, true), 'damaged', null);
    }

    public function testRemovedLastLine(): void
    {
        $this->damage(function () {
            $file = $this->monthFile();
            $rows = explode("\n", rtrim((string) file_get_contents($file), "\n"));
            array_pop($rows);
            file_put_contents($file, implode("\n", $rows) . "\n");
        }, 'damaged', 'removed');
    }

    public function testRemovedMonthFile(): void
    {
        $this->damage(fn () => unlink($this->monthFile()), 'damaged', 'removed');
    }

    public function testHeadDisagrees(): void
    {
        $this->damage(function () {
            $p = $this->journal->primary()->directory() . '/head.json';
            $h = json_decode((string) file_get_contents($p), true);
            $h['line_hash'] = str_repeat('b', 64);
            file_put_contents($p, json_encode($h));
        }, 'damaged', null);
    }

    public function testLostJournalId(): void
    {
        $this->damage(fn () => unlink($this->journal->primary()->directory() . '/journal.id'), 'journal_missing', null);
    }

    public function testLastLineLosesNewlineButHeadRecordsIt(): void
    {
        $this->damage(function () {
            $file = $this->monthFile();
            file_put_contents($file, rtrim((string) file_get_contents($file), "\n"));
        }, 'damaged', null);
    }

    public function testTornTailNotInHeadIsRepaired(): void
    {
        $this->issue(3);
        $this->journal->writer->flush();
        file_put_contents($this->monthFile(), '{"v":1,"seq":4,"jour', \FILE_APPEND);
        $this->issue(1);
        $r = $this->journal->anotherProcess()->writer->flush();
        $this->assertSame('ok', $r->status);
        $this->assertComplete('torn');
        $this->assertCount(1, glob($this->journal->primary()->directory() . '/quarantine/*.part'));
    }

    /**
     * Known gap, pinned so a change is noticed: with head.json and the last line both
     * deleted nothing is left in the folder to tell a line was removed, and the flush
     * writes it again from the database. Only the shop copy (T7) detects it.
     */
    public function testADeletedHeadJsonAndLastLineAreRewrittenFromTheDatabase(): void
    {
        $this->issue(3);
        $this->journal->writer->flush();
        unlink($this->journal->primary()->directory() . '/head.json');
        $file = $this->monthFile();
        $rows = explode("\n", rtrim((string) file_get_contents($file), "\n"));
        array_pop($rows);
        file_put_contents($file, implode("\n", $rows) . "\n");

        $r = $this->journal->anotherProcess()->writer->flush();

        $this->assertSame(FiscalJournalFlushResult::OK, $r->status);
        $this->assertSame(1, $r->written);
        $this->assertComplete('deleted head and last line');
    }

    // ---------- (4) a record that cannot be read blocks the flush, gapless ----------

    public function testUnreadableRecordBlocksGapless(): void
    {
        $this->issue(2);
        $this->journal->writer->flush();
        $this->issue(3);
        $bad = (int) $this->connection->fetchOne('SELECT id FROM fiscal_record ORDER BY id LIMIT 1 OFFSET 3');
        $number = $this->connection->fetchOne('SELECT number FROM fiscal_record WHERE id = ?', [$bad]);
        $this->connection->executeStatement("UPDATE fiscal_record SET invoice_type = 'ZZ' WHERE id = ?", [$bad]);
        $r = $this->journal->anotherProcess()->writer->flush();
        $this->assertSame(FiscalJournalFlushResult::BLOCKED, $r->status);
        $this->assertSame($number, $r->number);
        $this->assertSame([1, 2, 3], array_column($this->journal->lines(), 'seq'));
        $this->connection->executeStatement("UPDATE fiscal_record SET invoice_type = 'F2' WHERE id = ?", [$bad]);
        $this->journal->anotherProcess()->writer->flush();
        $this->assertComplete('blocked');
    }

    // ---------- (5) rows captured at issue, from the secondary's intent, in another process ----------

    public function testIssueRowsFromSecondaryIntentAcrossProcesses(): void
    {
        $p = $this->journal->primary()->directory();
        chmod($p, 0500);
        $sale = $this->fixtures->checkout($this->fixtures->recorder($this->journal->intents))
            ->createOrder(1, $this->fixtures->cart() + ['cash_amount' => JournalPathFixtures::PRICE], 'cash', JournalPathFixtures::PRICE);
        chmod($p, 0700);
        $issued = $this->connection->fetchAssociative('SELECT * FROM order_meta WHERE id = ?', [$sale['order_id']]);
        $this->connection->executeStatement('UPDATE order_meta SET printed = 1, refunded_amount = 5.00 WHERE id = ?', [$sale['order_id']]);
        $this->journal->anotherProcess()->writer->flush();
        [$line] = $this->journal->lines();
        $this->assertSame($issued, $line['rows']['order_meta']);
        $this->assertNotContains('order_meta', $line['as_of_flush']);
        $this->assertSame([], $this->journal->intentFiles());
    }
}
