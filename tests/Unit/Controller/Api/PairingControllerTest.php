<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use VenPosTPV\Controller\Api\PairingController;
use VenPosTPV\Service\Install\EnvFileUpdater;
use VenPosTPV\Service\PrestaShop\PairingClient;

/** Ajustes → Acerca de → «Volver a emparejar con la tienda» (admins only). */
final class PairingControllerTest extends TestCase
{
    private string $dir;
    private const OLD_ENV = "APP_ENV='prod'\nAPP_SECRET='keep'\nTPV_URL='https://tpv.example.com'\nPS_SHOP_URL='https://shop.example.com'\nPS_API_KEY='OLDKEY'\nSSO_SHARED_SECRET='old-secret-old-secret-old-secret-0'\nPS_DEFAULT_SHOP='1'\nPS_DEFAULT_CURRENCY='1'\nPS_SHOP_TIMEZONE='Europe/Madrid'\n";

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tpv-repair-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/.env', self::OLD_ENV);
        chmod($this->dir . '/.env', 0600);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($this->dir);
    }

    private function request(?string $role, array $body = []): Request
    {
        $request = Request::create('/api/settings/prestashop/pair', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body));
        $session = new Session(new MockArraySessionStorage());
        if ($role !== null) {
            $session->set('user_id', 1);
            $session->set('role', $role);
        }
        $request->setSession($session);

        return $request;
    }

    private function controller(MockHttpClient $http, ?string $tpvUrl = 'https://tpv.example.com'): PairingController
    {
        return new PairingController(new PairingClient($http), new EnvFileUpdater($this->dir), $tpvUrl, 'https://shop.example.com', 'prod', static fn (): array => []);
    }

    private static function pairedResponse(array &$requests = []): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['url' => $url, 'body' => json_decode($options['body'], true)];

            return new MockResponse((string) json_encode([
                'api_key' => 'NEWKEY0123456789ABCDEF0123456789', 'sso_secret' => str_repeat('9', 64),
                'shop_id' => 1, 'shop_url' => 'https://shop.example.com', 'currency_id' => 1,
                'timezone' => 'Europe/Madrid', 'module_version' => '0.8.0',
            ]), ['http_code' => 200]);
        });
    }

    public function testOnlyAnAdminCanPair(): void
    {
        $http = new MockHttpClient(fn () => self::fail('A cashier must not reach the shop.'));

        foreach (['cashier', null] as $role) {
            $response = $this->controller($http)->pair($this->request($role, ['code' => '123456']));
            self::assertSame(403, $response->getStatusCode());
        }
        self::assertSame(self::OLD_ENV, file_get_contents($this->dir . '/.env'));
    }

    public function testPairingRewritesTheShopKeysKeepsABackupAndNeverEchoesSecrets(): void
    {
        $requests = [];

        $response = $this->controller(self::pairedResponse($requests))->pair($this->request('admin', ['code' => '123456']));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame(['code' => '123456', 'tpv_url' => 'https://tpv.example.com'], $requests[0]['body']);
        self::assertSame('https://shop.example.com/module/tpvconnect/pair', $requests[0]['url']);
        $body = (string) $response->getContent();
        self::assertStringNotContainsString('NEWKEY', $body);
        self::assertStringNotContainsString(str_repeat('9', 64), $body);
        $data = json_decode($body, true)['data'];
        self::assertSame('https://shop.example.com', $data['shop_url']);
        self::assertSame('0.8.0', $data['module_version']);

        $env = (new Dotenv())->parse((string) file_get_contents($this->dir . '/.env'));
        self::assertSame('NEWKEY0123456789ABCDEF0123456789', $env['PS_API_KEY']);
        self::assertSame(str_repeat('9', 64), $env['SSO_SHARED_SECRET']);
        self::assertSame('keep', $env['APP_SECRET']);
        self::assertSame(self::OLD_ENV, file_get_contents($this->dir . '/' . $data['backup']));
    }

    public function testAnotherShopAddressCanBeGiven(): void
    {
        $requests = [];

        $this->controller(self::pairedResponse($requests))->pair($this->request('admin', ['code' => '123456', 'shop_url' => 'https://nueva.example.com']));

        self::assertSame('https://nueva.example.com/module/tpvconnect/pair', $requests[0]['url']);
        self::assertSame('https://nueva.example.com', (new Dotenv())->parse((string) file_get_contents($this->dir . '/.env'))['PS_SHOP_URL']);
    }

    public function testARefusedCodeLeavesTheEnvUntouched(): void
    {
        $http = new MockHttpClient([new MockResponse((string) json_encode(['error' => 'invalid_code']), ['http_code' => 403])]);

        $response = $this->controller($http)->pair($this->request('admin', ['code' => '123456']));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('no es correcto o ha caducado', json_decode((string) $response->getContent(), true)['error']);
        self::assertSame(self::OLD_ENV, file_get_contents($this->dir . '/.env'));
        self::assertSame([], glob($this->dir . '/.env.bak-*'));
    }

    public function testWithoutTheTpvAddressNothingIsSent(): void
    {
        $http = new MockHttpClient(fn () => self::fail('No request without TPV_URL.'));

        $response = $this->controller($http, null)->pair($this->request('admin', ['code' => '123456']));

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('TPV_URL', json_decode((string) $response->getContent(), true)['error']);
    }

    public function testTheCurrentShopAddressIsShownToAdmins(): void
    {
        $http = new MockHttpClient([]);
        $request = $this->request('admin');

        $response = $this->controller($http)->show($request);

        self::assertSame(['data' => ['shop_url' => 'https://shop.example.com', 'tpv_url' => 'https://tpv.example.com']], json_decode((string) $response->getContent(), true));
        self::assertSame(403, $this->controller($http)->show($this->request('cashier'))->getStatusCode());
    }
}
