<?php

declare(strict_types=1);

namespace VenPosTPV\Service\PrestaShop;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use VenPosTPV\Service\PrestaShop\Exception\PsApiException;

class PrestaShopApiClient
{
    private readonly string $baseUrl;

    /**
     * Request/response key used to ask the Web Service for a calculated price.
     * PrestaShop echoes the chosen key back on the product resource.
     */
    private const TTC_PRICE_KEY = 'tpv_ttc';

    /** Request/response key for the tax-excluded price with reductions applied. */
    private const NET_PRICE_KEY = 'tpv_net';

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
     * Every customer PrestaShop still has, for the till's local copy (PRD C1).
     * The customer resource has no phone; addresses do, and they are not read.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCustomers(): array
    {
        return $this->paginate('/customers?display=[id,firstname,lastname,email,active]&filter[deleted]=0&output_format=JSON');
    }

    /**
     * The PrestaShop customer with this email, if any (the first one when there are several).
     *
     * @throws PsApiException
     */
    public function findCustomerIdByEmail(string $email): ?int
    {
        $url = sprintf(
            '%s/api/customers?display=[id]&filter[email]=[%s]&filter[deleted]=0&sort=[id_ASC]&output_format=JSON',
            $this->baseUrl,
            rawurlencode($email)
        );
        $rows = $this->decodeResponse($this->request('GET', $url))['customers'] ?? [];
        if (!\is_array($rows) || $rows === [] || !\is_array($rows[0] ?? null) || (int) ($rows[0]['id'] ?? 0) < 1) {
            return null;
        }

        return (int) $rows[0]['id'];
    }

