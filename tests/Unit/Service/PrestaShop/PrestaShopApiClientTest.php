<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\PrestaShop;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\PrestaShop\Exception\PsApiException;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;

final class PrestaShopApiClientTest extends TestCase
{
    private function createClient(array $responses): PrestaShopApiClient
    {
        $httpClient = new MockHttpClient($responses);

        return new PrestaShopApiClient('http://localhost/prestashop/api', 'TEST_API_KEY', $httpClient);
    }

    private function createJsonResponse(array $data, int $statusCode = 200): MockResponse
    {
        return new MockResponse(json_encode($data), [
            'http_code' => $statusCode,
            'response_headers' => ['Content-Type: application/json'],
        ]);
    }

    public function testGetProductsReturnsDecodedProducts(): void
    {
        $responseData = [
            'products' => [
                ['id' => 1, 'name' => 'Product 1', 'reference' => 'REF001'],
                ['id' => 2, 'name' => 'Product 2', 'reference' => 'REF002'],
            ],
        ];

        $client = $this->createClient([$this->createJsonResponse($responseData)]);
        $products = $client->getProducts();

        $this->assertCount(2, $products);
        $this->assertSame(1, $products[0]['id']);
        $this->assertSame('Product 1', $products[0]['name']);
    }

    public function testGetCombinationsReturnsDecodedCombinations(): void
    {
        $responseData = [
            'combinations' => [
                ['id' => 10, 'id_product' => 1, 'reference' => 'COMB001'],
            ],
        ];

        $client = $this->createClient([$this->createJsonResponse($responseData)]);
        $combinations = $client->getCombinations();

        $this->assertCount(1, $combinations);
        $this->assertSame(10, $combinations[0]['id']);
    }

    public function testGetStockAvailablesReturnsDecodedStock(): void
    {
        $responseData = [
            'stock_availables' => [
                ['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 15],
            ],
        ];

        $client = $this->createClient([$this->createJsonResponse($responseData)]);
        $stock = $client->getStockAvailables();

        $this->assertCount(1, $stock);
        $this->assertSame(15, $stock[0]['quantity']);
    }

    public function testGetCategoriesReturnsDecodedCategories(): void
    {
        $responseData = [
            'categories' => [
                ['id' => 1, 'name' => 'Root', 'id_parent' => 0],
            ],
        ];

        $client = $this->createClient([$this->createJsonResponse($responseData)]);
        $categories = $client->getCategories();

        $this->assertCount(1, $categories);
        $this->assertSame(1, $categories[0]['id']);
    }

    public function testPaginationFetchesMultiplePages(): void
    {
        $page1 = [
            'products' => array_map(
                static fn (int $i): array => ['id' => $i, 'name' => "Product {$i}"],
                range(1, 100)
            ),
        ];
        $page2 = [
            'products' => [
                ['id' => 101, 'name' => 'Product 101'],
            ],
        ];

        $client = $this->createClient([
            $this->createJsonResponse($page1),
            $this->createJsonResponse($page2),
        ]);

        $products = $client->getProducts();

        $this->assertCount(101, $products);
        $this->assertSame(101, $products[100]['id']);
    }

    public function testExtractTranslationsReturnsLanguageKeyValue(): void
    {
        $field = ['1' => 'Nombre ES', '2' => 'English Name'];

        $result = PrestaShopApiClient::extractTranslations($field, '1');

        $this->assertSame('Nombre ES', $result);
    }

    public function testExtractTranslationsFallsBackToFirstValue(): void
    {
        $field = ['2' => 'English Name'];

        $result = PrestaShopApiClient::extractTranslations($field, '1');

        $this->assertSame('English Name', $result);
    }

    public function testExtractTranslationsReturnsStringAsIs(): void
    {
        $result = PrestaShopApiClient::extractTranslations('Simple Name', '1');

        $this->assertSame('Simple Name', $result);
    }

    public function testHttp401ThrowsPsApiException(): void
    {
        $client = $this->createClient([$this->createJsonResponse([], 401)]);

        $this->expectException(PsApiException::class);
        $this->expectExceptionMessage('Invalid PS API credentials');

        $client->getProducts();
    }

    public function testHttp429ThrowsPsApiException(): void
    {
        $client = $this->createClient([
            new MockResponse('', [
                'http_code' => 429,
                'response_headers' => ['retry-after: 30'],
            ]),
        ]);

        $this->expectException(PsApiException::class);
        $this->expectExceptionMessage('rate limit exceeded');
        $this->expectExceptionCode(429);

        $client->getProducts();
    }

    public function testHttp500ThrowsPsApiException(): void
    {
        $client = $this->createClient([$this->createJsonResponse(['error' => 'Internal error'], 500)]);

        $this->expectException(PsApiException::class);
        $this->expectExceptionCode(500);

        $client->getProducts();
    }

    public function testNetworkErrorThrowsPsApiException(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['error' => 'Connection refused']),
        ]);

        $client = new PrestaShopApiClient('http://localhost/prestashop/api', 'TEST_KEY', $httpClient);

        $this->expectException(PsApiException::class);
        $this->expectExceptionMessage('network error');

        $client->getProducts();
    }

    public function testEmptyResponseStopsPagination(): void
    {
        $client = $this->createClient([$this->createJsonResponse(['products' => []])]);
        $products = $client->getProducts();

        $this->assertCount(0, $products);
    }

    public function testPingReturnsOnlineTrueAndLatencyOnHttp200(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode(['products' => []]), [
                'http_code' => 200,
                'response_headers' => ['Content-Type: application/json'],
            ]),
        ]);

        $client = new PrestaShopApiClient('http://localhost/prestashop/api', 'TEST_KEY', $httpClient);
        $result = $client->ping();

        $this->assertTrue($result['online']);
        $this->assertIsInt($result['latency_ms']);
        $this->assertGreaterThanOrEqual(0, $result['latency_ms']);
    }

    public function testPingReturnsOnlineFalseOnNetworkError(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['error' => 'Connection refused']),
        ]);

        $client = new PrestaShopApiClient('http://localhost/prestashop/api', 'TEST_KEY', $httpClient);
        $result = $client->ping();

        $this->assertFalse($result['online']);
        $this->assertNull($result['latency_ms']);
    }

    public function testPingReturnsOnlineFalseOnHttp401(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 401]),
        ]);

        $client = new PrestaShopApiClient('http://localhost/prestashop/api', 'TEST_KEY', $httpClient);
        $result = $client->ping();

        $this->assertFalse($result['online']);
        $this->assertNull($result['latency_ms']);
    }
}
