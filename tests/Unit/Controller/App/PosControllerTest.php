<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\App;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use VenPosTPV\Controller\App\PosController;
use VenPosTPV\Service\Device\DeviceCookie;
use VenPosTPV\Service\Device\DeviceRegistry;
use VenPosTPV\Tests\Support\SessionRequest;

class PosControllerTest extends TestCase
{
    private const TOKEN = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const USER = ['user_id' => 1, 'cashier_name' => 'María García', 'store_name' => 'VenPosTPV', 'shop_id' => 1];

    private function twig(): Environment
    {
        return new Environment(new ArrayLoader(['pos.html.twig' => '<html><body>{{ session_data }}</body></html>']));
    }

    private function controller(Connection $connection, DeviceRegistry $registry): PosController
    {
        return new PosController($this->twig(), $connection, $registry, new DeviceCookie(''));
    }

    /** @return array<string, mixed> */
    private function sessionData(Response $response): array
    {
        $content = (string) $response->getContent();
        $json = substr($content, \strlen('<html><body>'), -\strlen('</body></html>'));

        return json_decode(html_entity_decode($json, ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $session */
    private function request(array $session, ?string $token = null): Request
    {
        $request = SessionRequest::create([], $session);
        if ($token !== null) {
            $request->cookies->set(DeviceCookie::NAME, $token);
        }

        return $request;
    }

    public function testIndexRedirectsWhenNotAuthenticated(): void
    {
        $controller = $this->controller($this->createMock(Connection::class), $this->createMock(DeviceRegistry::class));
        $response = $controller->index(SessionRequest::create());

        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        $this->assertSame('/app/login', $response->headers->get('Location'));
    }

    public function testALinkedComputerOpensItsOwnTill(): void
    {
        $registry = $this->createMock(DeviceRegistry::class);
        $registry->method('terminalForToken')->willReturnCallback(fn (?string $t): ?int => $t === self::TOKEN ? 4 : null);
        $registry->expects($this->never())->method('adoptOnlyTill');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchAssociative')
            ->with($this->stringContains('FROM terminal WHERE id = ?'), [4])
            ->willReturn(['name' => 'Caja 2', 'ticket_letter' => 'B', 'dataphone_mode' => 'independent', 'printer_type' => 'escpos', 'stock_sync_interval_seconds' => 30, 'stock_push_interval_seconds' => 0]);

        $response = $this->controller($connection, $registry)->index($this->request(self::USER + ['terminal_id' => 1], self::TOKEN));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringContainsString('Mar\\u00eda Garc\\u00eda', (string) $response->getContent());
        $data = $this->sessionData($response);
        $this->assertTrue($data['device_linked']);
        $this->assertSame(4, $data['terminal_id']);
        $this->assertSame('Caja 2', $data['terminal_name']);
        $this->assertSame('B', $data['ticket_letter']);
        $this->assertSame('independent', $data['dataphone_mode']);
        $this->assertSame('escpos', $data['printer_type']);
        $this->assertArrayNotHasKey('device_token', $data, 'the token is only handed to the page when it is issued');
    }

    public function testAnUnlinkedComputerGetsTheLinkScreenInsteadOfTerminalOne(): void
    {
        $registry = $this->createMock(DeviceRegistry::class);
        $registry->method('terminalForToken')->willReturn(null);
        $registry->method('adoptOnlyTill')->willReturn(null);
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchAssociative');

        $response = $this->controller($connection, $registry)->index($this->request(self::USER + ['terminal_id' => 1]));

        $data = $this->sessionData($response);
        $this->assertFalse($data['device_linked']);
        $this->assertNull($data['terminal_id']);
    }

    public function testTheFirstComputerAdoptsTheOnlyTill(): void
    {
        $registry = $this->createMock(DeviceRegistry::class);
        $registry->method('terminalForToken')->willReturn(null);
        $registry->expects($this->once())->method('adoptOnlyTill')->with(1, 1)->willReturn(['terminal_id' => 1, 'token' => self::TOKEN]);
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['name' => 'Caja 1', 'ticket_letter' => 'A', 'dataphone_mode' => 'integrated', 'printer_type' => 'pdf', 'stock_sync_interval_seconds' => 60, 'stock_push_interval_seconds' => 0]);

        $response = $this->controller($connection, $registry)->index($this->request(self::USER));

        $data = $this->sessionData($response);
        $this->assertTrue($data['device_linked']);
        $this->assertSame(1, $data['terminal_id']);
        $this->assertSame(self::TOKEN, $data['device_token'], 'mirrored in localStorage by the page');
        $cookies = array_filter($response->headers->getCookies(), fn ($c) => $c->getName() === DeviceCookie::NAME);
        $this->assertCount(1, $cookies);
        $this->assertSame(self::TOKEN, array_values($cookies)[0]->getValue());
        $this->assertTrue(array_values($cookies)[0]->isHttpOnly());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
