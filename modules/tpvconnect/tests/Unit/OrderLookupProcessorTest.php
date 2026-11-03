<?php

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use OrderLookupGateway;
use OrderLookupProcessor;
use PHPUnit\Framework\TestCase;
use RefundRequestException;

final class FakeOrderLookupGateway implements OrderLookupGateway
{
    /** @var array<int, array<string, mixed>> */
    public array $orders = [];

    public function findOrderIdsByReference(string $reference): array
    {
        $ids = [];
        foreach ($this->orders as $id => $order) {
            if ($order['reference'] === $reference) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function loadOrder(int $orderId): ?array
    {
        return $this->orders[$orderId] ?? null;
    }
}

final class OrderLookupProcessorTest extends TestCase
{
    private FakeOrderLookupGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new FakeOrderLookupGateway();
        $this->gateway->orders[77] = [
            'id' => 77,
            'reference' => 'XKBKNABJK',
            'date_add' => '2026-10-20 10:00:00',
            'customer_name' => 'Ana Pérez',
            'module' => 'ps_checkout',
            'current_state' => ['id' => 5, 'name' => 'Entregado'],
            // 10 € coupon over 33.33 + 33.33 + 33.34 of products.
            'total_discounts_tax_incl' => 10.0,
            'shipping_discount_tax_incl' => 0.0,
            'history' => [
                ['id_order_state' => 2, 'date_add' => '2026-10-20 10:05:00', 'delivery' => false],
                ['id_order_state' => 5, 'date_add' => '2026-10-23 12:00:00', 'delivery' => true],
                ['id_order_state' => 4, 'date_add' => '2026-10-22 09:00:00', 'delivery' => true],
            ],
            'details' => [
                // Listed out of id order on purpose: the lookup sorts them.
                ['id_order_detail' => 302, 'product_id' => 5, 'product_attribute_id' => 0, 'product_name' => 'Gorro', 'product_quantity' => 1, 'slipped_quantity' => 0, 'total_price_tax_incl' => 33.34, 'unit_price_tax_incl' => 33.34],
                ['id_order_detail' => 300, 'product_id' => 3, 'product_attribute_id' => 7, 'product_name' => 'Camisa - Talla: M', 'product_quantity' => 1, 'slipped_quantity' => 0, 'total_price_tax_incl' => 33.33, 'unit_price_tax_incl' => 33.33],
                ['id_order_detail' => 301, 'product_id' => 4, 'product_attribute_id' => 0, 'product_name' => 'Calcetines', 'product_quantity' => 3, 'slipped_quantity' => 1, 'total_price_tax_incl' => 33.33, 'unit_price_tax_incl' => 11.11],
            ],
        ];
    }

    private function lookup(array $request): array
    {
        return (new OrderLookupProcessor($this->gateway))->process($request);
    }

    public function testProratesOrderDiscountOverLinesToTheCentWithTheRemainderOnTheHeaviestLine(): void
    {
        $order = $this->lookup(['id_order' => 77])['orders'][0];

        $this->assertSame([300, 301, 302], array_column($order['lines'], 'id_order_detail'));
        // 10 * 33.33 / 100 = 3.333 -> 3.33 each; the heaviest line (33.34) takes 3.34.
        $this->assertSame([30.0, 30.0, 30.0], array_column($order['lines'], 'paid_total'));
        $this->assertSame(90.0, round(array_sum(array_column($order['lines'], 'paid_total')), 2));
    }

    public function testRemainderGoesToTheHeaviestLineWhenSharesDoNotRoundEvenly(): void
    {
        $this->gateway->orders[77]['total_discounts_tax_incl'] = 0.05;
        $order = $this->lookup(['id_order' => 77])['orders'][0];

        // 0.05 * 33.33/100 = 0.0167 -> 0.02 on each light line, heaviest takes 0.01.
        $this->assertSame([33.31, 33.31, 33.33], array_column($order['lines'], 'paid_total'));
    }

    public function testFreeShippingPartOfTheDiscountIsNotChargedToProducts(): void
    {
        $this->gateway->orders[77]['total_discounts_tax_incl'] = 4.95;
        $this->gateway->orders[77]['shipping_discount_tax_incl'] = 4.95;
        $order = $this->lookup(['id_order' => 77])['orders'][0];

        $this->assertSame([33.33, 33.33, 33.34], array_column($order['lines'], 'paid_total'));
    }

    public function testDeliveredAtIsTheFirstDeliveredStateAndRefundableComesFromSlips(): void
    {
        $order = $this->lookup(['id_order' => 77])['orders'][0];

        $this->assertSame('2026-10-22 09:00:00', $order['delivered_at']);
        $this->assertSame(77, $order['id_order']);
        $this->assertSame('XKBKNABJK', $order['reference']);
        $this->assertSame('2026-10-20 10:00:00', $order['date_add']);
        $this->assertSame('Ana Pérez', $order['customer_name']);
        $this->assertFalse($order['created_by_tpv']);
        $this->assertSame(['id' => 5, 'name' => 'Entregado'], $order['current_state']);
        $this->assertSame(
            ['id_order_detail' => 301, 'product_id' => 4, 'product_attribute_id' => 0, 'name' => 'Calcetines', 'quantity' => 3, 'refundable_quantity' => 2, 'paid_total' => 30.0, 'unit_price_tax_incl' => 11.11],
            $order['lines'][1],
        );
    }

    public function testUndeliveredOrderHasNoDeliveryDateAndTpvOrdersAreFlagged(): void
    {
        $this->gateway->orders[77]['history'] = [['id_order_state' => 2, 'date_add' => '2026-10-20 10:05:00', 'delivery' => false]];
        $this->gateway->orders[77]['module'] = 'tpvconnect';

        $order = $this->lookup(['id_order' => 77])['orders'][0];

        $this->assertNull($order['delivered_at']);
        $this->assertTrue($order['created_by_tpv']);
    }

    public function testReferenceIsCaseInsensitiveAndReturnsEveryOrderSharingIt(): void
    {
        $this->gateway->orders[78] = ['id' => 78] + $this->gateway->orders[77];

        $orders = $this->lookup(['reference' => 'xkbknabjk'])['orders'];

        $this->assertSame([77, 78], array_column($orders, 'id_order'));
    }

    public function testUnknownOrderIs404(): void
    {
        foreach ([['id_order' => 99], ['reference' => 'NOTTHERE1']] as $request) {
            try {
                $this->lookup($request);
                $this->fail('Expected 404');
            } catch (RefundRequestException $e) {
                $this->assertSame(404, $e->getHttpCode());
            }
        }
    }

    public function testInvalidInputIs422(): void
    {
        foreach ([[], ['id_order' => 0], ['id_order' => '77'], ['reference' => 'ab cd'], ['reference' => str_repeat('A', 33)]] as $request) {
            try {
                $this->lookup($request);
                $this->fail('Expected 422 for ' . json_encode($request));
            } catch (RefundRequestException $e) {
                $this->assertSame(422, $e->getHttpCode());
            }
        }
    }

    public function testSignatureMustBeTheHmacOfTheExactBody(): void
    {
        $body = '{"id_order":77}';
        $secret = 'shared-secret-of-at-least-32-characters';
        $valid = 'HMAC-SHA256 ' . hash_hmac('sha256', $body, $secret);

        $this->assertTrue(OrderLookupProcessor::verifySignature($body, $secret, $valid));
        $this->assertFalse(OrderLookupProcessor::verifySignature('{"id_order":78}', $secret, $valid));
        $this->assertFalse(OrderLookupProcessor::verifySignature($body, 'other-secret', $valid));
        $this->assertFalse(OrderLookupProcessor::verifySignature($body, $secret, hash_hmac('sha256', $body, $secret)));
        $this->assertFalse(OrderLookupProcessor::verifySignature($body, '', 'HMAC-SHA256 ' . hash_hmac('sha256', $body, '')));
    }
}
