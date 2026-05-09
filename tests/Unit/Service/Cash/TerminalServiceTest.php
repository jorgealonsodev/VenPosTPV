<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Cash;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Cash\TerminalService;

final class TerminalServiceTest extends TestCase
{
    public function testResolveUsesExistingTerminal(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())->method('fetchOne')->willReturn(7);
        $conn->expects($this->never())->method('insert');

        $service = new TerminalService($conn);
        $this->assertSame(7, $service->resolveForShop(1));
    }

    public function testResolveBootstrapsTerminalWhenMissing(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(false);
        $conn->expects($this->once())->method('insert')->with('terminal', $this->anything());
        $conn->method('lastInsertId')->willReturn('9');

        $service = new TerminalService($conn);
        $this->assertSame(9, $service->resolveForShop(1));
    }
}
