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

    public function testCustomerPhonesComeFromTheAddressesMobileFirstAndNewestAddressWins(): void
    {
        $urls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = urldecode($url);

            return $this->createJsonResponse(['addresses' => [
                ['id' => 10, 'id_customer' => 5, 'phone' => '910 000 000', 'phone_mobile' => ''],
                ['id' => 11, 'id_customer' => 5, 'phone' => '910 111 111', 'phone_mobile' => '600 555 555'],
                ['id' => 12, 'id_customer' => 7, 'phone' => '', 'phone_mobile' => ''],
                ['id' => 13, 'id_customer' => 0, 'phone' => '600 999 999', 'phone_mobile' => ''],
                ['id' => 14, 'id_customer' => 8, 'phone' => '944 222 333', 'phone_mobile' => ''],
            ]]);
        });
        $client = new PrestaShopApiClient('http://localhost/prestashop/api', 'TEST_API_KEY', $http);

        $this->assertSame([5 => '600 555 555', 8 => '944 222 333'], $client->getCustomerPhones());
        $this->assertStringContainsString('/api/addresses?display=[id,id_customer,phone,phone_mobile]&filter[deleted]=0', $urls[0]);
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

    /** PrestaShop 9 JSON: a list of language entries, not a map keyed by language id. */
    public function testExtractTranslationsReadsTheLanguageEntryOfTheListShape(): void
    {
        $field = [['id' => 1, 'value' => 'Raíz'], ['id' => 2, 'value' => 'Root']];

        $this->assertSame('Raíz', PrestaShopApiClient::extractTranslations($field, '1'));
        $this->assertSame('Root', PrestaShopApiClient::extractTranslations($field, '2'));
    }

    public function testExtractTranslationsFallsBackToTheFirstEntryOfTheListShape(): void
    {
        $field = [['id' => '2', 'value' => 'Root']];

        $this->assertSame('Root', PrestaShopApiClient::extractTranslations($field, '1'));
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

    public function testGetTaxIncludedUnitPriceReturnsPriceCalculatedWithTaxAndReduction(): void
    {
        $client = $this->createClient([
            $this->createJsonResponse([
                'product' => [
                    'id' => 1,
                    'price' => '23.900000',
                    'tpv_ttc' => '23.140000',
                ],
            ]),
        ]);

        $this->assertSame(23.14, $client->getTaxIncludedUnitPrice(1));
    }

    public function testGetTaxIncludedUnitPriceRequestsTaxAndReductionAndCombination(): void
    {
        $requestedUrls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$requestedUrls) {
            $requestedUrls[] = $url;

            return $this->createJsonResponse([
                'product' => ['id' => 1, 'tpv_ttc' => '25.960000'],
            ]);
        });

        $client = new PrestaShopApiClient('http://localhost/prestashop/api', 'TEST_KEY', $httpClient);
        $price = $client->getTaxIncludedUnitPrice(1, 7);

        $this->assertSame(25.96, $price);
        $this->assertCount(1, $requestedUrls);
        $url = $requestedUrls[0];
        $this->assertStringContainsString('/api/products/1?', $url);
        $this->assertStringContainsString('use_tax%5D=1', $url);
        $this->assertStringContainsString('use_reduction%5D=1', $url);
        $this->assertStringContainsString('product_attribute%5D=7', $url);
    }

    public function testGetTaxIncludedUnitPriceOmitsCombinationParameterForBaseProduct(): void
    {
        $requestedUrls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$requestedUrls) {
            $requestedUrls[] = $url;

            return $this->createJsonResponse([
                'product' => ['id' => 1, 'tpv_ttc' => '23.140000'],
            ]);
        });

        $client = new PrestaShopApiClient('http://localhost/prestashop/api', 'TEST_KEY', $httpClient);
        $client->getTaxIncludedUnitPrice(1);

        $this->assertStringNotContainsString('product_attribute', $requestedUrls[0]);
    }

    public function testGetTaxIncludedUnitPriceReturnsNullWhenPriceCannotBeComputed(): void
    {
        foreach ([['product' => ['id' => 1]], ['product' => ['id' => 1, 'tpv_ttc' => '']], ['product' => ['id' => 1, 'tpv_ttc' => 'not-a-number']]] as $payload) {
            $client = $this->createClient([$this->createJsonResponse($payload)]);

            $this->assertNull(
                $client->getTaxIncludedUnitPrice(1),
                'A missing or non-numeric calculated price must yield null so the caller can fall back'
            );
        }
    }

    public function testGetAuthoritativePricesReturnsBothPricesFromOneRequest(): void
    {
        $requestedUrls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$requestedUrls) {
            $requestedUrls[] = $url;

            return $this->createJsonResponse([
                'product' => [
                    'id' => 1,
                    'price' => '23.900000',
                    'tpv_ttc' => '23.140000',
                    'tpv_net' => '19.120000',
                ],
            ]);
        });

        $client = new PrestaShopApiClient('http://localhost/prestashop/api', 'TEST_KEY', $httpClient);
        $prices = $client->getAuthoritativePrices(1, 7);

        $this->assertSame(['ttc' => 23.14, 'net' => 19.12], $prices);
        $this->assertCount(1, $requestedUrls, 'both prices must come from a single request');
        $this->assertStringContainsString('use_tax%5D=1', $requestedUrls[0]);
        $this->assertStringContainsString('use_tax%5D=0', $requestedUrls[0]);
        $this->assertStringContainsString('product_attribute%5D=7', $requestedUrls[0]);
    }

    public function testGetAuthoritativePricesKeepsTtcWhenNetIsUnavailable(): void
    {
        $client = $this->createClient([
            $this->createJsonResponse([
                'product' => ['id' => 1, 'tpv_ttc' => '23.140000'],
            ]),
        ]);

        $this->assertSame(['ttc' => 23.14, 'net' => null], $client->getAuthoritativePrices(1));
    }

    public function testGetTaxIncludedUnitPriceThrowsOnRejectedCredentials(): void
    {
        $client = $this->createClient([
            $this->createJsonResponse(['errors' => [['code' => 26, 'message' => 'not allowed']]], 401),
        ]);

        $this->expectException(PsApiException::class);

        $client->getTaxIncludedUnitPrice(1);
    }
}
