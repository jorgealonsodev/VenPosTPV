<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use PHPUnit\Framework\TestCase;

/**
 * HMAC validation utility tests.
 */
class HmacValidationTest extends TestCase
{
    /**
     * @test
     */
    public function hmac_sha256_produces_consistent_signature(): void
    {
        $secret = 'test-secret-that-is-32-chars-long-1234';
        $body = '{"id_customer":1,"products":[{"id_product":1,"quantity":1}]}';

        $sig1 = hash_hmac('sha256', $body, $secret);
        $sig2 = hash_hmac('sha256', $body, $secret);

        $this->assertSame($sig1, $sig2);
        $this->assertSame(64, strlen($sig1)); // hex length of sha256
    }

    /**
     * @test
     */
    public function hmac_changes_with_different_secret(): void
    {
        $body = '{"test":"data"}';
        $secret1 = 'secret-one-that-is-32-chars-long-1';
        $secret2 = 'secret-two-that-is-32-chars-long-2';

        $sig1 = hash_hmac('sha256', $body, $secret1);
        $sig2 = hash_hmac('sha256', $body, $secret2);

        $this->assertNotSame($sig1, $sig2);
    }

    /**
     * @test
     */
    public function hmac_changes_with_different_body(): void
    {
        $secret = 'test-secret-that-is-32-chars-long-1234';
        $body1 = '{"a":1}';
        $body2 = '{"a":2}';

        $sig1 = hash_hmac('sha256', $body1, $secret);
        $sig2 = hash_hmac('sha256', $body2, $secret);

        $this->assertNotSame($sig1, $sig2);
    }

    /**
     * @test
     */
    public function hash_equals_prevents_timing_attacks(): void
    {
        $secret = 'test-secret-that-is-32-chars-long-1234';
        $body = '{"test":"data"}';

        $sig = hash_hmac('sha256', $body, $secret);

        $this->assertTrue(hash_equals($sig, $sig));
        $this->assertFalse(hash_equals($sig, str_repeat('0', 64)));
    }

    /**
     * @test
     */
    public function authorization_header_format_is_parsable(): void
    {
        $secret = 'test-secret-that-is-32-chars-long-1234';
        $body = '{"id_customer":1}';
        $signature = hash_hmac('sha256', $body, $secret);

        $header = 'HMAC-SHA256 ' . $signature;

        $this->assertStringStartsWith('HMAC-SHA256 ', $header);

        $extracted = substr($header, strlen('HMAC-SHA256 '));
        $this->assertSame($signature, $extracted);
    }
}
