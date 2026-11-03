<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Refund;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\PsRestockClient;

final class PsRestockClientTest extends TestCase
{
    private const PAYLOAD = ['request_id' => 'req-0001-abcd', 'lines' => [['id_product' => 3, 'id_product_attribute' => 7, 'quantity' => 1]]];

    public function testPostsTheSignedBodyToTheRestockEndpointAndReturnsTheLines(): void
    {
        $sent = [];
        $client = new PsRestockClient('http://shop.test/', 'secret', new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = compact('method', 'url', 'options');

            return new MockResponse(json_encode([
                'restocked_lines' => [['id_product' => 3, 'id_product_attribute' => 7, 'quantity' => 1, 'restocked' => true]],
                'restock_failed' => [],
                'replayed' => false,
            ]), ['http_code' => 201]);
        }));

        $result = $client->restock(self::PAYLOAD);

        $this->assertSame('POST', $sent['method']);
        $this->assertSame('http://shop.test/module/tpvconnect/restock', $sent['url']);
        $body = json_encode(self::PAYLOAD);
        $this->assertSame($body, $sent['options']['body']);
        $this->assertContains('Authorization: HMAC-SHA256 ' . hash_hmac('sha256', $body, 'secret'), $sent['options']['headers']);
        $this->assertTrue($result['restocked_lines'][0]['restocked']);
        $this->assertFalse($result['replayed']);
    }

    public function testAReplayIsEquallyFinal(): void
    {
        $client = new PsRestockClient('http://shop.test', 's', new MockHttpClient([new MockResponse(json_encode(['restocked_lines' => [], 'restock_failed' => [], 'replayed' => true]), ['http_code' => 200])]));

        $this->assertTrue($client->restock(self::PAYLOAD)['replayed']);
    }

    /** @return iterable<string, array{int, int}> */
    public static function errors(): iterable
    {
        yield 'unknown product is definitive' => [404, 404];
        yield 'invalid body is definitive' => [422, 422];
        yield 'busy request is a conflict' => [409, 409];
        yield 'server error is unknown' => [500, 0];
        yield 'bad signature is a validation error' => [401, 422];
    }

    /** @dataProvider errors */
    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testHttpErrorsMapToRefundExceptionCodes(int $status, int $code): void
    {
        $client = new PsRestockClient('http://shop.test', 's', new MockHttpClient([new MockResponse('{"error":"x"}', ['http_code' => $status])]));

        try {
            $client->restock(self::PAYLOAD);
            $this->fail('Expected RefundException');
        } catch (RefundException $e) {
            $this->assertSame($code, $e->getCode());
        }
    }

    public function testNetworkFailureIsUnknown(): void
    {
        $client = new PsRestockClient('http://shop.test', 's', new MockHttpClient(static fn () => throw new TransportException('down')));

        $this->expectException(RefundException::class);
        $this->expectExceptionCode(0);
        $client->restock(self::PAYLOAD);
    }

    public function testASuccessWithoutLinesIsNotTrusted(): void
    {
        $client = new PsRestockClient('http://shop.test', 's', new MockHttpClient([new MockResponse('not json', ['http_code' => 201])]));

        $this->expectExceptionCode(0);
        $client->restock(self::PAYLOAD);
    }
}
