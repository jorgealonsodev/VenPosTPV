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
     * @return array{order_slip_id: int, refunded_amount: float, refunded_lines: array<int, array<string, mixed>>}
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
     * @return array{order_slip_id: int, refunded_amount: float, refunded_lines: array<int, array<string, mixed>>}
     */
    private function handleResponse(ResponseInterface $response): array
    {
        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        if ($statusCode === 201) {
            $data = json_decode($content, true);
            if (!\is_array($data)) {
                throw RefundException::psUnavailable('Invalid JSON response from PS module');
            }

            return [
                'order_slip_id' => (int) ($data['order_slip_id'] ?? 0),
                'refunded_amount' => (float) ($data['refunded_amount'] ?? 0),
                'refunded_lines' => \is_array($data['refunded_lines'] ?? null) ? $data['refunded_lines'] : [],
            ];
        }

        if ($statusCode === 401) {
            throw RefundException::validationFailed('HMAC authentication rejected by PS module');
        }

        if ($statusCode === 422) {
            $data = json_decode($content, true);
            $details = \is_array($data) && isset($data['details']) ? json_encode($data['details']) : $content;

            throw RefundException::validationFailed(sprintf('PS validation failed: %s', $details));
        }

        if ($statusCode >= 500) {
            throw RefundException::psUnavailable(sprintf('PS module returned HTTP %d: %s', $statusCode, $content));
        }

        throw RefundException::psUnavailable(sprintf('PS module returned unexpected HTTP %d: %s', $statusCode, $content));
    }
}
