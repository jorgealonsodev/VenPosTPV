<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Refund;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use VenPosTPV\Service\Refund\Exception\RefundException;

class PsRefundClient
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
     * @param array<string, mixed> $payload
     *
     * @return array{order_slip_id: int, refunded_amount: float, refunded_lines: array<int, array<string, mixed>>, restock_failed: array<int, mixed>, replayed: bool}
     *
     * @throws RefundException on network/validation/PS errors
     */
    public function createRefund(array $payload): array
    {
        $jsonBody = json_encode($payload);
        if ($jsonBody === false) {
            throw RefundException::validationFailed('Failed to JSON-encode payload');
        }

        $signature = hash_hmac('sha256', $jsonBody, $this->sharedSecret);
        $url = sprintf('%s/module/tpvconnect/refunds', $this->shopUrl);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => sprintf('HMAC-SHA256 %s', $signature),
                ],
                'body' => $jsonBody,
                'timeout' => 30,
                'max_duration' => 30,
            ]);

            return $this->handleResponse($response);
        } catch (RefundException $e) {
            throw $e;
        } catch (TransportExceptionInterface $e) {
            throw RefundException::psUnavailable($e->getMessage(), $e);
        } catch (\Throwable $e) {
            throw RefundException::psUnavailable($e->getMessage(), $e);
        }
    }

    /**
     * 201 is a new credit slip; 200 is the module replaying the result of a request_id
     * it already processed, which is equally final.
     *
     * @return array{order_slip_id: int, refunded_amount: float, refunded_lines: array<int, array<string, mixed>>, restock_failed: array<int, mixed>, replayed: bool}
     */
    private function handleResponse(ResponseInterface $response): array
    {
        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);
        $data = json_decode($content, true);

        if ($statusCode === 200 || $statusCode === 201) {
            if (!\is_array($data) || (int) ($data['order_slip_id'] ?? 0) <= 0) {
                throw RefundException::psUnavailable('Invalid JSON response from PS module');
            }

            return [
                'order_slip_id' => (int) $data['order_slip_id'],
                'refunded_amount' => (float) ($data['refunded_amount'] ?? 0),
                'refunded_lines' => \is_array($data['refunded_lines'] ?? null) ? $data['refunded_lines'] : [],
                'restock_failed' => \is_array($data['restock_failed'] ?? null) ? $data['restock_failed'] : [],
                'replayed' => !empty($data['replayed']),
            ];
        }

        $details = \is_array($data) && isset($data['details']) ? json_encode($data['details']) : $content;

        if ($statusCode === 401) {
            throw RefundException::validationFailed('HMAC authentication rejected by PS module');
        }

        if ($statusCode === 404) {
            throw new RefundException(sprintf('Order not found in PrestaShop: %s', $details), 404);
        }

        if ($statusCode === 409) {
            throw RefundException::conflict(sprintf('Another refund for this order is in progress in PrestaShop: %s', $details));
        }

        if ($statusCode === 422) {
            throw RefundException::validationFailed(sprintf('PS validation failed: %s', $details));
        }

        if ($statusCode >= 500) {
            throw RefundException::psUnavailable(sprintf('PS module returned HTTP %d: %s', $statusCode, $content));
        }

        throw RefundException::psUnavailable(sprintf('PS module returned unexpected HTTP %d: %s', $statusCode, $content));
    }
}
