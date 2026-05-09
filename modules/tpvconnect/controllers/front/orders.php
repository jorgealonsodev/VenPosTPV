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
class TpvconnectOrdersModuleFrontController extends ModuleFrontController
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
    private function validateInput(array &$data): array
    {
        $errors = [];

        // Resolve anonymous customer
        $idCustomer = isset($data['id_customer']) ? (int) $data['id_customer'] : 0;
        if ($idCustomer === 0) {
            $anonId = (int) Configuration::get('TPVCONNECT_ANONYMOUS_CUSTOMER_ID');
            if ($anonId <= 0) {
                $errors['id_customer'] = 'Anonymous customer not configured';
            } else {
                $customer = new Customer($anonId);
                if (!Validate::isLoadedObject($customer)) {
                    $errors['id_customer'] = 'Anonymous customer not found';
                }
                // Mutate data so createOrder sees the resolved ID
                $data['id_customer'] = $anonId;
            }
        } else {
            $customer = new Customer($idCustomer);
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

        $customer = new Customer($idCustomer);

        // Create cart
        $cart = new Cart();
        $cart->id_customer = $idCustomer;
        $cart->id_currency = $idCurrency;
        $cart->id_shop = $idShop;
        $cart->id_lang = (int) $this->context->language->id;
        $cart->secure_key = $customer->secure_key;

        // Set delivery address (use customer's default or first address)
        $addresses = $customer->getAddresses((int) $this->context->language->id);
        if (!empty($addresses)) {
            $firstAddress = reset($addresses);
            $cart->id_address_delivery = (int) $firstAddress['id_address'];
            $cart->id_address_invoice = (int) $firstAddress['id_address'];
        } else {
            // Anonymous customer has no address — create a temporary one
            $address = new Address();
            $address->id_customer = $idCustomer;
            $address->firstname = 'TPV';
            $address->lastname = 'Cliente';
            $address->address1 = 'TPV Local';
            $address->city = 'TPV';
            $address->id_country = (int) Configuration::get('PS_COUNTRY_DEFAULT');
            $address->alias = 'tpv_temp_' . time();
            $address->add();
            $cart->id_address_delivery = (int) $address->id;
            $cart->id_address_invoice = (int) $address->id;
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
