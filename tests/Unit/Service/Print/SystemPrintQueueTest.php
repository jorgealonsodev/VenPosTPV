<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Print\SystemPrintQueue;

final class SystemPrintQueueTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tpv_lp_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        // Fake lp: records its arguments and the raw bytes it receives on stdin.
        file_put_contents($this->dir . '/lp', "#!/bin/sh\nprintf '%s\\n' \"\$@\" > {$this->dir}/args\ncat > {$this->dir}/stdin\necho 'request id is Q-1 (1 file(s))'\n");
        file_put_contents($this->dir . '/lpstat', "#!/bin/sh\nprintf 'Brother_MFC\\nEpson_TM-T20II\\n'\n");
        file_put_contents($this->dir . '/lp-fail', "#!/bin/sh\necho 'lp: The printer or class does not exist.' >&2\nexit 1\n");
        chmod($this->dir . '/lp', 0755);
        chmod($this->dir . '/lpstat', 0755);
        chmod($this->dir . '/lp-fail', 0755);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testSendsRawBytesToTheNamedQueue(): void
    {
        $bytes = "\x1b@Ticket\x1dV\x00";

        (new SystemPrintQueue($this->dir . '/lp', $this->dir . '/lpstat'))->send('Epson_TM-T20II', $bytes);

        $this->assertSame("-d\nEpson_TM-T20II\n-o\nraw\n", file_get_contents($this->dir . '/args'));
        $this->assertSame($bytes, file_get_contents($this->dir . '/stdin'));
    }

    public function testFailingQueueThrowsWithTheSystemMessage(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The printer or class does not exist');

        (new SystemPrintQueue($this->dir . '/lp-fail', $this->dir . '/lpstat'))->send('Missing', 'x');
    }

    public function testRejectsQueueNamesThatAreNotPlainIdentifiers(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SystemPrintQueue($this->dir . '/lp', $this->dir . '/lpstat'))->send('-oraw;rm', 'x');
    }

    public function testListsInstalledQueues(): void
    {
        $this->assertSame(['Brother_MFC', 'Epson_TM-T20II'], (new SystemPrintQueue($this->dir . '/lp', $this->dir . '/lpstat'))->list());
    }
}
