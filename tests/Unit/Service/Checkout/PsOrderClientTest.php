<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Checkout;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;
use VenPosTPV\Service\Checkout\PsOrderClient;

final class PsOrderClientTest extends TestCase
{
    private function createClient(array $responses): PsOrderClient
    {
        $httpClient = new MockHttpClient($responses);

        return new PsOrderClient('http://localhost/prestashop', 'test_secret', $httpClient);
    }

    private function captureRequestHeader(array &$capturedHeaders): MockHttpClient
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders): MockResponse {
            $capturedHeaders = $options['headers'] ?? [];

            return new MockResponse(json_encode([
                'order_id' => 42,
                'reference' => 'ORD-042',
                'total_paid' => 99.99,
            ]), ['http_code' => 201]);
        });

        return $httpClient;
    }

    public function testCreateOrderReturnsOrderDataOn201(): void
    {
        $response = new MockResponse(json_encode([
            'order_id' => 42,
            'reference' => 'ORD-042',
            'total_paid' => 99.99,
        ]), ['http_code' => 201]);

        $client = $this->createClient([$response]);
        $result = $client->createOrder([
            'products' => [['id_product' => 1, 'quantity' => 1, 'price' => 99.99]],
            'id_customer' => 1,
            'id_currency' => 1,
            'payment_method' => 'cash',
        ]);

        $this->assertSame(42, $result['order_id']);
        $this->assertSame('ORD-042', $result['reference']);
        $this->assertSame(99.99, $result['total_paid']);
    }

    public function testAuthorizationHeaderContainsHmacSha256(): void
    {
        $capturedHeaders = [];
        $httpClient = $this->captureRequestHeader($capturedHeaders);
        $client = new PsOrderClient('http://localhost/prestashop', 'test_secret', $httpClient);

        $client->createOrder([
            'products' => [['id_product' => 1, 'quantity' => 1, 'price' => 10.00]],
            'id_customer' => 1,
            'id_currency' => 1,
            'payment_method' => 'cash',
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

    public function testCorrectJsonBodyIsSent(): void
    {
        $capturedBody = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
            $capturedBody = $options['body'] ?? null;

            return new MockResponse(json_encode([
                'order_id' => 1,
                'reference' => 'REF',
                'total_paid' => 10.00,
            ]), ['http_code' => 201]);
        });

        $client = new PsOrderClient('http://localhost/prestashop', 'secret', $httpClient);
        $payload = [
            'products' => [['id_product' => 5, 'quantity' => 2, 'price' => 5.00]],
            'id_customer' => 1,
            'id_currency' => 1,
            'payment_method' => 'card',
        ];

        $client->createOrder($payload);

        $this->assertNotNull($capturedBody);
        $decoded = json_decode((string) $capturedBody, true);
        $this->assertCount(1, $decoded['products']);
        $this->assertSame(5, $decoded['products'][0]['id_product']);
        $this->assertSame(2, $decoded['products'][0]['quantity']);
        $this->assertSame('card', $decoded['payment_method']);
    }

    public function test401ThrowsCheckoutException(): void
    {
        $client = $this->createClient([
            new MockResponse(json_encode(['error' => 'Unauthorized']), ['http_code' => 401]),
        ]);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('HMAC authentication rejected');

        $client->createOrder([
            'products' => [['id_product' => 1, 'quantity' => 1, 'price' => 10.00]],
            'id_customer' => 1,
            'id_currency' => 1,
            'payment_method' => 'cash',
        ]);
    }

    public function test422ThrowsCheckoutException(): void
    {
        $client = $this->createClient([
            new MockResponse(json_encode(['error' => 'Validation failed', 'details' => ['products' => 'Required']]), ['http_code' => 422]),
        ]);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('PS validation failed');

        $client->createOrder([
            'products' => [],
            'id_customer' => 1,
            'id_currency' => 1,
            'payment_method' => 'cash',
        ]);
    }

    public function test500ThrowsCheckoutException(): void
    {
        $client = $this->createClient([
            new MockResponse(json_encode(['error' => 'Internal error']), ['http_code' => 500]),
        ]);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('PS module returned HTTP 500');

        $client->createOrder([
            'products' => [['id_product' => 1, 'quantity' => 1, 'price' => 10.00]],
            'id_customer' => 1,
            'id_currency' => 1,
            'payment_method' => 'cash',
        ]);
    }

    public function testNetworkErrorThrowsCheckoutException(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['error' => 'Connection refused']),
        ]);

        $client = new PsOrderClient('http://localhost/prestashop', 'secret', $httpClient);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('PrestaShop order service unavailable');

        $client->createOrder([
            'products' => [['id_product' => 1, 'quantity' => 1, 'price' => 10.00]],
            'id_customer' => 1,
            'id_currency' => 1,
            'payment_method' => 'cash',
        ]);
    }

    public function testHmacSignatureMatchesExpectedValue(): void
    {
        $secret = 'my_secret_key';
        $payload = ['test' => 'data'];
        $jsonBody = json_encode($payload);
        $expectedSignature = hash_hmac('sha256', $jsonBody, $secret);

        $capturedHeaders = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders): MockResponse {
            $capturedHeaders = $options['headers'] ?? [];

            return new MockResponse(json_encode([
                'order_id' => 1,
                'reference' => 'REF',
                'total_paid' => 10.00,
            ]), ['http_code' => 201]);
        });

        $client = new PsOrderClient('http://localhost/prestashop', $secret, $httpClient);
        $client->createOrder($payload);

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
}
