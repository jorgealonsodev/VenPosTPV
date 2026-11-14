<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal\Journal;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Backup\BackupRetention;
use VenPosTPV\Service\Fiscal\Journal\JournalLocation;

/** D1 (folder rules) and D4 (verification, torn tail) on one journal location. */
final class JournalLocationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = JournalLines::temporaryDirectory('location');
    }

    protected function tearDown(): void
    {
        JournalLines::remove($this->root);
    }

    private function location(): JournalLocation
    {
        return new JournalLocation($this->root . '/backups/fiscal-journal');
    }

    public function testPrepareMakesAPrivateFolderDeniedToTheWeb(): void
    {
        $location = $this->location();

        $this->assertTrue($location->prepare());

        $directory = $this->root . '/backups/fiscal-journal';
        $this->assertSame(0700, fileperms($directory) & 0777);
        $this->assertSame("Require all denied\n", file_get_contents($directory . '/.htaccess'));
        $this->assertSame(0600, fileperms($directory . '/.htaccess') & 0777);
        $this->assertSame(0700, fileperms($directory . '/intents') & 0777);
        $this->assertSame(0700, fileperms($directory . '/quarantine') & 0777);
    }

    public function testPrepareTightensAFolderThatAlreadyExists(): void
    {
        mkdir($this->root . '/backups/fiscal-journal', 0777, true);
        chmod($this->root . '/backups/fiscal-journal', 0777);

        $this->assertTrue($this->location()->prepare());

        $this->assertSame(0700, fileperms($this->root . '/backups/fiscal-journal') & 0777);
    }

    public function testAReadOnlyFolderIsNotWritable(): void
    {
        $location = $this->location();
        $location->prepare();
        chmod($this->root . '/backups/fiscal-journal', 0500);

        $this->assertFalse($location->isWritable());
        $this->assertFalse($location->prepare());
    }

    public function testLinesAreAppendedToTheFileOfTheirMonthWithPrivateFilesAndAHead(): void
    {
        $location = $this->location();
        $location->prepare();
        [$one, $two] = JournalLines::chain(1, 2, null, '2026-11-30T23:59:59+01:00');
        [$three] = JournalLines::chain(3, 3, $two, '2026-12-01T00:00:01+01:00');

        foreach ([$one, $two, $three] as $line) {
            $location->append($line);
        }
        $location->writeHead($three);

        $directory = $this->root . '/backups/fiscal-journal';
        $this->assertSame([$directory . '/journal-2026-11.jsonl', $directory . '/journal-2026-12.jsonl'], $location->journalFiles());
        $this->assertSame(0600, fileperms($directory . '/journal-2026-11.jsonl') & 0777);
        $this->assertCount(2, file($directory . '/journal-2026-11.jsonl'));
        $this->assertSame($two['line_hash'], $three['prev'], 'the first line of a month chains to the last of the previous month');
        $this->assertSame($three, $location->lastValidLine());
        $this->assertSame([$one, $two, $three], iterator_to_array($location->lines(), false));
        $head = json_decode((string) file_get_contents($directory . '/head.json'), true);
        $this->assertSame(['seq' => 3, 'number' => 'A26000003', 'invoice_type' => 'F2', 'hash' => $three['fiscal_record']['hash'], 'line_hash' => $three['line_hash'], 'fiscal_record_id' => 3], $head);
        $this->assertSame(0600, fileperms($directory . '/head.json') & 0777);
    }

    public function testATornLastLineIsCutAndQuarantined(): void
    {
        $location = $this->location();
        $location->prepare();
        [$one, $two, $three] = JournalLines::chain(1, 3);
        $location->append($one);
        $location->append($two);
        $file = $location->journalFiles()[0];
        $torn = substr(json_encode($three, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 80);
        file_put_contents($file, $torn, FILE_APPEND);

        $this->assertSame(JournalLocation::TAIL_REPAIRED, $location->repairTail());

        $this->assertSame([$one, $two], iterator_to_array($location->lines(), false));
        $quarantined = glob($this->root . '/backups/fiscal-journal/quarantine/*') ?: [];
        $this->assertCount(1, $quarantined);
        $this->assertSame($torn, file_get_contents($quarantined[0]));
        $this->assertSame(JournalLocation::TAIL_OK, $location->repairTail(), 'nothing left to repair');
    }

    public function testACompleteLastLineThatFailsItsHashIsDamageNotATornWrite(): void
    {
        $location = $this->location();
        $location->prepare();
        [$one, $two] = JournalLines::chain(1, 2);
        $location->append($one);
        $bad = $two;
        $bad['fiscal_record']['number'] = 'A26999999';
        file_put_contents($location->journalFiles()[0], json_encode($bad) . "\n", FILE_APPEND);
        $content = file_get_contents($location->journalFiles()[0]);

        $this->assertSame(JournalLocation::TAIL_BAD_LAST, $location->repairTail());
        $this->assertSame($content, file_get_contents($location->journalFiles()[0]), 'nothing is cut');
        $this->assertSame([], glob($this->root . '/backups/fiscal-journal/quarantine/*') ?: []);
    }

    public function testACompleteLastLineThatDoesNotFollowThePreviousIsDamage(): void
    {
        $location = $this->location();
        $location->prepare();
        [$one] = JournalLines::chain(1, 1);
        [$three] = JournalLines::chain(3, 3, $one);
        $location->append($one);
        $location->append($three);

        $this->assertSame(JournalLocation::TAIL_BROKEN_LAST, $location->repairTail());
        $this->assertCount(2, iterator_to_array($location->lines(), false));
    }

    public function testATerminatedLastLineThatIsNotJsonIsATornWrite(): void
    {
        $location = $this->location();
        $location->prepare();
        [$one] = JournalLines::chain(1, 1);
        $location->append($one);
        file_put_contents($location->journalFiles()[0], '{"v":1,"seq":2,' . "\n", FILE_APPEND);

        $this->assertSame(JournalLocation::TAIL_REPAIRED, $location->repairTail());
        $this->assertSame([$one], iterator_to_array($location->lines(), false));
    }

    public function testATornTailInAReadOnlyFileIsNotQuarantinedUntilItCanBeCut(): void
    {
        $location = $this->location();
        $location->prepare();
        [$one] = JournalLines::chain(1, 1);
        $location->append($one);
        $file = $location->journalFiles()[0];
        file_put_contents($file, '{"v":1,"se', FILE_APPEND);
        chmod($file, 0400);
        try {
            $location->repairTail();
            $this->fail('a tail that cannot be cut is a write failure');
        } catch (\RuntimeException) {
        } finally {
            chmod($file, 0600);
        }

        $this->assertSame([], glob($this->root . '/backups/fiscal-journal/quarantine/*') ?: []);
        $this->assertSame(JournalLocation::TAIL_REPAIRED, $location->repairTail());
        $this->assertCount(1, glob($this->root . '/backups/fiscal-journal/quarantine/*') ?: []);
    }

    public function testADamagedLineBeforeTheLastIsNeverRewritten(): void
    {
        $location = $this->location();
        $location->prepare();
        foreach (JournalLines::chain(1, 3) as $line) {
            $location->append($line);
        }
        $file = $location->journalFiles()[0];
        $content = (string) file_get_contents($file);
        $damaged = str_replace('"order_meta":{"id":102}', '"order_meta":{"id":902}', $content);
        $this->assertNotSame($content, $damaged);
        file_put_contents($file, $damaged);

        $this->assertSame(JournalLocation::TAIL_DAMAGED, $location->repairTail());
        $this->assertSame($damaged, file_get_contents($file), 'nothing is cut');
        $this->assertSame([], glob($this->root . '/backups/fiscal-journal/quarantine/*') ?: []);
    }

    public function testJournalIdentityIsStoredInTheLocation(): void
    {
        $location = $this->location();
        $location->prepare();
        $this->assertNull($location->journalId());

        $location->writeJournalId('0b6f5d2e-7c41-4f8a-9d3e-1a2b3c4d5e6f', new \DateTimeImmutable('2026-11-14T18:00:00+01:00'));

        $this->assertSame('0b6f5d2e-7c41-4f8a-9d3e-1a2b3c4d5e6f', $location->journalId());
        $this->assertSame(['journal_id' => '0b6f5d2e-7c41-4f8a-9d3e-1a2b3c4d5e6f', 'created_at' => '2026-11-14T18:00:00+01:00'], json_decode((string) file_get_contents($this->root . '/backups/fiscal-journal/journal.id'), true));
    }

    public function testBackupRetentionNeverTouchesTheJournalFolder(): void
    {
        $backups = $this->root . '/backups';
        $location = $this->location();
        $location->prepare();
        $location->append(JournalLines::chain(1, 1)[0]);
        foreach (['20260101-030000', '20260102-030000'] as $stamp) {
            touch($backups . '/venpostpv-' . $stamp . '.sql.gz');
        }

        (new BackupRetention())->prune($backups, 1);

        $this->assertFileExists($backups . '/fiscal-journal/journal-2026-11.jsonl');
        $this->assertFileDoesNotExist($backups . '/venpostpv-20260101-030000.sql.gz');
    }
}
