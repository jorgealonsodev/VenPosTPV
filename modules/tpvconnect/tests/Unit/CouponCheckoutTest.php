<?php

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use CouponCheckout;
use CouponCheckoutException;
use PHPUnit\Framework\TestCase;

final class CouponCheckoutTest extends TestCase
{
    private const SECRET = 'coupon-test-secret-which-is-at-least-32-bytes';

    protected function setUp(): void
    {
        \CouponTestState::reset();
    }

    public function test_preview_accepts_native_null_validity_and_uses_not_in_cart_checks(): void
    {
        $this->rule('SAVE10');
        $preview = (new CouponCheckout(self::SECRET, \Context::getContext()))->preview($this->payload('SAVE10'));

        self::assertSame(100.0, $preview['before_discount_total']);
        self::assertSame(10.0, $preview['discount_amount']);
        self::assertSame(90.0, $preview['total_paid']);
        self::assertSame(0, \CouponTestState::$ordersCreated);
        self::assertSame(0, \CouponTestState::$rules['SAVE10']['used'] ?? 0);
    }

    public function test_preview_rejects_native_inactive_exhausted_expired_and_customer_restricted_rules(): void
    {
        $checkout = new CouponCheckout(self::SECRET, \Context::getContext());
        $this->rule('INACTIVE', ['active' => false]);
        $this->rule('EXHAUSTED', ['quantity' => 0]);
        $this->rule('EXPIRED', ['date_to' => '2000-01-01 00:00:00']);
        $this->rule('MEMBER', ['customer_id' => 22]);

        foreach (['', 'UNKNOWN', 'INACTIVE', 'EXHAUSTED', 'EXPIRED', 'MEMBER'] as $code) {
            try {
                $checkout->preview($this->payload($code));
                self::fail('Expected native coupon rejection for ' . $code);
            } catch (CouponCheckoutException $exception) {
                self::assertSame(422, $exception->getHttpStatus());
            }
        }
        self::assertSame(0, \CouponTestState::$ordersCreated);
    }

    public function test_redeem_uses_native_cart_quantity_not_stock_quantity(): void
    {
        $this->rule('SAVE10');
        \CouponTestState::$stockQuantity = 999;
        $checkout = new CouponCheckout(self::SECRET, \Context::getContext());
        $preview = $checkout->preview($this->payload('SAVE10'));

        $result = $checkout->redeem($this->couponOrderRequest($preview, 'SAVE10'), static function (\Cart $cart, float $total): array {
            \CouponTestState::$ordersCreated++;
            return ['order_id' => 55, 'reference' => 'ORD55'];
        });

        self::assertSame(55, $result['order_id']);
        self::assertSame(1, \CouponTestState::$ordersCreated);
    }

    public function test_redeem_rejects_removed_rule_with_compensating_product_price_before_callback(): void
    {
        $this->rule('SAVE10');
        \CouponTestState::$stockQuantity = 1;
        $checkout = new CouponCheckout(self::SECRET, \Context::getContext());
        $preview = $checkout->preview($this->payload('SAVE10'));
        $cart = \CouponTestState::$carts[$preview['cart_id']];
        $cart->cartRules = [];
        \CouponTestState::$carts[$cart->id] = $cart;
        \CouponTestState::$productPrices[1] = 90.0;
        $callbacks = 0;

        try {
            $checkout->redeem($this->couponOrderRequest($preview, 'SAVE10'), static function () use (&$callbacks): array {
                $callbacks++;
                return ['order_id' => 99];
            });
            self::fail('Expected removed coupon rejection');
        } catch (CouponCheckoutException $exception) {
            self::assertSame(422, $exception->getHttpStatus());
        }
        self::assertSame(0, $callbacks);
    }

    public function test_redeem_rechecks_attached_rule_freshness_without_duplicate_guard(): void
    {
        $this->rule('SAVE10');
        \CouponTestState::$stockQuantity = 1;
        $checkout = new CouponCheckout(self::SECRET, \Context::getContext());
        $preview = $checkout->preview($this->payload('SAVE10'));
        \CouponTestState::$rules['SAVE10']['quantity'] = 0;

        try {
            $checkout->redeem($this->couponOrderRequest($preview, 'SAVE10'), static fn (): array => ['order_id' => 99]);
            self::fail('Expected exhausted attached coupon rejection');
        } catch (CouponCheckoutException $exception) {
            self::assertSame(422, $exception->getHttpStatus());
        }
    }

    public function test_redeem_rejects_forged_or_expired_quote(): void
    {
        $this->rule('SAVE10');
        $checkout = new CouponCheckout(self::SECRET, \Context::getContext());
        $preview = $checkout->preview($this->payload('SAVE10'));
        $expired = CouponCheckout::signQuote([
            'cart_id' => $preview['cart_id'], 'customer_id' => 1, 'shop_id' => 1, 'currency_id' => 1,
            'coupon_id' => 7, 'coupon_code' => 'SAVE10', 'coupon_expires_at' => '2030-01-01 00:00:00',
            'products' => [['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 1]],
            'before_discount_total' => 100.0, 'discount_amount' => 10.0, 'total_paid' => 90.0, 'expires_at' => time() - 1,
        ], self::SECRET);

        foreach ([$preview['quote'] . 'x', $expired] as $quote) {
            $this->expectException(CouponCheckoutException::class);
            $checkout->redeem($this->couponOrderRequest($preview, 'SAVE10', $quote), static fn (): array => ['order_id' => 99]);
        }
    }

    private function rule(string $code, array $overrides = []): void
    {
        \CouponTestState::$rules[$code] = $overrides + [
            'id' => 7, 'reduction' => 10.0, 'active' => true, 'quantity' => 1,
            'date_from' => '2000-01-01 00:00:00', 'date_to' => '2030-01-01 00:00:00',
        ];
    }

    private function payload(string $code): array
    {
        return [
            'id_customer' => 1, 'id_currency' => 1, 'id_shop' => 1, 'payment_method' => 'TPV',
            'products' => [['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 1]],
            'coupon_code' => $code,
        ];
    }

    private function couponOrderRequest(array $preview, string $code, ?string $quote = null): array
    {
        return [
            'id_customer' => 1, 'id_currency' => 1, 'id_shop' => 1, 'payment_method' => 'TPV',
            'products' => [['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 1]],
            'coupon' => [
                'code' => $code, 'preview_cart_id' => $preview['cart_id'], 'quote' => $quote ?? $preview['quote'],
                'expected_total_paid' => $preview['total_paid'],
            ],
        ];
    }
}
