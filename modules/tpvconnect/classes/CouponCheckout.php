<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/** A client-safe failure while preparing or redeeming a native cart rule. */
class CouponCheckoutException extends RuntimeException
{
    public function __construct(string $message, private int $httpStatus = 422)
    {
        parent::__construct($message);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}

/** Shared cart construction for ordinary orders and future coupon previews. */
class CouponCheckout
{
    private const QUOTE_TTL_SECONDS = 300;

    public function __construct(private string $secret, private ?Context $context = null)
    {
        $this->context ??= Context::getContext();
    }

    /**
     * Validate coupon-specific endpoint fields. General order fields remain the
     * controller's existing responsibility so non-coupon requests are unchanged.
     */
    public static function validateCouponRequest(array $data): array
    {
        $errors = [];
        $operation = $data['operation'] ?? 'order';
        if (!is_string($operation) || !in_array($operation, ['order', 'coupon_preview'], true)) {
            return ['operation' => 'Unknown operation'];
        }

        if ($operation === 'coupon_preview') {
            if (!isset($data['coupon_code']) || !is_string($data['coupon_code']) || trim($data['coupon_code']) === '') {
                $errors['coupon_code'] = 'Required non-empty string';
            }
            return $errors;
        }

        if (!array_key_exists('coupon', $data)) {
            return $errors;
        }
        if (!is_array($data['coupon'])) {
            return ['coupon' => 'Must be an object'];
        }

        $coupon = $data['coupon'];
        if (!isset($coupon['code']) || !is_string($coupon['code']) || trim($coupon['code']) === '') {
            $errors['coupon.code'] = 'Required non-empty string';
        }
        if (!isset($coupon['preview_cart_id']) || !is_int($coupon['preview_cart_id']) || $coupon['preview_cart_id'] < 1) {
            $errors['coupon.preview_cart_id'] = 'Required positive integer';
        }
        if (!isset($coupon['quote']) || !is_string($coupon['quote']) || $coupon['quote'] === '') {
            $errors['coupon.quote'] = 'Required non-empty string';
        }
        if (!isset($coupon['expected_total_paid']) || !is_int($coupon['expected_total_paid']) && !is_float($coupon['expected_total_paid'])
            || !is_finite((float) ($coupon['expected_total_paid'] ?? NAN))) {
            $errors['coupon.expected_total_paid'] = 'Required finite number';
        }

        return $errors;
    }

    /** Create a quote-only cart. Cart-rule use is not consumed until validateOrder. */
    public function preview(array $data): array
    {
        $code = isset($data['coupon_code']) && is_string($data['coupon_code']) ? trim($data['coupon_code']) : '';
        if ($code === '') {
            throw new CouponCheckoutException('Coupon code is invalid');
        }

        $cart = $this->buildCart($data);
        $beforeDiscountTotal = $this->grossOrderTotal($cart);
        $rule = $this->validateNativeRule($cart, $code);
        if (!$cart->addCartRule((int) $rule->id)) {
            throw new CouponCheckoutException('Coupon could not be applied');
        }

        $totalPaid = $this->payableOrderTotal($cart);
        $discountAmount = $this->money(max(0, $beforeDiscountTotal - $totalPaid));
        $expiresAt = $this->ruleExpiry($rule);
        $quote = [
            'cart_id' => (int) $cart->id,
            'customer_id' => (int) $cart->id_customer,
            'shop_id' => (int) $cart->id_shop,
            'currency_id' => (int) $cart->id_currency,
            'coupon_id' => (int) $rule->id,
            'coupon_code' => $code,
            'coupon_expires_at' => $expiresAt,
            'products' => $this->requestProductFingerprint($data['products']),
            'cart_rules' => $this->cartRuleFingerprint($cart),
            'before_discount_total' => $beforeDiscountTotal,
            'discount_amount' => $discountAmount,
            'total_paid' => $totalPaid,
            'expires_at' => time() + self::QUOTE_TTL_SECONDS,
        ];

        return [
            'operation' => 'coupon_preview',
            'cart_id' => (int) $cart->id,
            'before_discount_total' => $beforeDiscountTotal,
            'discount_amount' => $discountAmount,
            'total_paid' => $totalPaid,
            'coupon' => ['code' => $code, 'expires_at' => $expiresAt],
            'quote' => self::signQuote($quote, $this->secret),
        ];
    }

