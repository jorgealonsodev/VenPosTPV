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
     */
    public function createOrder(array $payload): array
    {
        return $this->handleOrderResponse($this->send($payload), false);
    }

    /**
     * Uploads a local sale with its ticket code as the order reference (module
     * 0.6.0, ReferencedOrderCreator). The reference is the idempotency key, so a
     * retry of an order PrestaShop already created answers 200 with replayed=true.
     *
     * @param array<string, mixed> $payload the order request, with `reference`
     *
     * @return array{order_id: int, reference: string, total_paid: float, replayed: bool}
     *
     * @throws CheckoutException code 422 when PrestaShop rejects the order for good (404, 422);
     *                           code 0 for anything worth retrying (network, 409 lock busy, 401, 5xx, incomplete answer)
     * @throws \InvalidArgumentException when the payload has no ticket code
     */
    public function createReferencedOrder(array $payload): array
    {
        if (!is_string($payload['reference'] ?? null) || $payload['reference'] === '') {
            throw new \InvalidArgumentException('A referenced order needs the ticket code as reference');
        }

        $response = $this->send($payload);
        try {
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw CheckoutException::psUnavailable($e->getMessage(), $e);
        }

        if ($statusCode === 200 || $statusCode === 201) {
            $data = json_decode($content, true);
            if (!is_array($data) || !is_int($data['order_id'] ?? null) || $data['order_id'] < 1
                || !is_string($data['reference'] ?? null) || $data['reference'] === '') {
                throw CheckoutException::psUnavailable(sprintf('Incomplete order answer from PS module (HTTP %d)', $statusCode));
            }

            return [
                'order_id' => $data['order_id'],
                'reference' => $data['reference'],
                'total_paid' => round((float) ($data['total_paid'] ?? 0), 2),
                'replayed' => ($data['replayed'] ?? false) === true,
            ];
        }

        if ($statusCode === 404 || $statusCode === 422) {
            $data = json_decode($content, true);
            $details = is_array($data) && isset($data['details']) ? json_encode($data['details']) : $content;

            throw CheckoutException::psRejected($statusCode, (string) $details);
        }

        throw CheckoutException::psUnavailable(sprintf('PS module returned HTTP %d: %s', $statusCode, mb_substr($content, 0, 500)));
    }

    /**
     * Asks the module (0.7.0, POST /module/tpvconnect/freeitem) to create or check
     * the hidden generic products of these VAT rates (docs/prd/articulo-libre.md, A5.2).
     *
     * @param list<int|float> $vatRates
     *
     * @return array<string, mixed> the module's answer: {products: {"21.00": {id_product, slots}}}
     *
     * @throws CheckoutException code 422 when the module refuses the rates, 0 for anything else
     */
    public function setupFreeItemProducts(array $vatRates): array
    {
        $response = $this->send(['vat_rates' => $vatRates], 'freeitem');
        try {
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw CheckoutException::psUnavailable($e->getMessage(), $e);
        }
        if ($statusCode === 200) {
            $data = json_decode($content, true);
            if (!is_array($data)) {
                throw CheckoutException::psUnavailable('Invalid JSON response from PS module');
            }

            return $data;
        }
        if ($statusCode === 404 || $statusCode === 422) {
            $data = json_decode($content, true);

            $details = is_array($data) && isset($data['details']) ? json_encode($data['details'], JSON_UNESCAPED_UNICODE) : $content;

            throw new CheckoutException(sprintf('PrestaShop refused the free item setup (HTTP %d): %s', $statusCode, (string) $details), 422);
        }

        throw CheckoutException::psUnavailable(sprintf('PS module returned HTTP %d: %s', $statusCode, mb_substr($content, 0, 500)));
    }

    /**
     * The preview endpoint is deliberately distinct from the ordinary order
     * client. A response from an older module is never treated as a preview.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{operation: string, cart_id: int, before_discount_total: float, discount_amount: float, total_paid: float, coupon: array{code: string, expires_at: string}, quote: string}
     */
    public function previewCoupon(array $payload): array
    {
        // A preview books nothing anywhere, so any failure to reach PrestaShop
        // (network, timeout, a gateway in front of a stopped shop) is coupon_offline.
        try {
            $response = $this->send($payload);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw CheckoutException::couponOffline($e);
        } catch (CheckoutException $e) {
            if ($e->getCode() === 0) {
                throw CheckoutException::couponOffline($e);
            }
            throw $e;
        }

        if ($statusCode !== 200) {
            if (\in_array($statusCode, self::GATEWAY_DOWN, true)) {
                throw CheckoutException::couponOffline();
            }
            if ($statusCode === 422) {
                throw CheckoutException::couponRejected();
            }
            throw CheckoutException::couponUnavailable('Coupon preview is unavailable; deploy the compatible TPV Connect module before enabling coupon checkout');
        }

        $data = json_decode($content, true);
        if (!is_array($data)
            || ($data['operation'] ?? null) !== 'coupon_preview'
            || !is_int($data['cart_id']) || $data['cart_id'] < 1
            || !is_array($data['coupon'] ?? null)
            || !is_string($data['coupon']['code'] ?? null) || trim($data['coupon']['code']) === ''
            || !is_string($data['coupon']['expires_at'] ?? null)
            || !is_string($data['quote'] ?? null) || $data['quote'] === '') {
            throw CheckoutException::couponUnavailable('Invalid coupon preview response from PS module');
        }

        foreach (['before_discount_total', 'discount_amount', 'total_paid'] as $amount) {
            if (!isset($data[$amount]) || !is_numeric($data[$amount]) || !is_finite((float) $data[$amount]) || (float) $data[$amount] < 0) {
                throw CheckoutException::couponUnavailable('Invalid coupon preview response from PS module');
            }
        }

        $result = [
            'operation' => 'coupon_preview',
            'cart_id' => $data['cart_id'],
            'before_discount_total' => round((float) $data['before_discount_total'], 2),
            'discount_amount' => round((float) $data['discount_amount'], 2),
            'total_paid' => round((float) $data['total_paid'], 2),
            'coupon' => [
                'code' => $data['coupon']['code'],
                'expires_at' => $data['coupon']['expires_at'],
            ],
            'quote' => $data['quote'],
        ];

        $quote = $this->verifyCouponQuote($result['quote']);
        $this->assertPreviewMatchesQuote($result, $quote, $payload);

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{order_id: int, reference: string, total_paid: float}
     */
    public function createCouponOrder(array $payload): array
    {
        try {
            $response = $this->send($payload);
        } catch (CheckoutException $e) {
            // The request was never issued (code 0); an encoding error stays 422.
            throw $e->getCode() === 0 ? CheckoutException::couponOffline($e) : $e;
        }
        try {
            $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            if (self::neverReachedPrestaShop($response, $e)) {
                throw CheckoutException::couponOffline($e);
            }
            // It may have reached PrestaShop: the order could exist.
            throw CheckoutException::couponReconciliationRequired($e->getMessage(), $e);
        }

        try {
            return $this->handleOrderResponse($response, true);
        } catch (CheckoutException $e) {
            if ($e->getCode() === 422) {
                throw $e;
            }

            throw CheckoutException::couponReconciliationRequired($e->getMessage(), $e);
        }
    }

    /** HTTP answers of a proxy or web server in front of a PrestaShop that does not answer. */
    private const GATEWAY_DOWN = [502, 503, 504];

    /**
     * True only when the connection to PrestaShop was never established (DNS
     * failure, refused, unreachable, connect timeout), so the request cannot have
     * created anything. A timeout after connecting is not: the order may exist.
     */
    private static function neverReachedPrestaShop(ResponseInterface $response, TransportExceptionInterface $e): bool
    {
        $connectTime = $response->getInfo('connect_time');
        if (is_numeric($connectTime) && (float) $connectTime > 0) {
            return false;
        }

        return preg_match('/could not resolve|couldn\'t resolve|failed to connect|couldn\'t connect|connection refused|network is unreachable|no route to host|connection timed out/i', $e->getMessage()) === 1;
    }

    /**
     * Verify the module-signed, short-lived binding before local tender effects.
     *
     * @return array<string, mixed>
     */
    public function verifyCouponQuote(mixed $token): array
    {
        if (!is_string($token) || substr_count($token, '.') !== 1) {
            throw CheckoutException::psValidationFailed('Coupon quote is invalid');
        }
        [$encoded, $signature] = explode('.', $token, 2);
        $expected = hash_hmac('sha256', $encoded, $this->sharedSecret);
        if (!hash_equals($expected, $signature)) {
            throw CheckoutException::psValidationFailed('Coupon quote is invalid');
        }

        $base64 = strtr($encoded, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $json = base64_decode($base64, true);
        $quote = $json === false ? null : json_decode($json, true);
        $required = ['cart_id', 'customer_id', 'shop_id', 'currency_id', 'coupon_id', 'coupon_code', 'coupon_expires_at', 'products', 'before_discount_total', 'discount_amount', 'total_paid', 'expires_at'];
        if (!is_array($quote) || array_diff($required, array_keys($quote))
            || !is_int($quote['cart_id']) || $quote['cart_id'] < 1
            || !is_int($quote['customer_id']) || $quote['customer_id'] < 1
            || !is_int($quote['shop_id']) || $quote['shop_id'] < 1
            || !is_int($quote['currency_id']) || $quote['currency_id'] < 1
            || !is_int($quote['expires_at']) || $quote['expires_at'] < time()
            || !is_string($quote['coupon_code']) || $quote['coupon_code'] === ''
            || !is_array($quote['products'])) {
            throw CheckoutException::psValidationFailed('Coupon quote is invalid or expired');
        }
        foreach (['before_discount_total', 'discount_amount', 'total_paid'] as $amount) {
            if (!isset($quote[$amount]) || !is_numeric($quote[$amount]) || !is_finite((float) $quote[$amount]) || (float) $quote[$amount] < 0) {
                throw CheckoutException::psValidationFailed('Coupon quote is invalid');
            }
        }

        return $quote;
    }

    /** @param array<string, mixed> $payload */
    private function send(array $payload, string $endpoint = 'orders'): ResponseInterface
    {
        $jsonBody = json_encode($payload);
        if ($jsonBody === false) {
            throw CheckoutException::psValidationFailed('Failed to JSON-encode payload');
        }

        $signature = hash_hmac('sha256', $jsonBody, $this->sharedSecret);
        $url = sprintf('%s/module/tpvconnect/%s', $this->shopUrl, $endpoint);

        try {
            return $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => sprintf('HMAC-SHA256 %s', $signature),
                ],
                'body' => $jsonBody,
                'timeout' => 30,
                'max_duration' => 30,
            ]);
        } catch (TransportExceptionInterface $e) {
            throw CheckoutException::psUnavailable($e->getMessage(), $e);
        } catch (\Throwable $e) {
            throw CheckoutException::psUnavailable($e->getMessage(), $e);
        }
    }

    /** @return array{order_id: int, reference: string, total_paid: float} */
    /**
     * TPV Connect 0.7.1: the rest of a voucher worth more than the sale, which
     * PrestaShop kept as a new cart rule anyone can use. Null when the module sent
     * none or an incomplete one (an older module sends none).
     *
     * @return array{id: int, code: string, amount: float, expires_at: string}|null
     */
    private static function leftoverVoucher(mixed $leftover): ?array
    {
        if (!is_array($leftover) || !is_int($leftover['id'] ?? null) || $leftover['id'] < 1
            || !is_string($leftover['code'] ?? null) || trim($leftover['code']) === ''
            || !is_numeric($leftover['amount'] ?? null) || (float) $leftover['amount'] <= 0 || !is_finite((float) $leftover['amount'])
            || !is_string($leftover['expires_at'] ?? null) || strtotime($leftover['expires_at']) === false) {
            return null;
        }

        return [
            'id' => $leftover['id'],
            'code' => trim($leftover['code']),
            'amount' => round((float) $leftover['amount'], 2),
            'expires_at' => $leftover['expires_at'],
        ];
    }

    private function handleOrderResponse(ResponseInterface $response, bool $coupon): array
    {
        try {
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw CheckoutException::psUnavailable($e->getMessage(), $e);
        }

        if ($statusCode === 201) {
            $data = json_decode($content, true);
            if (!is_array($data)) {
                throw CheckoutException::psUnavailable('Invalid JSON response from PS module');
            }
            if ($coupon && (!isset($data['order_id']) || !is_int($data['order_id']) || $data['order_id'] < 1
                || !is_string($data['reference'] ?? null) || $data['reference'] === ''
                || !isset($data['total_paid']) || !is_numeric($data['total_paid']) || !is_finite((float) $data['total_paid']))) {
                throw CheckoutException::couponReconciliationRequired('Coupon order response is incomplete; reconcile before charging again');
            }

            $leftover = $coupon ? self::leftoverVoucher($data['leftover_voucher'] ?? null) : null;

            return [
                'order_id' => (int) ($data['order_id'] ?? 0),
                'reference' => (string) ($data['reference'] ?? ''),
                'total_paid' => (float) ($data['total_paid'] ?? 0),
            ] + ($leftover !== null ? ['leftover_voucher' => $leftover] : []);
        }

        if ($statusCode === 401) {
            throw CheckoutException::psValidationFailed('HMAC authentication rejected by PS module');
        }

        if ($statusCode === 422) {
            $data = json_decode($content, true);
            $details = is_array($data) && isset($data['details']) ? json_encode($data['details']) : $content;
            throw CheckoutException::psValidationFailed(sprintf('PS validation failed: %s', $details));
        }

        if ($statusCode >= 500) {
            throw CheckoutException::psUnavailable(sprintf('PS module returned HTTP %d: %s', $statusCode, $content));
        }

        throw CheckoutException::psUnavailable(sprintf('PS module returned unexpected HTTP %d: %s', $statusCode, $content));
    }

    /**
     * @param array{operation: string, cart_id: int, before_discount_total: float, discount_amount: float, total_paid: float, coupon: array{code: string, expires_at: string}, quote: string} $preview
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $payload
     */
    private function assertPreviewMatchesQuote(array $preview, array $quote, array $payload): void
    {
        $requestedCustomerId = (int) ($payload['id_customer'] ?? 0);
        $quotedCustomerId = (int) $quote['customer_id'];
        // PrestaShop resolves anonymous customer 0 to its configured native
        // anonymous customer before signing the quote. A named customer must
        // still match exactly; anonymous requests only accept a positive, HMAC
        // verified native resolution.
        $customerMatches = $requestedCustomerId > 0
            ? $quotedCustomerId === $requestedCustomerId
            : $requestedCustomerId === 0 && $quotedCustomerId > 0;
        if ((int) $quote['cart_id'] !== $preview['cart_id']
            || !$customerMatches
            || !is_string($payload['coupon_code'] ?? null)
            || !hash_equals((string) $quote['coupon_code'], $payload['coupon_code'])
            || (int) $quote['shop_id'] !== (int) ($payload['id_shop'] ?? 0)
            || (int) $quote['currency_id'] !== (int) ($payload['id_currency'] ?? 0)
            || !hash_equals((string) $quote['coupon_code'], $preview['coupon']['code'])
            || !$this->sameMoney((float) $quote['before_discount_total'], $preview['before_discount_total'])
            || !$this->sameMoney((float) $quote['discount_amount'], $preview['discount_amount'])
            || !$this->sameMoney((float) $quote['total_paid'], $preview['total_paid'])
            || $this->productFingerprint($quote['products']) !== $this->productFingerprint($payload['products'] ?? [])) {
            throw CheckoutException::couponUnavailable('Coupon preview quote does not match its response');
        }
    }

    /** @param array<int, mixed> $products */
    private function productFingerprint(array $products): array
    {
        $fingerprint = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                return [];
            }
            $fingerprint[] = [
                'id_product' => (int) ($product['id_product'] ?? 0),
                'id_product_attribute' => (int) ($product['id_product_attribute'] ?? 0),
                'quantity' => (int) ($product['quantity'] ?? 0),
            ];
        }
        usort($fingerprint, static fn (array $left, array $right): int => $left <=> $right);

        return $fingerprint;
    }

    private function sameMoney(float $left, float $right): bool
    {
        return abs(round($left, 2) - round($right, 2)) < 0.00001;
    }
}
