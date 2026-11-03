<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use VenPosTPV\EventListener\DeviceListener;
use VenPosTPV\Service\Device\DeviceContext;
use VenPosTPV\Service\Device\DeviceCookie;
use VenPosTPV\Service\Device\DeviceNotLinkedException;
use VenPosTPV\Service\Device\DeviceRegistry;
use VenPosTPV\Tests\Support\SessionRequest;

/**
 * S10: every API that works on a till answers 409 {"code":"device_not_linked"} to a
 * browser without a linked till, instead of silently using terminal 1.
 */
final class DeviceGateTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function listener(?int $terminalForToken): DeviceListener
    {
        $registry = $this->createMock(DeviceRegistry::class);
        $registry->method('terminalForToken')->willReturnCallback(
            fn (?string $token): ?int => $token === self::TOKEN ? $terminalForToken : null
        );

        return new DeviceListener($registry);
    }

    /** @param array<string, mixed> $session */
    private function request(string $method, string $path, array $session, bool $withToken): Request
    {
        $request = Request::create($path, $method, [], $withToken ? [DeviceCookie::NAME => self::TOKEN] : []);
        $request->setSession(SessionRequest::create([], $session)->getSession());

        return $request;
    }

    private function dispatch(DeviceListener $listener, Request $request): RequestEvent
    {
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $listener->onRequest($event);

        return $event;
    }

    public function testTillApisAnswerDeviceNotLinkedForAnUnlinkedBrowser(): void
    {
        foreach (['/api/cash/open', '/api/cash/current', '/api/checkout', '/api/refund', '/api/exchange', '/api/drawer/open', '/api/parked-tickets', '/api/ticket/reprint'] as $path) {
            $event = $this->dispatch($this->listener(null), $this->request('POST', $path, ['user_id' => 1, 'shop_id' => 1], false));

            $this->assertTrue($event->hasResponse(), $path);
            $this->assertSame(409, $event->getResponse()->getStatusCode(), $path);
            $this->assertSame('device_not_linked', json_decode((string) $event->getResponse()->getContent(), true)['code'], $path);
        }
    }

    public function testLoginDeviceAndCatalogApisStayReachableWithoutATill(): void
    {
        foreach (['/api/auth/login', '/api/device', '/api/device/link', '/api/device/restore', '/api/catalog/search', '/api/health', '/api/terminals'] as $path) {
            $event = $this->dispatch($this->listener(null), $this->request('GET', $path, ['user_id' => 1], false));

            $this->assertFalse($event->hasResponse(), $path);
        }
    }

    public function testALinkedBrowserWorksOnItsOwnTill(): void
    {
        $request = $this->request('POST', '/api/cash/open', ['user_id' => 1, 'shop_id' => 1, 'terminal_id' => 1, 'cash_session_id' => 77], true);

        $event = $this->dispatch($this->listener(4), $request);

        $this->assertFalse($event->hasResponse());
        $this->assertSame(4, $request->attributes->get(DeviceContext::ATTRIBUTE));
        // Controllers that read the session's till (drawer, refunds, parked tickets) see this device's till.
        $this->assertSame(4, $request->getSession()->get('terminal_id'));
        $this->assertNull($request->getSession()->get('cash_session_id'), 'the drawer cached for another till is dropped');
    }

    public function testAnUnlinkedBrowserLosesAnyTillLeftInItsSession(): void
    {
        $request = $this->request('GET', '/api/catalog/search', ['user_id' => 1, 'terminal_id' => 1], false);

        $this->dispatch($this->listener(null), $request);

        $this->assertTrue($request->attributes->has(DeviceContext::ATTRIBUTE));
        $this->assertNull($request->attributes->get(DeviceContext::ATTRIBUTE));
        $this->assertNull($request->getSession()->get('terminal_id'));
    }

    public function testADeviceNotLinkedErrorFromAServiceBecomesTheSame409(): void
    {
        $event = new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            $this->request('GET', '/api/orders/search', [], false),
            HttpKernelInterface::MAIN_REQUEST,
            new DeviceNotLinkedException()
        );

        $this->listener(null)->onException($event);

        $this->assertSame(409, $event->getResponse()?->getStatusCode());
        $this->assertSame('device_not_linked', json_decode((string) $event->getResponse()->getContent(), true)['code']);
    }
}