    /** Public so isolated tests can make an expired or forged binding. */
    public static function signQuote(array $quote, string $secret): string
    {
        $encoded = rtrim(strtr(base64_encode((string) json_encode($quote, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        return $encoded . '.' . hash_hmac('sha256', $encoded, $secret);
    }

    /** Shared cart construction for ordinary orders and coupon previews. */
    public function buildCart(array $data): Cart
    {
        $idCustomer = (int) $data['id_customer'];
        $customer = new Customer($idCustomer);
        $cart = new Cart();
        $cart->id_customer = $idCustomer;
        $cart->id_currency = (int) $data['id_currency'];
        $cart->id_shop = (int) $data['id_shop'];
        $cart->id_lang = (int) $this->context->language->id;
        $cart->secure_key = $customer->secure_key;

        $addresses = $customer->getAddresses((int) $this->context->language->id);
        if (!empty($addresses)) {
            $firstAddress = reset($addresses);
            $cart->id_address_delivery = (int) $firstAddress['id_address'];
            $cart->id_address_invoice = (int) $firstAddress['id_address'];
        } else {
            $address = new Address();
            $address->id_customer = $idCustomer;
            $address->firstname = 'TPV';
            $address->lastname = 'Cliente';
            $address->address1 = 'TPV Local';
            $address->city = 'TPV';
            $address->id_country = (int) Configuration::get('PS_COUNTRY_DEFAULT');
            $address->alias = 'tpv_temp_' . time();
            if (!$address->add()) {
                throw new RuntimeException('Temporary address could not be created');
            }
            $cart->id_address_delivery = (int) $address->id;
            $cart->id_address_invoice = (int) $address->id;
        }
        if (!$cart->save()) {
            throw new RuntimeException('Cart could not be created');
        }

        foreach ($data['products'] as $product) {
            if (!$cart->updateQty((int) $product['quantity'], (int) $product['id_product'], (int) ($product['id_product_attribute'] ?? 0))) {
                throw new RuntimeException('Product could not be added to cart');
            }
        }
        return $cart;
    }

    private function validateNativeRule(Cart $cart, string $code): CartRule
    {
        $this->setContext($cart, new Customer((int) $cart->id_customer), new Currency((int) $cart->id_currency));
        $idRule = (int) CartRule::getIdByCode($code, (int) $this->context->language->id);
        $rule = new CartRule($idRule);
        if ($idRule < 1 || !Validate::isLoadedObject($rule)) {
            throw new CouponCheckoutException('Coupon is invalid');
        }
        // PS 8.1 returns null (not true) for a valid check with display_error.
        if ($rule->checkValidity($this->context, false, true) !== null) {
            throw new CouponCheckoutException('Coupon is no longer eligible');
        }
        return $rule;
    }

    private function setContext(Cart $cart, Customer $customer, Currency $currency): void
    {
        $this->context->cart = $cart;
        $this->context->customer = $customer;
        $this->context->currency = $currency;
        $this->context->shop = new Shop((int) $cart->id_shop);
    }

    private function requestProductFingerprint(array $products): array
    {
        return $this->productFingerprint($products, 'quantity');
    }

    private function productFingerprint(array $products, string $quantityField): array
    {
        $fingerprint = [];
        foreach ($products as $product) {
            $fingerprint[] = [
                'id_product' => (int) ($product['id_product'] ?? 0),
                'id_product_attribute' => (int) ($product['id_product_attribute'] ?? 0),
                'quantity' => (int) ($product[$quantityField] ?? 0),
            ];
        }
        usort($fingerprint, static fn (array $left, array $right): int => $left <=> $right);
        return $fingerprint;
    }

    private function grossOrderTotal(Cart $cart): float
    {
        // This does not remove or mutate the attached CartRule. ONLY_PRODUCTS
        // independently measures the native pre-cart-rule price basis.
        return $this->money($cart->getOrderTotal(true, Cart::ONLY_PRODUCTS, null, null, false));
    }

    private function payableOrderTotal(Cart $cart): float
    {
        return $this->money($cart->getOrderTotal(true, Cart::BOTH, null, null, false));
    }

    private function cartRuleFingerprint(Cart $cart): array
    {
        $fingerprint = [];
        foreach ($cart->getCartRules() as $cartRule) {
            if (!isset($cartRule['id_cart_rule'], $cartRule['value_real'])) {
                throw new CouponCheckoutException('Coupon attachment changed; request a new preview');
            }
            $fingerprint[] = [
                'id_cart_rule' => (int) $cartRule['id_cart_rule'],
                'value_real' => $this->money((float) $cartRule['value_real']),
            ];
        }
        usort($fingerprint, static fn (array $left, array $right): int => $left <=> $right);
        return $fingerprint;
    }

    private function ruleExpiry(CartRule $rule): string
    {
        return (string) $rule->date_to;
    }

    private function money(float|int $amount): float
    {
        return round((float) $amount, 2);
    }
}
