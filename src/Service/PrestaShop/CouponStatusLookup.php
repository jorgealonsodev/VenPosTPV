<?php

declare(strict_types=1);

namespace VenPosTPV\Service\PrestaShop;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the live state of cart rules (coupons) from the PrestaShop webservice in
 * one request: uses left, active and end date. Never throws: without the shop
 * the caller shows what the till knows.
 */
class CouponStatusLookup
{
    private readonly string $baseUrl;

    public function __construct(
        string $shopUrl,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly HttpClientInterface $httpClient,
    ) {
        $shopUrl = rtrim($shopUrl, '/');
        $this->baseUrl = str_ends_with($shopUrl, '/api') ? substr($shopUrl, 0, -4) : $shopUrl;
    }

    /**
     * @param list<int> $ids cart rule ids
     *
     * @return array<int, array{quantity: int, active: bool, date_to: string}>|null keyed by id
     *         (a rule PrestaShop no longer has is missing); null when the shop did not answer
     */
    public function find(array $ids): ?array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        if ($this->baseUrl === '' || $this->apiKey === '') {
            return null;
        }
        try {
            $response = $this->httpClient->request('GET', $this->baseUrl . '/api/cart_rules', [
                'auth_basic' => [$this->apiKey, ''],
                'query' => [
                    'output_format' => 'JSON',
                    'display' => '[id,quantity,active,date_to]',
                    'filter[id]' => '[' . implode('|', $ids) . ']',
                ],
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 5,
                'max_redirects' => 0,
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $data = json_decode($response->getContent(false), true);
        } catch (\Throwable) {
            return null;
        }
        // No match: PrestaShop answers an empty array instead of {"cart_rules": []}.
        if ($data === []) {
            return [];
        }
        if (!\is_array($data) || !\is_array($data['cart_rules'] ?? null)) {
            return null;
        }
        $found = [];
        foreach ($data['cart_rules'] as $rule) {
            if (!\is_array($rule) || !is_numeric($rule['id'] ?? null)) {
                continue;
            }
            $found[(int) $rule['id']] = [
                'quantity' => (int) ($rule['quantity'] ?? 0),
                'active' => (string) ($rule['active'] ?? '0') === '1',
                'date_to' => (string) ($rule['date_to'] ?? ''),
            ];
        }

        return $found;
    }
}
