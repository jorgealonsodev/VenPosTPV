<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Refund;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\PsOrderLookupClient;

final class PsOrderLookupClientTest extends TestCase
{
    public function testPostsTheSignedQueryToTheLookupEndpointAndReturnsTheOrders(): void
    {
        $sent = [];
        $client = new PsOrderLookupClient('http://shop.test/', 'secret', new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = compact('method', 'url', 'options');

            return new MockResponse(json_encode(['orders' => [['id_order' => 77, 'reference' => 'XKBKNABJK']]]), ['http_code' => 200]);
        }));

        $orders = $client->lookup(['reference' => 'XKBKNABJK']);

        $this->assertSame('POST', $sent['method']);
        $this->assertSame('http://shop.test/module/tpvconnect/orderlookup', $sent['url']);
        $body = '{"reference":"XKBKNABJK"}';
        $this->assertSame($body, $sent['options']['body']);
        $this->assertContains('Authorization: HMAC-SHA256 ' . hash_hmac('sha256', $body, 'secret'), $sent['options']['headers']);
        $this->assertSame([['id_order' => 77, 'reference' => 'XKBKNABJK']], $orders);
    }

    public function testNoSuchOrderIsAnEmptyList(): void
    {
        $client = new PsOrderLookupClient('http://shop.test', 's', new MockHttpClient([new MockResponse('{"error":"Order not found"}', ['http_code' => 404])]));

        $this->assertSame([], $client->lookup(['id_order' => 5]));
    }

    /** @return iterable<string, array{MockResponse|\Throwable, int}> */
    public static function failures(): iterable
    {
        yield 'invalid query' => [new MockResponse('{"error":"Validation failed","details":{"reference":"x"}}', ['http_code' => 422]), 422];
        yield 'bad signature' => [new MockResponse('{"error":"Unauthorized"}', ['http_code' => 401]), 0];
        yield 'server error' => [new MockResponse('boom', ['http_code' => 500]), 0];
        yield 'invalid json' => [new MockResponse('not json', ['http_code' => 200]), 0];
        yield 'shop down' => [new TransportException('Connection refused'), 0];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testFailuresAreUnavailableOrRejected(MockResponse|\Throwable $response, int $code): void
    {
        $http = $response instanceof \Throwable
            ? new MockHttpClient(static fn () => throw $response)
            : new MockHttpClient([$response]);

        try {
            (new PsOrderLookupClient('http://shop.test', 's', $http))->lookup(['id_order' => 5]);
            $this->fail('Expected RefundException');
        } catch (RefundException $e) {
            $this->assertSame($code, $e->getCode());
        }
    }
}
