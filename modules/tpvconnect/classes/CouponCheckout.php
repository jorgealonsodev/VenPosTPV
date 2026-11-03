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

/**
 * The order cannot be built from this request and retrying it unchanged will not
 * help (PrestaShop rejects the temporary address, the cart refuses a product). The
 * endpoint answers 422, which the TPV treats as definitive: an incident to correct,
 * not an upload retried forever.
 */
class TpvOrderBuildException extends RuntimeException
{
    public function getHttpStatus(): int
    {
        return 422;
    }
}

/**
 * Builds the module's carts and binds a native CartRule preview to one cart.
 *
 * This deliberately delegates eligibility and consumption to PrestaShop:
 * CartRule::checkValidity(), Cart::addCartRule(), and validateOrder().
 */
class CouponCheckout
{
    private const QUOTE_TTL_SECONDS = 300;
    private const LOCK_WAIT_SECONDS = 5;

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

    /**
     * Revalidate a quoted cart and call validateOrder exactly once while its
     * module-local cart lock is held. The callback must create the order.
     */
    public function redeem(array $data, callable $createOrder): array
    {
        $coupon = $data['coupon'] ?? null;
        if (!is_array($coupon)) {
            throw new CouponCheckoutException('Coupon binding is required');
        }
        $quote = $this->verifyQuote($coupon['quote'] ?? null);
        $this->assertRequestMatchesQuote($data, $coupon, $quote);

        // The rule lock serializes separate preview carts for one coupon; the
        // cart lock keeps retries for this exact quote in a deterministic order.
        $lockNames = [
            'tpvconnect_coupon_rule_' . (int) $quote['coupon_id'],
            'tpvconnect_coupon_cart_' . (int) $quote['cart_id'],
        ];
        $acquiredLocks = [];
        try {
            foreach ($lockNames as $lockName) {
                if (!$this->acquireLock($lockName)) {
                    throw new CouponCheckoutException('Coupon checkout is busy; request a new preview', 503);
                }
                $acquiredLocks[] = $lockName;
            }

            $cart = new Cart((int) $quote['cart_id']);
            if (!Validate::isLoadedObject($cart)) {
                throw new CouponCheckoutException('Preview cart no longer exists');
            }
            $this->assertCartMatchesQuote($cart, $data, $quote);
            $this->assertQuotedRuleApplied($cart, $quote);

            // A successful exact retry must replay the first order even though
            // validateOrder has already consumed the single-use CartRule.
            $existingOrderId = (int) Order::getIdByCartId((int) $cart->id);
            if ($existingOrderId > 0) {
                $order = new Order($existingOrderId);
                if (!Validate::isLoadedObject($order)) {
                    throw new CouponCheckoutException('Existing order is unavailable');
                }
                if (!$this->sameMoney((float) $order->total_paid, (float) $quote['total_paid'])) {
                    throw new CouponCheckoutException('Existing order total does not match preview');
                }
                $this->assertOrderCouponLineage($order, $quote);
                return [
                    'order_id' => (int) $order->id,
                    'reference' => (string) $order->reference,
                    'total_paid' => $this->money((float) $order->total_paid),
                ];
            }

            if (method_exists($cart, 'checkQuantities') && !$cart->checkQuantities()) {
                throw new CouponCheckoutException('Cart product quantities are no longer available');
            }
            $this->validateNativeRule($cart, (string) $quote['coupon_code'], (int) $quote['coupon_id'], (string) $quote['coupon_expires_at'], true);
            $beforeDiscountTotal = $this->grossOrderTotal($cart);
            $totalPaid = $this->payableOrderTotal($cart);
            if (!$this->sameMoney($beforeDiscountTotal, (float) $quote['before_discount_total'])
                || !$this->sameMoney($totalPaid, (float) $quote['total_paid'])
                || !$this->sameMoney($totalPaid, (float) $coupon['expected_total_paid'])) {
                throw new CouponCheckoutException('Coupon or cart totals changed; request a new preview');
            }

            $result = $createOrder($cart, $totalPaid);
            if (!is_array($result)) {
                throw new RuntimeException('Order creation did not return an order');
            }
            $result['total_paid'] = $totalPaid;
            return $result;
        } finally {
            foreach (array_reverse($acquiredLocks) as $acquiredLock) {
                $this->releaseLock($acquiredLock);
            }
        }
    }

