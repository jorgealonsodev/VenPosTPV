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
}
