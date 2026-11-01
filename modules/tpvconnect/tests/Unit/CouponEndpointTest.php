<?php

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use CouponCheckout;
use PHPUnit\Framework\TestCase;

final class CouponEndpointTest extends TestCase
{
    public function test_coupon_preview_operation_requires_a_non_empty_code(): void
    {
        self::assertSame(['coupon_code' => 'Required non-empty string'], CouponCheckout::validateCouponRequest([
            'operation' => 'coupon_preview',
        ]));
    }

    public function test_coupon_order_requires_scalar_binding_and_finite_expected_total(): void
    {
        $errors = CouponCheckout::validateCouponRequest([
            'operation' => 'order',
            'coupon' => ['code' => '', 'preview_cart_id' => '1', 'quote' => [], 'expected_total_paid' => INF],
        ]);

        self::assertArrayHasKey('coupon.code', $errors);
        self::assertArrayHasKey('coupon.preview_cart_id', $errors);
        self::assertArrayHasKey('coupon.quote', $errors);
        self::assertArrayHasKey('coupon.expected_total_paid', $errors);
    }

    public function test_unknown_operation_and_explicit_malformed_coupon_binding_are_rejected(): void
    {
        self::assertSame(['operation' => 'Unknown operation'], CouponCheckout::validateCouponRequest(['operation' => 'redeem_coupon']));
        self::assertSame(['coupon' => 'Must be an object'], CouponCheckout::validateCouponRequest(['coupon' => null]));
        self::assertSame(['coupon' => 'Must be an object'], CouponCheckout::validateCouponRequest(['coupon' => 'SAVE10']));
        self::assertSame([], CouponCheckout::validateCouponRequest([]));
    }
}
