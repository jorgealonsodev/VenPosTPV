<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Backup;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Backup\BackupRetention;

final class BackupRetentionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/venpos-retention-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                unlink($this->dir . '/' . $file);
            }
        }
        rmdir($this->dir);
    }

    /** @return list<string> */
    private function backups(int $count): array
    {
        $names = [];
        for ($day = 1; $day <= $count; ++$day) {
            $names[] = sprintf('venpostpv-202611%02d-040000.sql.gz', $day);
        }
        // Created newest first, so the modification time cannot be what decides.
        foreach (array_reverse($names) as $name) {
            file_put_contents($this->dir . '/' . $name, 'x');
        }

        return $names;
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = array_values(array_diff(scandir($this->dir) ?: [], ['.', '..']));
        sort($files);

        return $files;
    }

    public function testKeepsTheSevenNewestBackupsAndNothingElseIsTouched(): void
    {
        $names = $this->backups(9);
        $others = ['.htaccess', 'notes.txt', 'venpostpv-manual.sql.gz', '.venpostpv-20261110-040000.sql.gz.tmp', 'other-20261101-040000.sql.gz'];
        foreach ($others as $other) {
            file_put_contents($this->dir . '/' . $other, 'keep');
        }

        $deleted = (new BackupRetention())->prune($this->dir, 7);

        $this->assertSame([$names[0], $names[1]], $deleted);
        $expected = array_merge(\array_slice($names, 2), $others);
        sort($expected);
        $this->assertSame($expected, $this->files());
    }

    public function testKeepIsConfigurable(): void
    {
        $names = $this->backups(5);

        (new BackupRetention())->prune($this->dir, 2);

        $this->assertSame([$names[3], $names[4]], $this->files());
    }

    public function testFewerBackupsThanTheLimitDeletesNothing(): void
    {
        $names = $this->backups(3);

        $this->assertSame([], (new BackupRetention())->prune($this->dir, 7));
        $this->assertSame($names, $this->files());
    }

    public function testKeepMustBePositive(): void
    {
        $this->backups(2);

        $this->expectException(\InvalidArgumentException::class);
        (new BackupRetention())->prune($this->dir, 0);
    }

    public function testBackupsMarkedForReviewCountAsBackups(): void
    {
        file_put_contents($this->dir . '/venpostpv-20261101-040000.sql.gz', 'x');
        file_put_contents($this->dir . '/venpostpv-20261102-040000-REVISAR.sql.gz', 'x');
        file_put_contents($this->dir . '/venpostpv-20261103-040000.sql.gz', 'x');
        file_put_contents($this->dir . '/venpostpv-20261104-040000-revisar.sql.gz', 'x');

        $this->assertSame(['venpostpv-20261101-040000.sql.gz'], (new BackupRetention())->prune($this->dir, 2));
        $this->assertSame([
            'venpostpv-20261102-040000-REVISAR.sql.gz',
            'venpostpv-20261103-040000.sql.gz',
            'venpostpv-20261104-040000-revisar.sql.gz',
        ], $this->files());
    }
}
