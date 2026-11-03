<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Customer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\PrestaShop\Exception\PsApiException;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;

/**
 * The customer calls of the PrestaShop webservice client: the local copy reads
 * /api/customers, and a customer created at the till is looked up by email before
 * it is created, so an upload retried after an unknown outcome links the customer
 * it already created instead of creating a second one.
 */
final class PrestaShopCustomerApiTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];

    /** @param list<MockResponse> $responses */
    private function client(array $responses): PrestaShopApiClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => urldecode($url), 'body' => (string) ($options['body'] ?? '')];

            return array_shift($responses);
        });

        return new PrestaShopApiClient('http://shop.test/api', 'KEY', $http);
    }

    public function testGetCustomersReadsEveryNonDeletedCustomer(): void
    {
        $rows = [['id' => 5, 'firstname' => 'Ana', 'lastname' => 'Martínez', 'email' => 'ana@x.es', 'active' => '1']];
        $customers = $this->client([new MockResponse((string) json_encode(['customers' => $rows]))])->getCustomers();

        $this->assertSame($rows, $customers);
        $this->assertSame('GET', $this->requests[0]['method']);
        $this->assertStringContainsString('/api/customers?display=[id,firstname,lastname,email,active]&filter[deleted]=0&output_format=JSON&limit=0,100', $this->requests[0]['url']);
    }

    public function testFindCustomerIdByEmail(): void
    {
        $client = $this->client([
            new MockResponse((string) json_encode(['customers' => [['id' => 42]]])),
            new MockResponse('[]'),
        ]);

        $this->assertSame(42, $client->findCustomerIdByEmail('marta+tpv@x.es'));
        $this->assertNull($client->findCustomerIdByEmail('nobody@x.es'));
        $this->assertStringContainsString('filter[email]=[marta+tpv@x.es]', $this->requests[0]['url']);
    }

    public function testCreateCustomerPostsTheWebserviceXmlAndReturnsTheNewId(): void
    {
        $id = $this->client([new MockResponse((string) json_encode(['customer' => ['id' => 77]]), ['http_code' => 201])])
            ->createCustomer('Marta & Co', 'Ruiz', 'marta@x.es');

        $this->assertSame(77, $id);
        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertStringContainsString('/api/customers?output_format=JSON', $this->requests[0]['url']);
        $xml = simplexml_load_string($this->requests[0]['body']);
        $this->assertNotFalse($xml);
        $this->assertSame('Marta & Co', (string) $xml->customer->firstname);
        $this->assertSame('Ruiz', (string) $xml->customer->lastname);
        $this->assertSame('marta@x.es', (string) $xml->customer->email);
        $this->assertSame('1', (string) $xml->customer->active);
        $this->assertGreaterThanOrEqual(16, \strlen((string) $xml->customer->passwd));
    }

    public function testCreateCustomerRejectedByPrestaShopKeepsTheHttpStatus(): void
    {
        try {
            $this->client([new MockResponse('{"errors":[{"message":"lastname is invalid"}]}', ['http_code' => 400])])->createCustomer('A', 'B', 'a@x.es');
            $this->fail('A rejected customer must throw');
        } catch (PsApiException $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertStringContainsString('lastname is invalid', $e->getMessage());
        }
    }
}
