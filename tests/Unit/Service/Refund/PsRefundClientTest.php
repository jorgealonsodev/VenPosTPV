<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Refund;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\PsRefundClient;

final class PsRefundClientTest extends TestCase
{
    private function createClient(array $responses): PsRefundClient
    {
        $httpClient = new MockHttpClient($responses);

        return new PsRefundClient('http://localhost/prestashop', 'test_secret', $httpClient);
    }

    private function captureRequestHeader(array &$capturedHeaders): MockHttpClient
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders): MockResponse {
            $capturedHeaders = $options['headers'] ?? [];

            return new MockResponse(json_encode([
                'order_slip_id' => 7,
                'refunded_amount' => 29.90,
                'refunded_lines' => [['id_product' => 5, 'quantity' => 2, 'amount' => 29.90]],
            ]), ['http_code' => 201]);
        });

        return $httpClient;
    }

    public function testCreateRefundReturnsDataOn201(): void
    {
        $response = new MockResponse(json_encode([
            'order_slip_id' => 7,
            'refunded_amount' => 29.90,
            'refunded_lines' => [['id_product' => 5, 'quantity' => 2, 'amount' => 29.90]],
        ]), ['http_code' => 201]);

        $client = $this->createClient([$response]);
        $result = $client->createRefund([
            'ps_order_id' => 42,
            'lines' => [['id_product' => 5, 'quantity' => 2, 'amount' => 29.90]],
            'refund_type' => 'partial',
            'reason' => 'Defective',
        ]);

        $this->assertSame(7, $result['order_slip_id']);
        $this->assertSame(29.90, $result['refunded_amount']);
        $this->assertCount(1, $result['refunded_lines']);
    }

    public function testAuthorizationHeaderContainsHmacSha256(): void
    {
        $capturedHeaders = [];
        $httpClient = $this->captureRequestHeader($capturedHeaders);
        $client = new PsRefundClient('http://localhost/prestashop', 'test_secret', $httpClient);

        $client->createRefund([
            'ps_order_id' => 42,
            'lines' => [['id_product' => 5, 'quantity' => 2, 'amount' => 29.90]],
            'refund_type' => 'partial',
        ]);

        $authHeader = null;
        foreach ($capturedHeaders as $header) {
            if (str_starts_with($header, 'Authorization:')) {
                $authHeader = $header;
                break;
            }
        }

        $this->assertNotNull($authHeader);
        $this->assertStringStartsWith('Authorization: HMAC-SHA256 ', $authHeader);
        $signature = substr($authHeader, strlen('Authorization: HMAC-SHA256 '));
        $this->assertSame(64, strlen($signature)); // hex SHA-256
    }

    public function test401ThrowsRefundException(): void
    {
        $client = $this->createClient([
            new MockResponse(json_encode(['error' => 'Unauthorized']), ['http_code' => 401]),
        ]);

        $this->expectException(RefundException::class);
        $this->expectExceptionMessage('HMAC authentication rejected');

        $client->createRefund([
            'ps_order_id' => 42,
            'lines' => [],
            'refund_type' => 'full',
        ]);
    }

    public function test422ThrowsRefundException(): void
    {
        $client = $this->createClient([
            new MockResponse(json_encode(['error' => 'Validation failed', 'details' => ['lines' => 'Required']]), ['http_code' => 422]),
        ]);

        $this->expectException(RefundException::class);
        $this->expectExceptionMessage('PS validation failed');

        $client->createRefund([
            'ps_order_id' => 42,
            'lines' => [],
            'refund_type' => 'full',
        ]);
    }

    public function test500ThrowsRefundException(): void
    {
        $client = $this->createClient([
            new MockResponse(json_encode(['error' => 'Internal error']), ['http_code' => 500]),
        ]);

        $this->expectException(RefundException::class);
        $this->expectExceptionMessage('PS module returned HTTP 500');

        $client->createRefund([
            'ps_order_id' => 42,
            'lines' => [],
            'refund_type' => 'full',
        ]);
    }

    public function testNetworkErrorThrowsRefundException(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['error' => 'Connection refused']),
        ]);

        $client = new PsRefundClient('http://localhost/prestashop', 'secret', $httpClient);

        $this->expectException(RefundException::class);
        $this->expectExceptionMessage('PrestaShop refund service unavailable');

        $client->createRefund([
            'ps_order_id' => 42,
            'lines' => [],
            'refund_type' => 'full',
        ]);
    }

    public function testHmacSignatureMatchesExpectedValue(): void
    {
        $secret = 'my_secret_key';
        $payload = ['ps_order_id' => 42, 'refund_type' => 'full'];
        $jsonBody = json_encode($payload);
        $expectedSignature = hash_hmac('sha256', $jsonBody, $secret);

        $capturedHeaders = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders): MockResponse {
            $capturedHeaders = $options['headers'] ?? [];

            return new MockResponse(json_encode([
                'order_slip_id' => 1,
                'refunded_amount' => 10.00,
                'refunded_lines' => [],
            ]), ['http_code' => 201]);
        });

        $client = new PsRefundClient('http://localhost/prestashop', $secret, $httpClient);
        $client->createRefund($payload);

        $authHeader = null;
        foreach ($capturedHeaders as $header) {
            if (str_starts_with($header, 'Authorization:')) {
                $authHeader = $header;
                break;
            }
        }

        $this->assertNotNull($authHeader);
        $parts = explode(' ', $authHeader);
        $this->assertCount(3, $parts);
        $this->assertSame($expectedSignature, $parts[2]);
    }

    public function testReplayed200IsAFinalSuccess(): void
    {
        $client = $this->createClient([new MockResponse(json_encode([
            'order_slip_id' => 9, 'refunded_amount' => 10.0, 'refunded_lines' => [], 'restock_failed' => [], 'replayed' => true,
        ]), ['http_code' => 200])]);

        $result = $client->createRefund(['ps_order_id' => 42, 'request_id' => 'abcdefgh12', 'lines' => []]);

        $this->assertSame(9, $result['order_slip_id']);
        $this->assertTrue($result['replayed']);
    }

    public function testDefiniteRejectionsKeepTheirStatusCode(): void
    {
        foreach ([404 => 404, 409 => 409, 422 => 422] as $http => $code) {
            $client = $this->createClient([new MockResponse(json_encode(['error' => 'x', 'details' => ['lines.0.quantity' => 'Only 0 unit(s) left to refund']]), ['http_code' => $http])]);
            try {
                $client->createRefund(['ps_order_id' => 42]);
                $this->fail('Expected RefundException for HTTP ' . $http);
            } catch (RefundException $e) {
                $this->assertSame($code, $e->getCode());
            }
        }
    }
}
