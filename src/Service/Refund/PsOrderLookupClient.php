<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Refund;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use VenPosTPV\Service\Refund\Exception\RefundException;

/**
 * Reads PrestaShop orders that are not TPV sales (online orders) through the TPV
 * Connect module (POST /module/tpvconnect/orderlookup), signed like PsRefundClient.
 * The module returns the paid amount per line (discounts prorated), the quantity
 * still refundable after credit slips and the delivery date.
 */
class PsOrderLookupClient
{
    private readonly string $shopUrl;

    public function __construct(
        string $shopUrl,
        private readonly string $sharedSecret,
        private readonly HttpClientInterface $httpClient,
    ) {
        $this->shopUrl = rtrim($shopUrl, '/');
    }

    /**
     * @param array{id_order: int}|array{reference: string} $query
     *
     * @return array<int, array<string, mixed>> the matching orders; empty when there is none
     *
     * @throws RefundException 502 when the shop cannot answer, 422 when it rejects the query
     */
    public function lookup(array $query): array
    {
        $jsonBody = json_encode($query, JSON_THROW_ON_ERROR);

        try {
            $response = $this->httpClient->request('POST', $this->shopUrl . '/module/tpvconnect/orderlookup', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'HMAC-SHA256 ' . hash_hmac('sha256', $jsonBody, $this->sharedSecret),
                ],
                'body' => $jsonBody,
                'timeout' => 15,
                'max_duration' => 15,
            ]);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (\Throwable $e) {
            throw RefundException::psUnavailable($e->getMessage(), $e);
        }

        if ($statusCode === 404) {
            return [];
        }
        $data = json_decode($content, true);
        if ($statusCode === 200) {
            if (!\is_array($data) || !\is_array($data['orders'] ?? null)) {
                throw RefundException::psUnavailable('Invalid JSON response from PS module');
            }

            return array_values(array_filter($data['orders'], 'is_array'));
        }
        if ($statusCode === 422) {
            $details = \is_array($data) && isset($data['details']) ? json_encode($data['details']) : $content;

            throw RefundException::validationFailed(sprintf('PS order lookup rejected: %s', $details));
        }

        throw RefundException::psUnavailable(sprintf('PS order lookup returned HTTP %d', $statusCode));
    }
}
