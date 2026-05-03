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
 * Front controller for order creation endpoint.
 *
 * POST /module/tpvconnect/orders
 */
class TpvConnectOrdersModuleFrontController extends ModuleFrontController
{
    private const RATE_LIMIT_MAX = 60;
    private const RATE_LIMIT_WINDOW = 60;

    public function init(): void
    {
        parent::init();
        $this->ajax = true;
    }

    public function postProcess(): void
    {
        // Only accept POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['error' => 'Method not allowed'], 405);
            return;
        }

        // Rate limiting
        $clientIp = $this->getClientIp();
        if (!Tpvconnect::checkRateLimit($clientIp)) {
            $this->jsonResponse(['error' => 'Rate limit exceeded'], 429);
            return;
        }

        // HMAC authentication
        $rawBody = file_get_contents('php://input');
        $secret = (string) Configuration::get('TPVCONNECT_SSO_SECRET');

        if (!$this->verifyHmac($rawBody, $secret)) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Parse and validate JSON body
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            $this->jsonResponse(['error' => 'Validation failed', 'details' => ['body' => 'Invalid JSON']], 422);
            return;
        }

        $errors = $this->validateInput($data);
        if (!empty($errors)) {
            $this->jsonResponse(['error' => 'Validation failed', 'details' => $errors], 422);
            return;
        }

        // Create order
        try {
            $result = $this->createOrder($data);
            $this->jsonResponse($result, 201);
        } catch (Exception $e) {
            $this->jsonResponse(['error' => 'Order creation failed', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * Validate incoming order data.
     */
    private function validateInput(array $data): array
    {
        $errors = [];

        if (empty($data['id_customer']) || !is_int($data['id_customer'])) {
            $errors['id_customer'] = 'Required integer';
        } else {
            $customer = new Customer((int) $data['id_customer']);
            if (!Validate::isLoadedObject($customer)) {
                $errors['id_customer'] = 'Customer not found';
            }
        }

        if (empty($data['id_currency']) || !is_int($data['id_currency'])) {
            $errors['id_currency'] = 'Required integer';
        } else {
            $currency = new Currency((int) $data['id_currency']);
            if (!Validate::isLoadedObject($currency)) {
                $errors['id_currency'] = 'Currency not found';
            }
        }

        if (empty($data['id_shop']) || !is_int($data['id_shop'])) {
            $errors['id_shop'] = 'Required integer';
        }

        if (empty($data['payment_method']) || !is_string($data['payment_method'])) {
            $errors['payment_method'] = 'Required string';
        }

        if (empty($data['products']) || !is_array($data['products'])) {
            $errors['products'] = 'Required array';
        } else {
            foreach ($data['products'] as $index => $product) {
                if (empty($product['id_product']) || !is_int($product['id_product'])) {
                    $errors['products.' . $index . '.id_product'] = 'Required integer';
                } else {
                    $p = new Product((int) $product['id_product']);
                    if (!Validate::isLoadedObject($p)) {
                        $errors['products.' . $index . '.id_product'] = 'Product not found';
                    }
                }

                $qty = isset($product['quantity']) ? (int) $product['quantity'] : 0;
                if ($qty < 1) {
                    $errors['products.' . $index . '.quantity'] = 'Must be >= 1';
                }
            }
        }

        return $errors;
    }

    /**
     * Create a PrestaShop order from the incoming data.
     */
    private function createOrder(array $data): array
    {
        $idCustomer = (int) $data['id_customer'];
        $idCurrency = (int) $data['id_currency'];
        $idShop = (int) $data['id_shop'];
        $paymentMethod = (string) $data['payment_method'];
        $transactionId = isset($data['transaction_id']) ? (string) $data['transaction_id'] : null;

        // Create cart
        $cart = new Cart();
        $cart->id_customer = $idCustomer;
        $cart->id_currency = $idCurrency;
        $cart->id_shop = $idShop;
        $cart->id_lang = (int) $this->context->language->id;

        // Set delivery address (use customer's default or first address)
        $addresses = Address::getAddresses($idCustomer);
        if (!empty($addresses)) {
            $firstAddress = reset($addresses);
            $cart->id_address_delivery = (int) $firstAddress['id_address'];
            $cart->id_address_invoice = (int) $firstAddress['id_address'];
        }

        $cart->save();

        // Add products
        foreach ($data['products'] as $product) {
            $idProduct = (int) $product['id_product'];
            $idProductAttribute = isset($product['id_product_attribute']) ? (int) $product['id_product_attribute'] : 0;
            $quantity = (int) $product['quantity'];

            $cart->updateQty($quantity, $idProduct, $idProductAttribute);
        }

        // Calculate totals
        $totalPaid = $cart->getOrderTotal(true, Cart::BOTH);

        // Get order state
        $idOrderState = (int) Configuration::get('TPV_OS_PAID');
        if ($idOrderState <= 0) {
            throw new RuntimeException('TPV order state not configured');
        }

        // Prepare extra vars
        $extraVars = [];
        if ($transactionId !== null) {
            $extraVars['transaction_id'] = $transactionId;
        }

        // Validate order using this module as the payment module
        $this->module->validateOrder(
            (int) $cart->id,
            $idOrderState,
            $totalPaid,
            $paymentMethod,
            null,
            $extraVars,
            $idCurrency,
            false,
            $cart->secure_key
        );

        $order = new Order((int) $this->module->currentOrder);

        if (!Validate::isLoadedObject($order)) {
            throw new RuntimeException('Order was not created');
        }

        return [
            'order_id' => (int) $order->id,
            'reference' => $order->reference,
        ];
    }

    /**
     * Verify HMAC-SHA256 signature from Authorization header.
     */
    private function verifyHmac(string $rawBody, string $secret): bool
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

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
