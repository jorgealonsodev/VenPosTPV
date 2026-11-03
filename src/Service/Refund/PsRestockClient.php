<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Refund;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use VenPosTPV\Service\Refund\Exception\RefundException;

/**
 * Restocks goods returned without a ticket through the TPV Connect module
 * (POST /module/tpvconnect/restock), signed like PsRefundClient. 404 and 422 are
 * definitive rejections; 409 and anything else leave the outcome unknown.
 */
class PsRestockClient
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
     * @param array{request_id: string, lines: array<int, array{id_product: int, id_product_attribute: int, quantity: int}>} $payload
     *
     * @return array{restocked_lines: array<int, array<string, mixed>>, restock_failed: array<int, mixed>, replayed: bool}
     *
     * @throws RefundException
     */
    public function restock(array $payload): array
    {
        $jsonBody = json_encode($payload);
        if ($jsonBody === false) {
            throw RefundException::validationFailed('Failed to JSON-encode payload');
        }

        try {
            $response = $this->httpClient->request('POST', $this->shopUrl . '/module/tpvconnect/restock', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'HMAC-SHA256 ' . hash_hmac('sha256', $jsonBody, $this->sharedSecret),
                ],
                'body' => $jsonBody,
                'timeout' => 30,
                'max_duration' => 30,
            ]);

            return $this->handleResponse($response);
        } catch (RefundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw RefundException::psUnavailable($e->getMessage(), $e);
        }
    }

    /** @return array{restocked_lines: array<int, array<string, mixed>>, restock_failed: array<int, mixed>, replayed: bool} */
    private function handleResponse(ResponseInterface $response): array
    {
        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);
        $data = json_decode($content, true);

        if ($statusCode === 200 || $statusCode === 201) {
            if (!\is_array($data) || !\is_array($data['restocked_lines'] ?? null)) {
                throw RefundException::psUnavailable('Invalid JSON response from PS module');
            }

            return [
                'restocked_lines' => $data['restocked_lines'],
                'restock_failed' => \is_array($data['restock_failed'] ?? null) ? $data['restock_failed'] : [],
                'replayed' => !empty($data['replayed']),
            ];
        }

        $details = \is_array($data) && isset($data['details']) ? json_encode($data['details']) : $content;

        return match (true) {
            $statusCode === 401 => throw RefundException::validationFailed('HMAC authentication rejected by PS module'),
            $statusCode === 404 => throw new RefundException(sprintf('Product not found in PrestaShop: %s', $details), 404),
            $statusCode === 409 => throw RefundException::conflict(sprintf('This restock is already in progress in PrestaShop: %s', $details)),
            $statusCode === 422 => throw RefundException::validationFailed(sprintf('PS validation failed: %s', $details)),
            default => throw RefundException::psUnavailable(sprintf('PS module returned HTTP %d: %s', $statusCode, $content)),
        };
    }
}