    /** Public so isolated tests can make an expired or forged binding. */
    public static function signQuote(array $quote, string $secret): string
    {
        $encoded = rtrim(strtr(base64_encode((string) json_encode($quote, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        return $encoded . '.' . hash_hmac('sha256', $encoded, $secret);
    }

    /** Placeholder for countries that need an identification number; passes Validate::isDniLite. */
    public const TEMPORARY_ADDRESS_DNI = 'TPV';

    /**
     * Shared cart construction for ordinary orders and coupon previews.
     *
     * The customer's first address is used when there is one. Otherwise a temporary
     * address is created in the shop's default country, with a dni placeholder only
     * when that country needs an identification number (PrestaShop refuses the
     * address without one: "Address->dni is empty").
     *
     * @throws TpvOrderBuildException 422: the address or a product is refused
     */
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
            $dni = $this->temporaryAddressDni((int) $address->id_country);
            if ($dni !== null) {
                $address->dni = $dni;
            }
            // Validated first, so a refused field is a 422 and never a 500 from add().
            $valid = $address->validateFields(false, true);
            if ($valid !== true) {
                throw new TpvOrderBuildException('Temporary address is invalid: ' . (is_string($valid) ? $valid : 'refused by PrestaShop'));
            }
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
                throw new TpvOrderBuildException(sprintf('Product could not be added to cart: %d', (int) $product['id_product']));
            }
        }
        return $cart;
    }

    /** The dni the temporary address needs in that country; null when it needs none. */
    protected function temporaryAddressDni(int $idCountry): ?string
    {
        return Address::dniRequired($idCountry) ? self::TEMPORARY_ADDRESS_DNI : null;
    }

    private function verifyQuote(mixed $token): array
    {
        if (!is_string($token) || substr_count($token, '.') !== 1) {
            throw new CouponCheckoutException('Coupon quote is invalid');
        }
        [$encoded, $signature] = explode('.', $token, 2);
        $expected = hash_hmac('sha256', $encoded, $this->secret);
        if (!hash_equals($expected, $signature)) {
            throw new CouponCheckoutException('Coupon quote is invalid');
        }
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        $quote = $json === false ? null : json_decode($json, true);
        $required = ['cart_id', 'customer_id', 'shop_id', 'currency_id', 'coupon_id', 'coupon_code', 'coupon_expires_at', 'products', 'cart_rules', 'before_discount_total', 'discount_amount', 'total_paid', 'expires_at'];
        if (!is_array($quote) || array_diff($required, array_keys($quote)) || !is_int($quote['expires_at']) || $quote['expires_at'] < time()) {
            throw new CouponCheckoutException('Coupon quote is invalid or expired');
        }
        return $quote;
    }

    private function assertRequestMatchesQuote(array $data, array $coupon, array $quote): void
    {
        if ((int) ($coupon['preview_cart_id'] ?? 0) !== $quote['cart_id']
            || !is_string($coupon['code'] ?? null) || !hash_equals($quote['coupon_code'], $coupon['code'])
            || (int) ($data['id_customer'] ?? 0) !== $quote['customer_id']
            || (int) ($data['id_shop'] ?? 0) !== $quote['shop_id']
            || (int) ($data['id_currency'] ?? 0) !== $quote['currency_id']
            || $this->requestProductFingerprint($data['products'] ?? []) !== $quote['products']) {
            throw new CouponCheckoutException('Coupon quote does not match this order');
        }
    }

    private function assertCartMatchesQuote(Cart $cart, array $data, array $quote): void
    {
        if ((int) $cart->id_customer !== $quote['customer_id'] || (int) $cart->id_shop !== $quote['shop_id']
            || (int) $cart->id_currency !== $quote['currency_id'] || $this->cartProductFingerprint($cart->getProducts()) !== $quote['products']) {
            throw new CouponCheckoutException('Preview cart changed; request a new preview');
        }
        $this->setContext($cart, new Customer((int) $data['id_customer']), new Currency((int) $data['id_currency']));
    }

    private function validateNativeRule(Cart $cart, string $code, ?int $expectedId = null, ?string $expectedExpiry = null, bool $alreadyInCart = false): CartRule
    {
        $this->setContext($cart, new Customer((int) $cart->id_customer), new Currency((int) $cart->id_currency));
        $idRule = (int) CartRule::getIdByCode($code, (int) $this->context->language->id);
        $rule = new CartRule($idRule);
        if ($idRule < 1 || !Validate::isLoadedObject($rule) || $expectedId !== null && (int) $rule->id !== $expectedId
            || $expectedExpiry !== null && $this->ruleExpiry($rule) !== $expectedExpiry) {
            throw new CouponCheckoutException('Coupon is invalid');
        }

        if ($alreadyInCart && !$this->ruleIsCurrent($rule)) {
            throw new CouponCheckoutException('Coupon is no longer eligible');
        }
        // PS 8.1 returns null (not true) for a valid check with display_error.
        // For an attached rule, native duplicate detection is intentionally
        // bypassed while ruleIsCurrent() freshly checks status/date/quantity.
        if ($rule->checkValidity($this->context, $alreadyInCart, true) !== null) {
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

    private function ruleIsCurrent(CartRule $rule): bool
    {
        $now = date('Y-m-d H:i:s');
        return (bool) $rule->active && (int) $rule->quantity > 0
            && (string) $rule->date_from <= $now && (string) $rule->date_to >= $now;
    }

    private function assertQuotedRuleApplied(Cart $cart, array $quote): void
    {
        $cartRules = $this->cartRuleFingerprint($cart);
        if (!$this->sameCartRuleFingerprint($cartRules, $quote['cart_rules'])) {
            throw new CouponCheckoutException('Coupon attachment changed; request a new preview');
        }

        foreach ($cartRules as $cartRule) {
            if ($cartRule['id_cart_rule'] === (int) $quote['coupon_id']
                && $this->sameMoney($cartRule['value_real'], (float) $quote['discount_amount'])) {
                return;
            }
        }
        throw new CouponCheckoutException('Coupon discount changed; request a new preview');
    }

    private function sameCartRuleFingerprint(array $current, mixed $quoted): bool
    {
        if (!is_array($quoted) || count($current) !== count($quoted)) {
            return false;
        }
        foreach ($current as $index => $cartRule) {
            if (!is_array($quoted[$index] ?? null)
                || $cartRule['id_cart_rule'] !== (int) ($quoted[$index]['id_cart_rule'] ?? 0)
                || !$this->sameMoney($cartRule['value_real'], (float) ($quoted[$index]['value_real'] ?? NAN))) {
                return false;
            }
        }
        return true;
    }

    private function requestProductFingerprint(array $products): array
    {
        return $this->productFingerprint($products, 'quantity');
    }

    private function cartProductFingerprint(array $products): array
    {
        // Cart::getProducts() exposes requested amount as cart_quantity;
        // quantity is stock availability and must not bind the checkout quote.
        return $this->productFingerprint($products, 'cart_quantity');
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

    private function assertOrderCouponLineage(Order $order, array $quote): void
    {
        if (!method_exists($order, 'getCartRules')) {
            throw new CouponCheckoutException('Existing order coupon lineage is unavailable');
        }
        $recordedRules = [];
        foreach ($order->getCartRules() as $cartRule) {
            if (!isset($cartRule['id_cart_rule'], $cartRule['value'])) {
                throw new CouponCheckoutException('Existing order coupon lineage is unavailable');
            }
            $recordedRules[] = [
                'id_cart_rule' => (int) $cartRule['id_cart_rule'],
                'value_real' => $this->money((float) $cartRule['value']),
            ];
        }
        usort($recordedRules, static fn (array $left, array $right): int => $left <=> $right);
        if (!$this->sameCartRuleFingerprint($recordedRules, $quote['cart_rules'])) {
            throw new CouponCheckoutException('Existing order coupon lineage does not match preview');
        }
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

    private function acquireLock(string $lockName): bool
    {
        $rows = Db::getInstance()->executeS("SELECT GET_LOCK('" . pSQL($lockName) . "', " . self::LOCK_WAIT_SECONDS . ') AS locked', true, false);
        return is_array($rows) && isset($rows[0]['locked']) && (int) $rows[0]['locked'] === 1;
    }

    private function releaseLock(string $lockName): void
    {
        Db::getInstance()->executeS("SELECT RELEASE_LOCK('" . pSQL($lockName) . "') AS released", true, false);
    }

    private function ruleExpiry(CartRule $rule): string
    {
        return (string) $rule->date_to;
    }

    private function money(float|int $amount): float
    {
        return round((float) $amount, 2);
    }

    private function sameMoney(float $left, float $right): bool
    {
        return abs($this->money($left) - $this->money($right)) < 0.00001;
    }
}
