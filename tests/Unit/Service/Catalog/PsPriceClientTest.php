<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Catalog;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\Catalog\Exception\CatalogEditException;
use VenPosTPV\Service\Catalog\PsPriceClient;

/** «Modo edición» → POST /module/tpvconnect/price, signed with the shared secret. */
final class PsPriceClientTest extends TestCase
{
    public function testItPostsTheSignedPriceAndReturnsThePriceWithoutVat(): void
    {
        $sent = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = [$method, $url, $options];

            return new MockResponse('{"id_product":7,"id_product_attribute":0,"price":270,"price_net":223.140496,"vat_rate":21}', ['http_code' => 200]);
        });

        $result = (new PsPriceClient('https://shop.test/', 'secret', $http))->setPrice(7, 0, 270.0, 21.0);

        self::assertSame(['price_net' => 223.140496], $result);
        self::assertSame('POST', $sent[0]);
        self::assertSame('https://shop.test/module/tpvconnect/price', $sent[1]);
        $body = '{"id_product":7,"id_product_attribute":0,"price":270,"vat_rate":21}';
        self::assertSame($body, $sent[2]['body']);
        self::assertContains('Authorization: HMAC-SHA256 ' . hash_hmac('sha256', $body, 'secret'), $sent[2]['headers']);
    }

    /** @return iterable<string, array{int, string, int, string}> */
    public static function failures(): iterable
    {
        yield 'unknown item' => [404, '{"error":"Product not found","details":{}}', 404, 'prestashop_not_found'];
        yield 'module without the endpoint' => [404, '<html>Página no encontrada</html>', 502, 'prestashop_price_failed'];
        yield 'rate without tax rule' => [422, '{"error":"Validation failed","details":{"vat_rate":"The shop has no tax rule for 4 %"}}', 422, 'prestashop_no_tax_rule'];
        yield 'shop error' => [500, '{"error":"Price change failed"}', 502, 'prestashop_price_failed'];
        yield 'bad signature' => [401, '{"error":"Unauthorized"}', 502, 'prestashop_price_failed'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testAFailureChangesNothingAndSaysWhy(int $status, string $answer, int $expectedStatus, string $code): void
    {
        $client = new PsPriceClient('https://shop.test', 'secret', new MockHttpClient(new MockResponse($answer, ['http_code' => $status])));

        try {
            $client->setPrice(7, 0, 10.0, 4.0);
            self::fail('Expected a refusal');
        } catch (CatalogEditException $e) {
            self::assertSame([$expectedStatus, $code], [$e->status, $e->errorCode]);
        }
    }

    public function testANetworkFailureIsABadGateway(): void
    {
        $client = new PsPriceClient('https://shop.test', 'secret', new MockHttpClient(static fn () => throw new \Symfony\Component\HttpClient\Exception\TransportException('down')));

        try {
            $client->setPrice(7, 0, 10.0, 21.0);
            self::fail('Expected a refusal');
        } catch (CatalogEditException $e) {
            self::assertSame([502, 'prestashop_price_failed'], [$e->status, $e->errorCode]);
        }
    }

    public function testTheCodesArePostedSignedWithEmptyForCleared(): void
    {
        $sent = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = [$url, $options['body']];

            return new MockResponse('{"id_product":7,"id_product_attribute":71,"ean13":"96385074","reference":""}', ['http_code' => 200]);
        });

        (new PsPriceClient('https://shop.test', 'secret', $http))->setCodes(7, 71, '96385074', null);

        self::assertSame(['https://shop.test/module/tpvconnect/codes', '{"id_product":7,"id_product_attribute":71,"ean13":"96385074","reference":""}'], $sent);
    }

    /** @return iterable<string, array{int, string, int, string}> */
    public static function codesFailures(): iterable
    {
        yield 'unknown item' => [404, '{"error":"Product not found","details":{}}', 404, 'prestashop_not_found'];
        yield 'module before 0.8.4' => [404, '<html>Página no encontrada</html>', 502, 'prestashop_codes_failed'];
        yield 'refused ean' => [422, '{"error":"Validation failed","details":{"ean13":"Empty, or 8 or 13 digits"}}', 422, 'prestashop_codes_invalid'];
        yield 'shop error' => [500, '{"error":"Codes change failed"}', 502, 'prestashop_codes_failed'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('codesFailures')]
    public function testARefusedCodesChangeSaysWhy(int $status, string $answer, int $expectedStatus, string $code): void
    {
        $client = new PsPriceClient('https://shop.test', 'secret', new MockHttpClient(new MockResponse($answer, ['http_code' => $status])));

        try {
            $client->setCodes(7, 0, '96385074', 'R');
            self::fail('Expected a refusal');
        } catch (CatalogEditException $e) {
            self::assertSame([$expectedStatus, $code], [$e->status, $e->errorCode]);
        }
    }
}
