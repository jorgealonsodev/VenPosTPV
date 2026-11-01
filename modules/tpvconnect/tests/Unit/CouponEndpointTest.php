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
}
