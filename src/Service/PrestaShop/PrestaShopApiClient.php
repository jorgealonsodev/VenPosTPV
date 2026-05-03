<?php

declare(strict_types=1);

namespace VenPosTPV\Service\PrestaShop;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use VenPosTPV\Service\PrestaShop\Exception\PsApiException;

class PrestaShopApiClient
{
    private readonly string $baseUrl;

    public function __construct(
        string $baseUrl,
        private readonly string $apiKey,
        private readonly HttpClientInterface $httpClient
    ) {
        $baseUrl = rtrim($baseUrl, '/');

        if (str_ends_with($baseUrl, '/api')) {
            $baseUrl = substr($baseUrl, 0, -4);
        }

        $this->baseUrl = $baseUrl;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProducts(): array
    {
        return $this->paginate('/products?display=full&filter[active]=1&output_format=JSON');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCombinations(): array
    {
        return $this->paginate('/combinations?display=full&output_format=JSON');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getStockAvailables(): array
    {
        return $this->paginate('/stock_availables?display=full&output_format=JSON');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCategories(): array
    {
        return $this->paginate('/categories?display=full&output_format=JSON');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function paginate(string $endpoint): array
    {
        $results = [];
        $offset = 0;
        $limit = 100;

        do {
            $url = sprintf(
                '%s/api%s&limit=%d,%d',
                $this->baseUrl,
                $endpoint,
                $offset,
                $limit
            );

            $response = $this->request('GET', $url);
            $data = $this->decodeResponse($response);

            $pageResults = $data[$this->getEntityKey($endpoint)] ?? [];

            if (!\is_array($pageResults) || $pageResults === []) {
                break;
            }

            foreach ($pageResults as $item) {
                $results[] = $item;
            }

            $offset += $limit;
        } while (\count($pageResults) === $limit);

        return $results;
    }

    private function request(string $method, string $url): ResponseInterface
    {
        try {
            $response = $this->httpClient->request($method, $url, [
                'headers' => [
                    'Authorization' => 'Basic ' . $this->buildAuthHeader(),
                ],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 401) {
                throw PsApiException::httpError(401, 'Invalid PS API credentials', $response);
            }

            if ($statusCode === 429) {
                $retryAfter = (int) ($response->getHeaders(false)['retry-after'][0] ?? 60);

                throw PsApiException::rateLimitExceeded($retryAfter);
            }

            if ($statusCode >= 400 && $statusCode < 600) {
                throw PsApiException::httpError(
                    $statusCode,
                    $response->getContent(false),
                    $response
                );
            }

            return $response;
        } catch (PsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw PsApiException::networkError($e->getMessage(), $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(ResponseInterface $response): array
    {
        $content = $response->getContent();
        $decoded = json_decode($content, true);

        if (!\is_array($decoded)) {
            throw PsApiException::httpError(
                $response->getStatusCode(),
                'Invalid JSON response from PrestaShop API',
                $response
            );
        }

        return $decoded;
    }

    private function buildAuthHeader(): string
    {
        return base64_encode($this->apiKey . ':');
    }

    private function getEntityKey(string $endpoint): string
    {
        if (str_contains($endpoint, '/products')) {
            return 'products';
        }

        if (str_contains($endpoint, '/combinations')) {
            return 'combinations';
        }

        if (str_contains($endpoint, '/stock_availables')) {
            return 'stock_availables';
        }

        if (str_contains($endpoint, '/categories')) {
            return 'categories';
        }

        return 'items';
    }

    /**
     * Extract a translation from a multi-lang PS field.
     *
     * @param array<string, mixed>|string $field
     */
    public static function extractTranslations(array|string $field, string $languageKey = '1'): mixed
    {
        if (\is_string($field)) {
            return $field;
        }

        if (isset($field[$languageKey])) {
            return $field[$languageKey];
        }

        if ($field !== []) {
            return array_values($field)[0];
        }

        return null;
    }
}
