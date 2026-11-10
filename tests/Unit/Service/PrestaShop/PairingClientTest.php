<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\PrestaShop;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\PrestaShop\PairingClient;
use VenPosTPV\Service\PrestaShop\PairingException;

final class PairingClientTest extends TestCase
{
    /** @return array<string, mixed> */
    private function paired(array $overrides = []): array
    {
        return $overrides + [
            'api_key' => 'ABCDEF0123456789ABCDEF0123456789',
            'sso_secret' => str_repeat('a1', 32),
            'shop_id' => 1,
            'shop_url' => 'https://shop.example.com',
            'currency_id' => 2,
            'timezone' => 'Europe/Madrid',
            'module_version' => '0.8.0',
        ];
    }

    private static function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($body), ['http_code' => $status, 'response_headers' => ['Content-Type: application/json']]);
    }

    private function messageOf(callable $call): string
    {
        try {
            $call();
        } catch (PairingException $e) {
            return $e->getMessage();
        }
        self::fail('A PairingException was expected.');
    }

    public function testItPostsTheCodeAndTheTpvAddressAndReturnsTheEnvValues(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];

            return self::json($this->paired());
        });

        $result = (new PairingClient($http))->pair('https://shop.example.com/', ' 123 456 ', 'https://tpv.example.com');

        self::assertSame('POST', $requests[0][0]);
        self::assertSame('https://shop.example.com/module/tpvconnect/pair', $requests[0][1]);
        self::assertSame(['code' => '123456', 'tpv_url' => 'https://tpv.example.com'], json_decode($requests[0][2]['body'], true));
        self::assertSame(0, $requests[0][2]['max_redirects']);
        self::assertSame([
            'PS_SHOP_URL' => 'https://shop.example.com',
            'PS_API_KEY' => 'ABCDEF0123456789ABCDEF0123456789',
            'SSO_SHARED_SECRET' => str_repeat('a1', 32),
            'PS_DEFAULT_SHOP' => '1',
            'PS_DEFAULT_CURRENCY' => '2',
            'PS_SHOP_TIMEZONE' => 'Europe/Madrid',
            'TPV_COUPON_CHECKOUT_ENABLED' => '1',
        ], $result->envValues());
        self::assertSame('0.8.0', $result->moduleVersion);
    }

    public function testTheShopAddressMustBeHttpsExceptLocalhost(): void
    {
        $http = new MockHttpClient(fn (): MockResponse => self::json($this->paired()));
        $client = new PairingClient($http);

        self::assertSame('La dirección de la tienda debe ser una URL https:// válida (por ejemplo https://tutienda.com).', $this->messageOf(fn () => $client->pair('http://shop.example.com', '123456', 'https://tpv.example.com')));
        self::assertSame('http://localhost:8092', $client->pair('http://localhost:8092', '123456', 'http://localhost:8000')->shopUrl);
    }

    public function testTheCodeMustHaveSixDigitsBeforeAnyRequest(): void
    {
        $http = new MockHttpClient(fn () => self::fail('No request for a malformed code.'));

        self::assertSame('El código de emparejamiento tiene 6 cifras. Pulsa «Emparejar con un TPV» en el módulo TPV Connect de PrestaShop para ver uno.', $this->messageOf(fn () => (new PairingClient($http))->pair('https://shop.example.com', '12345', 'https://tpv.example.com')));
    }

    public function testAWrongOrExpiredCodeSaysSo(): void
    {
        $http = new MockHttpClient([self::json(['error' => 'invalid_code'], 403)]);

        self::assertSame('El código de emparejamiento no es correcto o ha caducado. Pulsa otra vez «Emparejar con un TPV» en el módulo TPV Connect y escribe el código nuevo (vale 10 minutos).', $this->messageOf(fn () => (new PairingClient($http))->pair('https://shop.example.com', '123456', 'https://tpv.example.com')));
    }

    public function testAnUnreachableShopSaysSo(): void
    {
        $http = new MockHttpClient(fn () => throw new TransportException('Could not resolve host'));

        self::assertSame('No se pudo conectar con la tienda en https://shop.example.com. Revisa la dirección y que la tienda esté en línea.', $this->messageOf(fn () => (new PairingClient($http))->pair('https://shop.example.com', '123456', 'https://tpv.example.com')));
    }

    public function testAShopWithoutTheModuleOrWithAnOldOneIsToldToInstallOrUpdateIt(): void
    {
        $expected = 'La tienda no tiene el módulo TPV Connect 0.8.0 o superior. Instala o actualiza el módulo en PrestaShop (Módulos → Gestor de módulos → Subir un módulo, con tpvconnect.zip) y vuelve a intentarlo.';
        foreach ([
            new MockResponse('<html>Page not found</html>', ['http_code' => 404, 'response_headers' => ['Content-Type: text/html']]),
            self::json($this->paired(['module_version' => '0.7.9'])),
            self::json(['unexpected' => true]),
            new MockResponse('<html>ok</html>', ['http_code' => 200]),
        ] as $response) {
            self::assertSame($expected, $this->messageOf(fn () => (new PairingClient(new MockHttpClient([$response])))->pair('https://shop.example.com', '123456', 'https://tpv.example.com')));
        }
    }

    public function testARedirectNamesTheAddressToTypeInstead(): void
    {
        $http = new MockHttpClient([new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location: https://www.shop.example.com/module/tpvconnect/pair?x=1']])]);

        self::assertSame('La tienda redirige a https://www.shop.example.com. Escribe esa dirección como dirección de la tienda.', $this->messageOf(fn () => (new PairingClient($http))->pair('https://shop.example.com', '123456', 'https://tpv.example.com')));
    }

    public function testTheShopRefusingTheTpvAddressSaysSo(): void
    {
        $http = new MockHttpClient([self::json(['error' => 'invalid_tpv_url'], 422)]);

        self::assertSame('La tienda no acepta la dirección del TPV (http://tpv.example.com): tiene que ser https://.', $this->messageOf(fn () => (new PairingClient($http))->pair('https://shop.example.com', '123456', 'http://tpv.example.com')));
    }

    public function testTooManyRequestsAndBusyAskToWait(): void
    {
        foreach ([self::json(['error' => 'rate_limited'], 429), self::json(['error' => 'busy'], 503)] as $response) {
            self::assertSame('La tienda está ocupada o ha recibido demasiados intentos. Espera un minuto y vuelve a intentarlo.', $this->messageOf(fn () => (new PairingClient(new MockHttpClient([$response])))->pair('https://shop.example.com', '123456', 'https://tpv.example.com')));
        }
    }

    public function testAnInvalidKeyInTheAnswerIsRefusedAndNeverEchoed(): void
    {
        $http = new MockHttpClient([self::json($this->paired(['api_key' => "BAD\nKEY", 'sso_secret' => 'short']))]);

        $message = $this->messageOf(fn () => (new PairingClient($http))->pair('https://shop.example.com', '123456', 'https://tpv.example.com'));

        self::assertStringNotContainsString('BAD', $message);
        self::assertStringContainsString('TPV Connect 0.8.0', $message);
    }
}
