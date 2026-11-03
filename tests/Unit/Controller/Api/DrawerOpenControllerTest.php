<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Controller\Api\DrawerOpenController;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Tests\Support\SessionRequest;

/**
 * "Abrir cajón" (docs/prd/agente-impresion.md C3, T11): on an agent till the
 * response names the queued drawer job, so the screen can warn when it does not
 * open in 5 s. Other printers answer as before.
 */
final class DrawerOpenControllerTest extends TestCase
{
    public function testAnAgentTillAnswersTheQueuedDrawerJob(): void
    {
        $printer = $this->createMock(PrinterService::class);
        $printer->expects($this->once())->method('openDrawer')->with(4);
        $printer->method('queuedJobId')->with('drawer')->willReturn(77);

        $response = (new DrawerOpenController($printer))(SessionRequest::json([], ['user_id' => 1, 'terminal_id' => 4]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['success' => true, 'drawer_job_id' => 77], json_decode((string) $response->getContent(), true));
    }

    public function testOtherPrintersAnswerAsBefore(): void
    {
        $printer = $this->createMock(PrinterService::class);
        $printer->expects($this->once())->method('openDrawer')->with(4);
        $printer->method('queuedJobId')->willReturn(null);

        $response = (new DrawerOpenController($printer))(SessionRequest::json([], ['user_id' => 1, 'terminal_id' => 4]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['success' => true], json_decode((string) $response->getContent(), true));
    }

    public function testWithoutATillNothingIsOpened(): void
    {
        $printer = $this->createMock(PrinterService::class);
        $printer->expects($this->never())->method('openDrawer');

        $response = (new DrawerOpenController($printer))(SessionRequest::json([], ['user_id' => 1]));

        $this->assertSame(400, $response->getStatusCode());
    }
}
