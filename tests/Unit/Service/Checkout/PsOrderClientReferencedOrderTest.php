<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Checkout;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;
use VenPosTPV\Service\Checkout\PsOrderClient;

/**
 * Module 0.6.0 contract (ReferencedOrderCreator): 201 created, 200 replayed for an
 * existing order, 409 while the reference lock is busy, 404/422 rejected.
 */
final class PsOrderClientReferencedOrderTest extends TestCase
{
    private const PAYLOAD = ['products' => [['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 1, 'price' => 10.0]], 'id_customer' => 0, 'reference' => 'A26000123'];

    private function client(MockResponse ...$responses): PsOrderClient
    {
        return new PsOrderClient('http://localhost/prestashop', 'test_secret', new MockHttpClient($responses));
    }

    public function testCreatedAndReplayedOrdersAreBothSuccess(): void
    {
        $sent = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = json_decode((string) $options['body'], true);

            return new MockResponse(json_encode(['order_id' => 7, 'reference' => 'A26000123', 'total_paid' => 10.0, 'replayed' => false]), ['http_code' => 201]);
        });
        $created = (new PsOrderClient('http://localhost/prestashop', 'test_secret', $http))->createReferencedOrder(self::PAYLOAD);
        $this->assertSame(['order_id' => 7, 'reference' => 'A26000123', 'total_paid' => 10.0, 'replayed' => false], $created);
        $this->assertSame('A26000123', $sent['reference'], 'the ticket code travels as the order reference');

        $replayed = $this->client(new MockResponse(json_encode(['order_id' => 7, 'reference' => 'A26000123', 'total_paid' => 10.0, 'replayed' => true]), ['http_code' => 200]))
            ->createReferencedOrder(self::PAYLOAD);
        $this->assertSame(['order_id' => 7, 'reference' => 'A26000123', 'total_paid' => 10.0, 'replayed' => true], $replayed);
    }

    public function testRejectionsAreDefinitiveAndEverythingElseIsTemporary(): void
    {
        foreach ([[422, 422], [404, 422], [409, 0], [500, 0], [503, 0], [401, 0]] as [$status, $code]) {
            try {
                $this->client(new MockResponse(json_encode(['error' => 'x', 'details' => ['products' => 'Product 1 not found']]), ['http_code' => $status]))
                    ->createReferencedOrder(self::PAYLOAD);
                $this->fail('HTTP ' . $status . ' must throw');
            } catch (CheckoutException $e) {
                $this->assertSame($code, $e->getCode(), 'HTTP ' . $status);
                $this->assertStringContainsString((string) $status, $e->getMessage());
            }
        }
    }

    public function testNetworkFailureAndIncompleteAnswersAreTemporary(): void
    {
        foreach ([new MockResponse('', ['error' => 'Connection refused']), new MockResponse('{"order_id":0}', ['http_code' => 201]), new MockResponse('not json', ['http_code' => 200])] as $response) {
            try {
                $this->client($response)->createReferencedOrder(self::PAYLOAD);
                $this->fail('must throw');
            } catch (CheckoutException $e) {
                $this->assertSame(0, $e->getCode());
            }
        }
    }

    public function testAPayloadWithoutTicketCodeIsRefusedBeforeSending(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->createReferencedOrder(['products' => []]);
    }
}
