<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\DeviceController;
use VenPosTPV\Service\Device\DeviceCookie;
use VenPosTPV\Service\Device\DeviceRegistry;
use VenPosTPV\Service\Device\TillHasDeviceException;
use VenPosTPV\Tests\Support\SessionRequest;

/**
 * S10: only an administrator links this computer to a till; the token lives in an
 * HttpOnly cookie and can be put back from the copy kept in localStorage.
 */
final class DeviceControllerTest extends TestCase
{
    private const ADMIN = ['user_id' => 1, 'role' => 'admin', 'shop_id' => 1];
    private const CASHIER = ['user_id' => 5, 'role' => 'cashier', 'shop_id' => 1];

    /** @param array<string, mixed> $body @param array<string, mixed> $session */
    private function json(array $body, array $session, ?string $deviceToken = null): Request
    {
        $request = SessionRequest::json($body, $session);
        $request->headers->set('Content-Type', 'application/json');
        if ($deviceToken !== null) {
            $request->cookies->set(DeviceCookie::NAME, $deviceToken);
        }

        return $request;
    }

    private function deviceCookie(Response $response): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === DeviceCookie::NAME) {
                return $cookie;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function body(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testAnUnlinkedDeviceGetsTheLinkScreenDataAndAdminsSeeTheTills(): void
    {
        $registry = $this->createMock(DeviceRegistry::class);
        $registry->method('terminalForToken')->willReturn(null);
        $registry->method('listTills')->willReturn([['id' => 3, 'name' => 'Caja 1', 'ticket_letter' => 'A', 'device' => null, 'this_device' => false]]);
        $controller = new DeviceController($registry, new DeviceCookie(''));

        $admin = $this->body($controller->status(SessionRequest::create([], self::ADMIN)));
        $cashier = $this->body($controller->status(SessionRequest::create([], self::CASHIER)));

        $this->assertFalse($admin['data']['linked']);
        $this->assertSame(3, $admin['data']['tills'][0]['id']);
        $this->assertFalse($cashier['data']['linked']);
        $this->assertArrayNotHasKey('tills', $cashier['data'], 'a cashier cannot choose a till');
    }

    public function testAnAdminLinksThisComputerAndGetsTheTokenInAnHttpOnlyCookie(): void
    {
        $token = str_repeat('ab', 32);
        $registry = $this->createMock(DeviceRegistry::class);
        $registry->expects($this->once())->method('link')->with(3, 1, false, null)->willReturn($token);
        $controller = new DeviceController($registry, new DeviceCookie(''));

        $response = $controller->link($this->json(['terminal_id' => 3], self::ADMIN));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(['terminal_id' => 3, 'token' => $token], $this->body($response)['data']);
        $cookie = $this->deviceCookie($response);
        $this->assertNotNull($cookie);
        $this->assertSame($token, $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        $this->assertGreaterThan(time() + 9 * 365 * 86400, $cookie->getExpiresTime(), 'valid for about ten years');
    }

    public function testACashierCannotLinkATill(): void
    {
        $registry = $this->createMock(DeviceRegistry::class);
        $registry->expects($this->never())->method('link');
        $controller = new DeviceController($registry, new DeviceCookie(''));

        $response = $controller->link($this->json(['terminal_id' => 3], self::CASHIER));

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertNull($this->deviceCookie($response));
    }

    public function testATillOnAnotherDeviceAsksToConfirmTheMove(): void
    {
        $registry = $this->createMock(DeviceRegistry::class);
        $registry->method('link')->willThrowException(new TillHasDeviceException());
        $controller = new DeviceController($registry, new DeviceCookie(''));

        $response = $controller->link($this->json(['terminal_id' => 3], self::ADMIN));

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $this->assertSame('till_has_device', $this->body($response)['code']);
        $this->assertNull($this->deviceCookie($response));
    }

    public function testMovingTheTillPassesTheConfirmationAndThisDevicesCurrentToken(): void
    {
        $current = str_repeat('cd', 32);
        $registry = $this->createMock(DeviceRegistry::class);
        $registry->expects($this->once())->method('link')->with(3, 1, true, $current)->willReturn(str_repeat('ef', 32));
        $controller = new DeviceController($registry, new DeviceCookie(''));

        $response = $controller->link($this->json(['terminal_id' => 3, 'move' => true], self::ADMIN, $current));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(str_repeat('ef', 32), $this->deviceCookie($response)?->getValue());
    }

    public function testLinkingRefusesAFormPostSoAnotherSiteCannotTriggerIt(): void
    {
        $registry = $this->createMock(DeviceRegistry::class);
        $registry->expects($this->never())->method('link');
        $controller = new DeviceController($registry, new DeviceCookie(''));
        $request = SessionRequest::create([], self::ADMIN, 'terminal_id=3');
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');

        $this->assertSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $controller->link($request)->getStatusCode());
    }

    public function testTheCookieIsRestoredFromTheTokenKeptInLocalStorage(): void
    {
        $token = str_repeat('12', 32);
        $registry = $this->createMock(DeviceRegistry::class);
        $registry->method('terminalForToken')->willReturnCallback(fn (?string $t): ?int => $t === $token ? 4 : null);
        $controller = new DeviceController($registry, new DeviceCookie(''));

        $restored = $controller->restore($this->json(['token' => $token], self::CASHIER));
        $unknown = $controller->restore($this->json(['token' => str_repeat('99', 32)], self::CASHIER));

        $this->assertSame(Response::HTTP_OK, $restored->getStatusCode());
        $this->assertSame(['terminal_id' => 4], $this->body($restored)['data']);
        $this->assertSame($token, $this->deviceCookie($restored)?->getValue());
        $this->assertTrue($this->deviceCookie($restored)?->isHttpOnly());
        $this->assertSame(Response::HTTP_NOT_FOUND, $unknown->getStatusCode());
        $this->assertSame('device_not_linked', $this->body($unknown)['code']);
        $this->assertNull($this->deviceCookie($unknown));
    }

    public function testDeviceEndpointsNeedALoggedInUser(): void
    {
        $controller = new DeviceController($this->createMock(DeviceRegistry::class), new DeviceCookie(''));

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $controller->status(SessionRequest::create())->getStatusCode());
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $controller->restore($this->json(['token' => str_repeat('12', 32)], []))->getStatusCode());
    }
}
