<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use PHPUnit\Framework\TestCase;
use TpvConnect\SsoToken;

class SsoTokenTest extends TestCase
{
    private const TEST_SECRET = 'test-secret-that-is-32-chars-long-1234';

    protected function setUp(): void
    {
        parent::setUp();
        // Clear any stored nonces between tests
        \Configuration::$values = [];
    }

    /**
     * @test
     */
    public function generate_returns_token_with_tilde_separator(): void
    {
        $token = SsoToken::generate(1, 1, self::TEST_SECRET);

        $this->assertStringContainsString('~', $token);
        $parts = explode('~', $token);
        $this->assertCount(2, $parts);
    }

    /**
     * @test
     */
    public function generate_creates_base64url_encoded_payload(): void
    {
        $token = SsoToken::generate(42, 2, self::TEST_SECRET);
        $parts = explode('~', $token);
        $payload = $parts[0];

        // base64url should not contain +, /, or =
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $payload);
    }

    /**
     * @test
     */
    public function generate_stores_nonce_in_configuration(): void
    {
        SsoToken::generate(1, 1, self::TEST_SECRET);

        $found = false;
        foreach (\Configuration::$values as $key => $value) {
            if (str_starts_with($key, 'TPVCONNECT_NONCE_')) {
                $found = true;
                $this->assertIsNumeric($value);
            }
        }

        $this->assertTrue($found, 'Nonce should be stored in Configuration');
    }

    /**
     * @test
     */
    public function verify_valid_token_returns_payload(): void
    {
        $token = SsoToken::generate(42, 2, self::TEST_SECRET);
        $payload = SsoToken::verify($token, self::TEST_SECRET);

        $this->assertIsArray($payload);
        $this->assertSame(42, $payload['employee_id']);
        $this->assertSame(2, $payload['shop_id']);
        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertArrayHasKey('nonce', $payload);
    }

    /**
     * @test
     */
    public function verify_invalid_hmac_returns_null(): void
    {
        $token = SsoToken::generate(1, 1, self::TEST_SECRET);
        $tamperedToken = $token . 'tampered';

        $this->assertNull(SsoToken::verify($tamperedToken, self::TEST_SECRET));
    }

    /**
     * @test
     */
    public function verify_wrong_secret_returns_null(): void
    {
        $token = SsoToken::generate(1, 1, self::TEST_SECRET);

        $this->assertNull(SsoToken::verify($token, 'wrong-secret-that-is-32-chars-long'));
    }

    /**
     * @test
     */
    public function verify_expired_token_returns_null(): void
    {
        // Manually create an expired token
        $payload = json_encode([
            'employee_id' => 1,
            'shop_id' => 1,
            'timestamp' => time() - 120,
            'nonce' => 'expired_nonce_12345678',
        ]);

        $encodedPayload = SsoToken::base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $encodedPayload, self::TEST_SECRET);
        $token = $encodedPayload . '~' . $signature;

        // Store the nonce so validateNonce passes
        \Configuration::updateValue('TPVCONNECT_NONCE_expired_nonce_12345678', (string) (time() - 120));

        $this->assertNull(SsoToken::verify($token, self::TEST_SECRET));
    }

    /**
     * @test
     */
    public function verify_replayed_nonce_returns_null(): void
    {
        $token = SsoToken::generate(1, 1, self::TEST_SECRET);

        // First verify consumes the nonce
        SsoToken::verify($token, self::TEST_SECRET);

        // Second verify should fail (nonce consumed)
        $this->assertNull(SsoToken::verify($token, self::TEST_SECRET));
    }

    /**
     * @test
     */
    public function base64url_encode_decode_roundtrip(): void
    {
        $original = json_encode(['test' => 'data', 'special' => '+/=']);
        $encoded = SsoToken::base64UrlEncode($original);
        $decoded = SsoToken::base64UrlDecode($encoded);

        $this->assertSame($original, $decoded);
    }

    /**
     * @test
     */
    public function base64url_encoding_has_no_special_chars(): void
    {
        $data = json_encode(['binary' => base64_encode(random_bytes(32))]);
        $encoded = SsoToken::base64UrlEncode($data);

        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $encoded);
    }
}
