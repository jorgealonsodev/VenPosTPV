<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Backup;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Backup\BackupRetention;
use VenPosTPV\Service\Backup\DatabaseBackup;
use VenPosTPV\Service\Backup\HealthCheck;
use VenPosTPV\Service\Backup\HealthReport;
use VenPosTPV\Service\Backup\SqlDumper;

final class DatabaseBackupTest extends TestCase
{
    private string $root;
    private string $dir;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/venpos-backup-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0700);
        $this->dir = $this->root . '/backups';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (array_diff(scandir($this->dir) ?: [], ['.', '..']) as $file) {
                unlink($this->dir . '/' . $file);
            }
            rmdir($this->dir);
        }
        rmdir($this->root);
    }

    private function dumper(string $sql, int $tables, ?\Throwable $failure = null): SqlDumper
    {
        $dumper = $this->createMock(SqlDumper::class);
        $dumper->method('dump')->willReturnCallback(static function (\Closure $write) use ($sql, $tables, $failure): int {
            $write($sql);
            if ($failure !== null) {
                throw $failure;
            }

            return $tables;
        });

        return $dumper;
    }

    /** @param list<string> $problems */
    private function health(array $problems = []): HealthCheck
    {
        return new class(new HealthReport(3, 5, $problems)) implements HealthCheck {
            public function __construct(private readonly HealthReport $report)
            {
            }

            public function run(): HealthReport
            {
                return $this->report;
            }
        };
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = array_values(array_diff(scandir($this->dir) ?: [], ['.', '..']));
        sort($files);

        return $files;
    }

    private function oldBackups(int $count): void
    {
        mkdir($this->dir, 0755);
        for ($day = 1; $day <= $count; ++$day) {
            file_put_contents(sprintf('%s/venpostpv-202610%02d-040000.sql.gz', $this->dir, $day), 'old');
        }
    }

    public function testWritesAPrivateGzipFileNamedAfterTheLocalTimeInAPrivateFolder(): void
    {
        $backup = new DatabaseBackup($this->dumper("SET FOREIGN_KEY_CHECKS=0;\n", 3), new BackupRetention(), $this->health(), $this->dir);

        $result = $backup->run(7, new \DateTimeImmutable('2026-11-05 04:00:09'));

        $path = $this->dir . '/venpostpv-20261105-040009.sql.gz';
        $this->assertSame($path, $result->path);
        $this->assertSame('venpostpv-20261105-040009.sql.gz', $result->fileName());
        $this->assertSame(3, $result->tables);
        $this->assertSame(filesize($path), $result->bytes);
        $this->assertSame("SET FOREIGN_KEY_CHECKS=0;\n", gzdecode((string) file_get_contents($path)));
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame(0700, fileperms($this->dir) & 0777);
        $this->assertSame("Require all denied\n", file_get_contents($this->dir . '/.htaccess'));
        $this->assertSame(['.htaccess', 'venpostpv-20261105-040009.sql.gz'], $this->files());
    }

    public function testAnExistingFolderIsMadePrivateAndOnlyTheNewestBackupsAreKept(): void
    {
        $this->oldBackups(9);
        file_put_contents($this->dir . '/notes.txt', 'keep');
        $backup = new DatabaseBackup($this->dumper('SELECT 1;', 1), new BackupRetention(), $this->health(), $this->dir);

        $backup->run(7, new \DateTimeImmutable('2026-11-05 04:00:00'));

        $this->assertSame(0700, fileperms($this->dir) & 0777);
        $this->assertSame([
            '.htaccess',
            'notes.txt',
            'venpostpv-20261004-040000.sql.gz',
            'venpostpv-20261005-040000.sql.gz',
            'venpostpv-20261006-040000.sql.gz',
            'venpostpv-20261007-040000.sql.gz',
            'venpostpv-20261008-040000.sql.gz',
            'venpostpv-20261009-040000.sql.gz',
            'venpostpv-20261105-040000.sql.gz',
        ], $this->files());
    }

    public function testARetentionFailureKeepsTheWrittenBackupAndIsReportedApart(): void
    {
        $retention = new class () extends BackupRetention {
            public function prune(string $directory, int $keep): array
            {
                throw new \RuntimeException('Cannot delete venpostpv-20261101-040000.sql.gz');
            }
        };
        $backup = new DatabaseBackup($this->dumper('SELECT 1;', 1), $retention, $this->health(), $this->dir);

        $result = $backup->run(7, new \DateTimeImmutable('2026-11-05 04:00:00'));

        self::assertFileExists($this->dir . '/venpostpv-20261105-040000.sql.gz');
        self::assertSame('Cannot delete venpostpv-20261101-040000.sql.gz', $result->retentionError);
    }

    public function testAFailedBackupLeavesNoFileAndDeletesNothing(): void
    {
        $this->oldBackups(9);
        $backup = new DatabaseBackup($this->dumper('half a dump', 0, new \RuntimeException('Lost connection')), new BackupRetention(), $this->health(), $this->dir);

        try {
            $backup->run(7, new \DateTimeImmutable('2026-11-05 04:00:00'));
            $this->fail('The failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('Lost connection', $e->getMessage());
        }

        $files = $this->files();
        $this->assertCount(10, $files); // the 9 old backups and the .htaccess
        $this->assertNotContains('venpostpv-20261105-040000.sql.gz', $files);
        foreach ($files as $file) {
            $this->assertStringNotContainsString('tmp', $file);
        }
    }

    public function testAFailedCheckStillWritesTheBackupMarkedForReviewAndDeletesNothing(): void
    {
        $this->oldBackups(9);
        $problems = ['Table order_meta: Corrupt'];
        $backup = new DatabaseBackup($this->dumper('SELECT 1;', 2), new BackupRetention(), $this->health($problems), $this->dir);

        $result = $backup->run(7, new \DateTimeImmutable('2026-11-05 04:00:00'));

        $this->assertSame('venpostpv-20261105-040000-REVISAR.sql.gz', $result->fileName());
        $this->assertSame($problems, $result->health->problems);
        $this->assertSame('SELECT 1;', gzdecode((string) file_get_contents($result->path)));
        $files = $this->files();
        $this->assertCount(11, $files); // 9 old backups, the new one and the .htaccess
        $this->assertContains('venpostpv-20261001-040000.sql.gz', $files);
    }

    public function testAHealthyRunCountsEarlierBackupsMarkedForReview(): void
    {
        mkdir($this->dir, 0700);
        foreach (['20261001', '20261002', '20261003'] as $day) {
            file_put_contents(sprintf('%s/venpostpv-%s-040000-REVISAR.sql.gz', $this->dir, $day), 'old');
        }
        $backup = new DatabaseBackup($this->dumper('SELECT 1;', 2), new BackupRetention(), $this->health(), $this->dir);

        $backup->run(2, new \DateTimeImmutable('2026-11-05 04:00:00'));

        $this->assertSame(['.htaccess', 'venpostpv-20261003-040000-REVISAR.sql.gz', 'venpostpv-20261105-040000.sql.gz'], $this->files());
    }
}
