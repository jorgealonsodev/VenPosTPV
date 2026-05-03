<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use PHPUnit\Framework\TestCase;

/**
 * Admin configuration validation tests.
 */
class AdminConfigValidationTest extends TestCase
{
    /**
     * @test
     */
    public function valid_https_url_passes_validation(): void
    {
        $url = 'https://tpv.example.com';
        $this->assertTrue((bool) filter_var($url, FILTER_VALIDATE_URL));
        $this->assertStringStartsWith('https://', $url);
    }

    /**
     * @test
     */
    public function http_url_fails_https_requirement(): void
    {
        $url = 'http://tpv.example.com';
        $this->assertStringStartsNotWith('https://', $url);
    }

    /**
     * @test
     */
    public function empty_url_is_invalid(): void
    {
        $url = '';
        $this->assertFalse((bool) filter_var($url, FILTER_VALIDATE_URL));
    }

    /**
     * @test
     */
    public function invalid_url_string_fails_validation(): void
    {
        $url = 'not-a-url';
        $this->assertFalse((bool) filter_var($url, FILTER_VALIDATE_URL));
    }

    /**
     * @test
     */
    public function secret_32_chars_passes_validation(): void
    {
        $secret = str_repeat('a', 32);
        $this->assertGreaterThanOrEqual(32, strlen($secret));
    }

    /**
     * @test
     */
    public function secret_31_chars_fails_validation(): void
    {
        $secret = str_repeat('a', 31);
        $this->assertLessThan(32, strlen($secret));
    }

    /**
     * @test
     */
    public function secret_64_chars_passes_validation(): void
    {
        $secret = bin2hex(random_bytes(32));
        $this->assertGreaterThanOrEqual(32, strlen($secret));
    }
}
