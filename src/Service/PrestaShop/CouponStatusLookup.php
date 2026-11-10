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
     * @return array<int, array{quantity: int, active: bool, date_from: string, date_to: string, reduction_amount: float, reduction_percent: float, reduction_tax: bool, partial_use: bool}>|null keyed by id
     *         (a rule PrestaShop no longer has is missing); null when the shop did not answer
     */
    public function find(array $ids): ?array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        return $this->fetch('filter[id]', '[' . implode('|', $ids) . ']');
    }

    /**
     * The cart rules with exactly this code (PrestaShop keeps codes unique, so at most one).
     * Only letters, digits, "-" and "_": anything else could change the filter, so it is not sent.
     *
     * @return array<int, array{quantity: int, active: bool, date_from: string, date_to: string, reduction_amount: float, reduction_percent: float, reduction_tax: bool, partial_use: bool}>|null keyed by id;
     *         null when the shop did not answer or the code cannot be looked up
     */
    public function findByCode(string $code): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,254}$/', $code) !== 1) {
            return null;
        }

        return $this->fetch('filter[code]', '[' . $code . ']');
    }

    /** @return array<int, array{quantity: int, active: bool, date_from: string, date_to: string, reduction_amount: float, reduction_percent: float, reduction_tax: bool, partial_use: bool}>|null */
    private function fetch(string $filter, string $value): ?array
    {
        if ($this->baseUrl === '' || $this->apiKey === '') {
            return null;
        }
        try {
            $response = $this->httpClient->request('GET', $this->baseUrl . '/api/cart_rules', [
                'auth_basic' => [$this->apiKey, ''],
                'query' => [
                    'output_format' => 'JSON',
                    'display' => '[id,quantity,active,date_from,date_to,reduction_amount,reduction_percent,reduction_tax,partial_use]',
                    $filter => $value,
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
                'date_from' => (string) ($rule['date_from'] ?? ''),
                'date_to' => (string) ($rule['date_to'] ?? ''),
                'reduction_amount' => (float) ($rule['reduction_amount'] ?? 0),
                'reduction_percent' => (float) ($rule['reduction_percent'] ?? 0),
                'reduction_tax' => (string) ($rule['reduction_tax'] ?? '0') === '1',
                'partial_use' => (string) ($rule['partial_use'] ?? '0') === '1',
            ];
        }

        return $found;
    }
}
