<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Checkout;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;

class PsOrderClient
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
     * @return array{order_id: int, reference: string, total_paid: float}
     *
     * @throws CheckoutException on network/validation/PS errors
     */
    public function createOrder(array $payload): array
    {
        $jsonBody = json_encode($payload);
        if ($jsonBody === false) {
            throw CheckoutException::psValidationFailed('Failed to JSON-encode payload');
        }

        $signature = hash_hmac('sha256', $jsonBody, $this->sharedSecret);
        $url = sprintf('%s/module/tpvconnect/orders', $this->shopUrl);

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
        } catch (CheckoutException $e) {
            throw $e;
        } catch (TransportExceptionInterface $e) {
            throw CheckoutException::psUnavailable($e->getMessage(), $e);
        } catch (\Throwable $e) {
            throw CheckoutException::psUnavailable($e->getMessage(), $e);
        }
    }

    /**
     * @return array{order_id: int, reference: string, total_paid: float}
     */
    private function handleResponse(ResponseInterface $response): array
    {
        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        if ($statusCode === 201) {
            $data = json_decode($content, true);
            if (!\is_array($data)) {
                throw CheckoutException::psUnavailable('Invalid JSON response from PS module');
            }

            return [
                'order_id' => (int) ($data['order_id'] ?? 0),
                'reference' => (string) ($data['reference'] ?? ''),
                'total_paid' => (float) ($data['total_paid'] ?? 0),
            ];
        }

        if ($statusCode === 401) {
            throw CheckoutException::psValidationFailed('HMAC authentication rejected by PS module');
        }

        if ($statusCode === 422) {
            $data = json_decode($content, true);
            $details = \is_array($data) && isset($data['details']) ? json_encode($data['details']) : $content;

            throw CheckoutException::psValidationFailed(sprintf('PS validation failed: %s', $details));
        }

        if ($statusCode >= 500) {
            throw CheckoutException::psUnavailable(sprintf('PS module returned HTTP %d: %s', $statusCode, $content));
        }

        throw CheckoutException::psUnavailable(sprintf('PS module returned unexpected HTTP %d: %s', $statusCode, $content));
    }
}