    /**
     * Creates a customer through the webservice (POST /api/customers, XML as
     * PrestaShop requires for writes). The password is random: the customer can
     * reset it from the shop. No group is sent, so PrestaShop's default
     * applies (to confirm against the live shop).
     *
     * @return int the new PrestaShop customer id
     *
     * @throws PsApiException with the HTTP status as code (400 when PrestaShop rejects the data)
     */
    public function createCustomer(string $firstname, string $lastname, string $email): int
    {
        $xml = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><prestashop xmlns:xlink="http://www.w3.org/1999/xlink"><customer><firstname>%s</firstname><lastname>%s</lastname><email>%s</email><passwd>%s</passwd><active>1</active></customer></prestashop>',
            htmlspecialchars($firstname, \ENT_XML1 | \ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($lastname, \ENT_XML1 | \ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($email, \ENT_XML1 | \ENT_QUOTES, 'UTF-8'),
            bin2hex(random_bytes(12)),
        );
        $response = $this->request('POST', sprintf('%s/api/customers?output_format=JSON', $this->baseUrl), 15, [
            'headers' => ['Content-Type' => 'application/xml'],
            'body' => $xml,
        ]);
        $id = (int) ($this->decodeResponse($response)['customer']['id'] ?? 0);
        if ($id < 1) {
            throw PsApiException::httpError($response->getStatusCode(), 'The created customer has no id', $response);
        }

        return $id;
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
    public function getProductOptionValues(): array
    {
        return $this->paginate('/product_option_values?display=[id,id_attribute_group,name,color]&output_format=JSON');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProductOptions(): array
    {
        return $this->paginate('/product_options?display=[id,name]&output_format=JSON');
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
     * Returns the authoritative tax-included unit price of a product, or of one
     * of its combinations, as PrestaShop would charge it in its own cart.
     *
     * This is the price the TPV must charge: `getProducts()` exposes the raw
     * `price` field, which is tax-excluded and has no reduction applied, while
     * the shop books orders with `Cart::getOrderTotal(true, Cart::BOTH)`.
     *
     * The calculated-price parameter only works on a single product resource, so
     * this costs one request per product.
     *
     * The price is calculated in the default visitor context (default currency,
     * country and group). Quantity-based pricing, cart rules and shipping are
     * still only applied by PrestaShop when the order is created.
     *
     * @return float|null the calculated price, or null when PrestaShop cannot
     *                    calculate one (the caller falls back to the base price)
     *
     * @throws PsApiException on network or HTTP error
     */
    public function getTaxIncludedUnitPrice(int $productId, int $combinationId = 0): ?float
    {
        $prices = $this->getAuthoritativePrices($productId, $combinationId);

        return $prices['ttc'] ?? null;
    }

    /**
     * Returns both authoritative unit prices PrestaShop would charge for a product
     * or for one of its combinations, resolved in a single request:
     *
     * - `ttc`: tax included, with reductions applied — the amount the shop books.
     * - `net`: tax excluded, with reductions applied — the base the shop applies
     *   its tax to, so `ttc - net` is the VAT actually charged.
     *
     * The tax-excluded price is NOT `catalog_product.price`: that one is the raw
     * Web Service base price, which ignores reductions.
     *
     * The calculated-price parameter only works on a single product resource, so
     * this costs one request per product.
     *
     * @return array{ttc: float|null, net: float|null}|null null when PrestaShop
     *                                                      returns no product payload
     *
     * @throws PsApiException on network or HTTP error
     */
    public function getAuthoritativePrices(int $productId, int $combinationId = 0): ?array
    {
        $ttcParameters = ['use_tax' => 1, 'use_reduction' => 1];
        $netParameters = ['use_tax' => 0, 'use_reduction' => 1];

        if ($combinationId > 0) {
            $ttcParameters['product_attribute'] = $combinationId;
            $netParameters['product_attribute'] = $combinationId;
        }

        $query = http_build_query([
            'price' => [
                self::TTC_PRICE_KEY => $ttcParameters,
                self::NET_PRICE_KEY => $netParameters,
            ],
            'output_format' => 'JSON',
        ]);

        $url = sprintf('%s/api/products/%d?%s', $this->baseUrl, $productId, $query);
        $response = $this->request('GET', $url);
        $data = $this->decodeResponse($response);

        $product = $data['product'] ?? null;
        if (!\is_array($product)) {
            return null;
        }

        return [
            'ttc' => $this->scalarPrice($product[self::TTC_PRICE_KEY] ?? null),
            'net' => $this->scalarPrice($product[self::NET_PRICE_KEY] ?? null),
        ];
    }

    /**
     * Reads a calculated price defensively: a missing, empty or non-numeric value
     * must never be cast to 0.00 and accepted as authoritative.
     */
    private function scalarPrice(mixed $value): ?float
    {
        if (\is_array($value)) {
            $value = $value['value'] ?? null;
        }

        if (!\is_scalar($value) || !is_numeric($value)) {
            return null;
        }

        $price = (float) $value;

        return is_finite($price) && $price >= 0 ? $price : null;
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

    /**
     * Update the quantity of a stock_available record in PrestaShop.
     * Uses PUT /api/stock_availables/{id} with XML payload (PS WebService requires XML for PUT).
     *
     * @throws PsApiException on network or HTTP error
     */
    public function updateStockAvailable(int $psStockId, int $newQuantity): void
    {
        $url = sprintf('%s/api/stock_availables/%d?output_format=JSON', $this->baseUrl, $psStockId);

        // First GET to retrieve full object (PS PUT requires the full XML resource)
        $response = $this->request('GET', sprintf('%s/api/stock_availables/%d?output_format=JSON', $this->baseUrl, $psStockId));
        $data = $this->decodeResponse($response);
        $stockAvailable = $data['stock_available'] ?? null;

        if (!\is_array($stockAvailable)) {
            throw PsApiException::httpError(0, sprintf('stock_available %d not found in PS', $psStockId), $response);
        }

        // Build minimal XML for PUT
        $xml = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><prestashop xmlns:xlink="http://www.w3.org/1999/xlink"><stock_available><id>%d</id><id_product>%d</id_product><id_product_attribute>%d</id_product_attribute><id_shop>%d</id_shop><id_shop_group>%d</id_shop_group><quantity>%d</quantity><depends_on_stock>%d</depends_on_stock><out_of_stock>%d</out_of_stock></stock_available></prestashop>',
            (int) ($stockAvailable['id'] ?? $psStockId),
            (int) ($stockAvailable['id_product'] ?? 0),
            (int) ($stockAvailable['id_product_attribute'] ?? 0),
            (int) ($stockAvailable['id_shop'] ?? 1),
            (int) ($stockAvailable['id_shop_group'] ?? 0),
            $newQuantity,
            (int) ($stockAvailable['depends_on_stock'] ?? 0),
            (int) ($stockAvailable['out_of_stock'] ?? 2),
        );

        try {
            $putResponse = $this->httpClient->request('PUT', $url, [
                'headers' => [
                    'Authorization' => 'Basic ' . $this->buildAuthHeader(),
                    'Content-Type' => 'application/xml',
                ],
                'body' => $xml,
                'timeout' => 10,
            ]);

            $statusCode = $putResponse->getStatusCode();
            if ($statusCode >= 400) {
                throw PsApiException::httpError($statusCode, $putResponse->getContent(false), $putResponse);
            }
        } catch (PsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw PsApiException::networkError($e->getMessage(), $e);
        }
    }

    /**
     * @return array{online: bool, latency_ms: int|null}
     */
    public function ping(): array
    {
        $url = sprintf('%s/api?limit=0&output_format=JSON', $this->baseUrl);
        $start = hrtime(true);

        try {
            $response = $this->request('GET', $url, 5);
            $latency = (int) ((hrtime(true) - $start) / 1_000_000);

            return ['online' => true, 'latency_ms' => $latency];
        } catch (\Throwable) {
            return ['online' => false, 'latency_ms' => null];
        }
    }

    /** @param array{headers?: array<string, string>, body?: string} $options */
    private function request(string $method, string $url, int $timeout = 10, array $options = []): ResponseInterface
    {
        try {
            $response = $this->httpClient->request($method, $url, [
                'headers' => [
                    'Authorization' => 'Basic ' . $this->buildAuthHeader(),
                ] + ($options['headers'] ?? []),
                'timeout' => $timeout,
            ] + (isset($options['body']) ? ['body' => $options['body']] : []));

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

        if (str_contains($endpoint, '/customers')) {
            return 'customers';
        }

        if (str_contains($endpoint, '/categories')) {
            return 'categories';
        }

        if (str_contains($endpoint, '/product_option_values')) {
            return 'product_option_values';
        }

        if (str_contains($endpoint, '/product_options')) {
            return 'product_options';
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
