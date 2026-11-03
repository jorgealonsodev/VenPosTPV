<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use VenPosTPV\EventListener\DeviceListener;
use VenPosTPV\Service\Device\DeviceRegistry;

/**
 * docs/prd/agente-impresion.md E9: the print agent API never uses the session or
 * the device cookie, even when the request carries a session cookie.
 */
final class AgentApiNoSessionTest extends TestCase
{
    public function testTheDeviceListenerLeavesAgentRequestsAlone(): void
    {
        $registry = $this->createMock(DeviceRegistry::class);
        $registry->expects($this->never())->method('terminalForToken');
        $listener = new DeviceListener($registry);

        foreach (['/api/agent/v1/pair', '/api/agent/v1/jobs', '/api/agent/v1/jobs/5/result', '/api/agent/v1/status', '/api/agent/v1/update'] as $path) {
            $session = new Session(new MockArraySessionStorage());
            $request = Request::create($path, 'GET', [], ['PHPSESSID' => 'abc', 'venpos_device' => 'x']);
            $request->setSession($session);
            $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

            $listener->onRequest($event);

            $this->assertFalse($event->hasResponse(), $path);
            $this->assertFalse($session->isStarted(), $path . ': no session is started');
            $this->assertFalse($request->attributes->has('terminal_id'), $path);
        }
    }

    public function testOtherApisStillResolveTheTill(): void
    {
        $registry = $this->createMock(DeviceRegistry::class);
        $registry->expects($this->once())->method('terminalForToken')->willReturn(null);

        (new DeviceListener($registry))->onRequest(new RequestEvent($this->createMock(HttpKernelInterface::class), Request::create('/api/print-jobs/5'), HttpKernelInterface::MAIN_REQUEST));
    }
}
