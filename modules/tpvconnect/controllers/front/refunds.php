<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'tpvconnect/classes/SsoToken.php';

/**
 * Front controller for refund creation endpoint.
 *
 * POST /module/tpvconnect/refunds
 */
class TpvconnectRefundsModuleFrontController extends ModuleFrontController
{
    private const RATE_LIMIT_MAX = 60;
    private const RATE_LIMIT_WINDOW = 60;

    public function initContent(): void
    {
        // Only accept POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['error' => 'Method not allowed'], 405);
        }

        // Rate limiting
        $clientIp = $this->getClientIp();
        if (!Tpvconnect::checkRateLimit($clientIp)) {
            $this->jsonResponse(['error' => 'Rate limit exceeded'], 429);
        }

        // HMAC authentication
        $rawBody = (string) file_get_contents('php://input');
        $secret = (string) Configuration::get('TPVCONNECT_SSO_SECRET');

        if (!$this->verifyHmac($rawBody, $secret)) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
        }

        // Parse and validate JSON body
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            $this->jsonResponse(['error' => 'Validation failed', 'details' => ['body' => 'Invalid JSON']], 422);
        }

        $errors = $this->validateInput($data);
        if (!empty($errors)) {
            $this->jsonResponse(['error' => 'Validation failed', 'details' => $errors], 422);
        }

        // Create refund
        try {
            $result = $this->createRefund($data);
            $this->jsonResponse($result, 201);
        } catch (Exception $e) {
            $this->jsonResponse(['error' => 'Refund creation failed', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * Validate incoming refund data.
     */
    private function validateInput(array $data): array
    {
        $errors = [];

        if (empty($data['ps_order_id']) || !is_int($data['ps_order_id'])) {
            $errors['ps_order_id'] = 'Required integer';
        } else {
            $order = new Order((int) $data['ps_order_id']);
            if (!Validate::isLoadedObject($order)) {
                $errors['ps_order_id'] = 'Order not found';
            }
        }

        if (empty($data['refund_type']) || !is_string($data['refund_type'])) {
            $errors['refund_type'] = 'Required string (full or partial)';
        } elseif (!in_array($data['refund_type'], ['full', 'partial'], true)) {
            $errors['refund_type'] = 'Must be "full" or "partial"';
        }

        if (empty($data['lines']) || !is_array($data['lines'])) {
            $errors['lines'] = 'Required array';
        } else {
            foreach ($data['lines'] as $index => $line) {
                if (empty($line['id_product']) || !is_int($line['id_product'])) {
                    $errors['lines.' . $index . '.id_product'] = 'Required integer';
                }
                $qty = isset($line['quantity']) ? (int) $line['quantity'] : 0;
                if ($qty < 1) {
                    $errors['lines.' . $index . '.quantity'] = 'Must be >= 1';
                }
            }
        }

        return $errors;
    }

    /**
     * Create a PrestaShop OrderSlip from the incoming data.
     */
    private function createRefund(array $data): array
    {
        $psOrderId = (int) $data['ps_order_id'];
        $order = new Order($psOrderId);

        if (!Validate::isLoadedObject($order)) {
            throw new RuntimeException('Order not found');
        }

        $lines = $data['lines'];
        $refundType = (string) $data['refund_type'];
        $reason = isset($data['reason']) ? (string) $data['reason'] : '';

        // Build products and quantities for OrderSlip::create
        $products = [];
        $quantities = [];
        $refundedLines = [];
        $refundedAmount = 0.0;

        foreach ($lines as $line) {
            $idProduct = (int) $line['id_product'];
            $idProductAttribute = isset($line['id_product_attribute']) ? (int) $line['id_product_attribute'] : 0;
            $quantity = (int) $line['quantity'];

            // Find the order detail for this product
            $orderDetails = $order->getProducts();
            $found = false;
            foreach ($orderDetails as $detail) {
                if ((int) $detail['product_id'] === $idProduct && (int) $detail['product_attribute_id'] === $idProductAttribute) {
                    $products[] = $detail;
                    $quantities[] = $quantity;
                    $found = true;

                    $unitPrice = (float) $detail['unit_price_tax_incl'];
                    $lineTotal = round($unitPrice * $quantity, 2);
                    $refundedAmount += $lineTotal;

                    $refundedLines[] = [
                        'id_order_detail' => (int) $detail['id_order_detail'],
                        'id_product' => $idProduct,
                        'id_product_attribute' => $idProductAttribute,
                        'quantity' => $quantity,
                        'amount' => $lineTotal,
                    ];

                    break;
                }
            }

            if (!$found) {
                throw new RuntimeException(sprintf('Product %d not found in order', $idProduct));
            }
        }

        // Create OrderSlip
        $shippingCost = 0.0; // No shipping refund by default
        $orderSlip = OrderSlip::create($order, $products, $quantities, $shippingCost);

        if (!$orderSlip || !Validate::isLoadedObject($orderSlip)) {
            throw new RuntimeException('Failed to create order slip');
        }

        // Update order state to refund
        $idOrderState = (int) Configuration::get('TPV_OS_REFUND');
        if ($idOrderState > 0) {
            $history = new OrderHistory();
            $history->id_order = $order->id;
            $history->changeIdOrderState($idOrderState, $order->id);
            $history->addWithemail();
        }

        return [
            'order_slip_id' => (int) $orderSlip->id,
            'refunded_amount' => round($refundedAmount, 2),
            'refunded_lines' => $refundedLines,
        ];
    }

    /**
     * Verify HMAC-SHA256 signature from Authorization header.
     */
    private function verifyHmac(string $rawBody, string $secret): bool
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? getallheaders()['Authorization']
            ?? '';

        if (strpos($authHeader, 'HMAC-SHA256 ') !== 0) {
            return false;
        }

        $providedSignature = substr($authHeader, strlen('HMAC-SHA256 '));
        $expectedSignature = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expectedSignature, $providedSignature);
    }

    /**
     * Get client IP address.
     */
    private function getClientIp(): string
    {
        $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);

                return trim($ips[0]);
            }
        }

        return '0.0.0.0';
    }

    /**
     * Send JSON response and exit.
     */
    private function jsonResponse(array $data, int $httpCode): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
