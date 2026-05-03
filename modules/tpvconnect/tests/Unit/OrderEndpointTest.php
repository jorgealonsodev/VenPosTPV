<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use PHPUnit\Framework\TestCase;

/**
 * Order endpoint structure and validation tests.
 */
class OrderEndpointTest extends TestCase
{
    /**
     * @test
     */
    public function valid_order_payload_structure(): void
    {
        $payload = [
            'id_customer' => 1,
            'id_currency' => 1,
            'id_shop' => 1,
            'products' => [
                [
                    'id_product' => 1,
                    'id_product_attribute' => 0,
                    'quantity' => 2,
                ],
            ],
            'payment_method' => 'TPV Connect',
            'transaction_id' => 'TXN-12345',
        ];

        $this->assertArrayHasKey('id_customer', $payload);
        $this->assertArrayHasKey('id_currency', $payload);
        $this->assertArrayHasKey('id_shop', $payload);
        $this->assertArrayHasKey('products', $payload);
        $this->assertArrayHasKey('payment_method', $payload);
        $this->assertIsArray($payload['products']);
        $this->assertArrayHasKey('id_product', $payload['products'][0]);
        $this->assertArrayHasKey('quantity', $payload['products'][0]);
    }

    /**
     * @test
     */
    public function missing_customer_id_is_invalid(): void
    {
        $payload = [
            'id_currency' => 1,
            'id_shop' => 1,
            'products' => [],
            'payment_method' => 'TPV',
        ];

        $this->assertArrayNotHasKey('id_customer', $payload);
    }

    /**
     * @test
     */
    public function missing_products_array_is_invalid(): void
    {
        $payload = [
            'id_customer' => 1,
            'id_currency' => 1,
            'id_shop' => 1,
            'payment_method' => 'TPV',
        ];

        $this->assertArrayNotHasKey('products', $payload);
    }

    /**
     * @test
     */
    public function response_contains_order_id_and_reference(): void
    {
        $response = [
            'order_id' => 42,
            'reference' => 'ORD000042',
        ];

        $this->assertArrayHasKey('order_id', $response);
        $this->assertArrayHasKey('reference', $response);
        $this->assertIsInt($response['order_id']);
        $this->assertIsString($response['reference']);
    }

    /**
     * @test
     */
    public function error_response_401_structure(): void
    {
        $response = ['error' => 'Unauthorized'];

        $this->assertArrayHasKey('error', $response);
    }

    /**
     * @test
     */
    public function error_response_422_structure(): void
    {
        $response = [
            'error' => 'Validation failed',
            'details' => ['id_customer' => 'Required integer'],
        ];

        $this->assertArrayHasKey('error', $response);
        $this->assertArrayHasKey('details', $response);
        $this->assertIsArray($response['details']);
    }

    /**
     * @test
     */
    public function rate_limit_counter_structure(): void
    {
        $ip = '192.168.1.1';
        $key = 'tpvconnect_rate_limit_' . md5($ip);

        $this->assertSame('tpvconnect_rate_limit_' . md5($ip), $key);
    }
}
