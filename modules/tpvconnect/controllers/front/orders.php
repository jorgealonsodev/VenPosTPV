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
require_once _PS_MODULE_DIR_ . 'tpvconnect/classes/CouponCheckout.php';
require_once _PS_MODULE_DIR_ . 'tpvconnect/classes/ReferencedOrderCreator.php';
require_once _PS_MODULE_DIR_ . 'tpvconnect/classes/FreeItemOrderLines.php';
require_once _PS_MODULE_DIR_ . 'tpvconnect/classes/LeftoverVoucher.php';

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

        $errors = array_merge(
            $this->validateInput($data),
            CouponCheckout::validateCouponRequest($data),
            ReferencedOrderCreator::validateRequest($data),
            FreeItemOrderLines::validateRequest($data, FreeItemCatalog::load())
        );
        if (!empty($errors)) {
            $this->jsonResponse(['error' => 'Validation failed', 'details' => $errors], 422);
        }

        try {
            if (($data['operation'] ?? 'order') === 'coupon_preview') {
                $checkout = new CouponCheckout($secret, $this->context);
                $this->jsonResponse($checkout->preview($data), 200);
            }

            // An optional TPV reference is the order's reference and its
            // idempotency key: a retry replays the existing order (200).
            $creator = new ReferencedOrderCreator(new PsReferencedOrderGateway());
            $result = $creator->create($data, fn (): array => $this->createOrder($data, $secret));
            if (array_key_exists('coupon', $data)) {
                // The rest of a voucher worth more than the sale, made usable by
                // anyone; the same one again on a replayed request.
                $result = (new LeftoverVoucher(new PsLeftoverVoucherGateway()))->attachTo($result);
            }
            $this->jsonResponse($result, ($result['replayed'] ?? false) === true ? 200 : 201);
        } catch (ReferencedOrderException $e) {
            $this->jsonResponse(['error' => $e->getMessage(), 'details' => $e->getDetails()], $e->getHttpCode());
        } catch (CouponCheckoutException $e) {
            $this->jsonResponse(['error' => 'Coupon checkout failed', 'details' => $e->getMessage()], $e->getHttpStatus());
        } catch (TpvOrderBuildException $e) {
            $this->jsonResponse(['error' => 'Order validation failed', 'details' => $e->getMessage()], $e->getHttpStatus());
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
    private function createOrder(array $data, string $secret): array
    {
        $checkout = new CouponCheckout($secret, $this->context);
        if (array_key_exists('coupon', $data)) {
            return $checkout->redeem($data, fn (Cart $cart, float $totalPaid): array => $this->validateOrderFromCart($cart, $data, $totalPaid));
        }

        // Retain the existing non-coupon path, sharing only cart construction.
        // Free item lines (articulo-libre.md, A5.4) get a per-cart price before they
        // are added and their concept as line name after validateOrder.
        $freeLines = new FreeItemOrderLines(new PsFreeItemOrderGateway(), FreeItemCatalog::load());
        TpvFreeItemNames::hold(FreeItemOrderLines::concepts($data['products']));
        try {
            $cart = $checkout->buildCart($data, $freeLines);
            $result = $this->validateOrderFromCart($cart, $data, (float) $cart->getOrderTotal(true, Cart::BOTH));
            $freeLines->nameOrderLines((int) $result['order_id'], $data['products']);

            return $result;
        } finally {
            $freeLines->releaseCart();
            TpvFreeItemNames::release();
        }
    }

    private function validateOrderFromCart(Cart $cart, array $data, float $totalPaid): array
    {
        $idCurrency = (int) $data['id_currency'];
        $idOrderState = (int) Configuration::get('TPV_OS_PAID');
        if ($idOrderState <= 0) {
            throw new RuntimeException('TPV order state not configured');
        }

        $extraVars = [];
        if (isset($data['transaction_id'])) {
            $extraVars['transaction_id'] = (string) $data['transaction_id'];
        }
        $this->module->validateOrder(
            (int) $cart->id,
            $idOrderState,
            $totalPaid,
            (string) $data['payment_method'],
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
            'total_paid' => round($totalPaid, 2),
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
