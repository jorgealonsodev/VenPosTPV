<?php

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use PHPUnit\Framework\TestCase;
use RefundGateway;
use RefundProcessor;
use RefundRequestException;

final class FakeRefundGateway implements RefundGateway
{
    /** @var array<int, array<string, mixed>> */
    public array $orders = [];
    /** @var array<int, array{order_id: int, products: array<int, array<string, mixed>>, amount: float}> */
    public array $slips = [];
    /** @var array<int, array{int, int, int, int}> */
    public array $restocks = [];
    /** @var array<int, array{int, int}> */
    public array $states = [];
    /** @var array<string, array<string, mixed>> */
    public array $requests = [];
    public bool $lockAvailable = true;
    public int $locksReleased = 0;
    public ?string $failRestockFor = null;

    public function loadOrder(int $orderId): ?array
    {
        return $this->orders[$orderId] ?? null;
    }

    public function createSlip(int $orderId, array $productList, float $amount): int
    {
        $this->slips[] = ['order_id' => $orderId, 'products' => $productList, 'amount' => $amount];
        foreach ($productList as $product) {
            foreach ($this->orders[$orderId]['details'] as &$detail) {
                if ($detail['id_order_detail'] === $product['id_order_detail']) {
                    $detail['slipped_quantity'] += $product['quantity'];
                }
            }
            unset($detail);
        }

        return 500 + \count($this->slips);
    }

    public function restock(int $productId, int $attributeId, int $quantity, int $shopId): void
    {
        if ($this->failRestockFor === $productId . ':' . $attributeId) {
            throw new \RuntimeException('stock locked');
        }
        $this->restocks[] = [$productId, $attributeId, $quantity, $shopId];
    }

    public function setOrderState(int $orderId, int $stateId): void
    {
        $this->states[] = [$orderId, $stateId];
    }

    public function findProcessedRequest(string $requestId): ?array
    {
        return $this->requests[$requestId] ?? null;
    }

    public function rememberProcessedRequest(string $requestId, array $result): void
    {
        $this->requests[$requestId] = $result;
    }

    public function acquireLock(string $name): bool
    {
        return $this->lockAvailable;
    }

    public function releaseLock(string $name): void
    {
        ++$this->locksReleased;
    }
}

final class RefundProcessorTest extends TestCase
{
    private FakeRefundGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new FakeRefundGateway();
        $this->gateway->orders[10] = [
            'id' => 10,
            'id_shop' => 1,
            'details' => [
                // Shirt size M (combination 7): 2 sold at 30 incl. / 24.79 excl.
                ['id_order_detail' => 100, 'product_id' => 3, 'product_attribute_id' => 7, 'product_quantity' => 2, 'slipped_quantity' => 0, 'unit_price_tax_excl' => 24.79, 'unit_price_tax_incl' => 30.0],
                // Socks, no combination: 1 sold at 20.
                ['id_order_detail' => 101, 'product_id' => 4, 'product_attribute_id' => 0, 'product_quantity' => 1, 'slipped_quantity' => 0, 'unit_price_tax_excl' => 16.53, 'unit_price_tax_incl' => 20.0],
            ],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function request(array $overrides = []): array
    {
        return $overrides + [
            'ps_order_id' => 10,
            'request_id' => 'req-0001-abcd',
            'refund_type' => 'partial',
            'reason' => 'size',
            'lines' => [
                ['id_product' => 3, 'id_product_attribute' => 7, 'quantity' => 1, 'amount' => 27.0, 'restock' => true],
            ],
        ];
    }

    private function process(array $request): array
    {
        return (new RefundProcessor($this->gateway, 9))->process($request);
    }

    public function testCreatesSlipWithChosenPaidAmountAndRestocksExactCombination(): void
    {
        $result = $this->process($this->request());

        $this->assertSame(501, $result['order_slip_id']);
        $this->assertSame(27.0, $result['refunded_amount']);
        $this->assertCount(1, $this->gateway->slips);
        $this->assertSame(27.0, $this->gateway->slips[0]['amount']);
        $this->assertSame(
            [['id_order_detail' => 100, 'quantity' => 1, 'unit_price' => 24.79]],
            $this->gateway->slips[0]['products'],
        );
        $this->assertSame([[3, 7, 1, 1]], $this->gateway->restocks);
        $this->assertSame([[10, 9]], $this->gateway->states);
        $this->assertSame(
            [['id_order_detail' => 100, 'id_product' => 3, 'id_product_attribute' => 7, 'quantity' => 1, 'amount' => 27.0, 'restocked' => true]],
            $result['refunded_lines'],
        );
        $this->assertSame([], $result['restock_failed']);
        $this->assertSame(1, $this->gateway->locksReleased);
    }

    public function testDoesNotRestockWhenLineOptsOut(): void
    {
        $request = $this->request();
        $request['lines'][0]['restock'] = false;

        $result = $this->process($request);

        $this->assertSame([], $this->gateway->restocks);
        $this->assertFalse($result['refunded_lines'][0]['restocked']);
    }

    public function testRejectsQuantityAboveWhatIsStillRefundable(): void
    {
        $this->gateway->orders[10]['details'][0]['slipped_quantity'] = 2;

        try {
            $this->process($this->request());
            $this->fail('Expected rejection');
        } catch (RefundRequestException $e) {
            $this->assertSame(422, $e->getHttpCode());
            $this->assertArrayHasKey('lines.0.quantity', $e->getDetails());
        }
        $this->assertSame([], $this->gateway->slips);
        $this->assertSame([], $this->gateway->restocks);
        $this->assertSame(1, $this->gateway->locksReleased);
    }

    public function testRejectsRepeatedLinesThatTogetherExceedRefundable(): void
    {
        $line = ['id_product' => 4, 'id_product_attribute' => 0, 'quantity' => 1, 'amount' => 20.0, 'restock' => true];

        $this->expectException(RefundRequestException::class);
        $this->process($this->request(['lines' => [$line, $line]]));
    }

    public function testRejectsAmountAboveTheLinePrice(): void
    {
        $request = $this->request();
        $request['lines'][0]['amount'] = 30.5;

        try {
            $this->process($request);
            $this->fail('Expected rejection');
        } catch (RefundRequestException $e) {
            $this->assertSame(422, $e->getHttpCode());
            $this->assertArrayHasKey('lines.0.amount', $e->getDetails());
        }
        $this->assertSame([], $this->gateway->slips);
    }

    public function testRejectsProductThatIsNotInTheOrder(): void
    {
        $request = $this->request();
        $request['lines'][0]['id_product_attribute'] = 8;

        $this->expectException(RefundRequestException::class);
        $this->process($request);
    }

    public function testUnknownOrderIs404(): void
    {
        try {
            $this->process($this->request(['ps_order_id' => 99]));
            $this->fail('Expected rejection');
        } catch (RefundRequestException $e) {
            $this->assertSame(404, $e->getHttpCode());
        }
    }

    public function testInvalidInputIsRejectedBeforeTouchingTheShop(): void
    {
        try {
            $this->process(['ps_order_id' => 10, 'request_id' => 'x', 'lines' => [['id_product' => 3, 'quantity' => 0, 'amount' => -1]]]);
            $this->fail('Expected rejection');
        } catch (RefundRequestException $e) {
            $this->assertSame(422, $e->getHttpCode());
            $details = $e->getDetails();
            $this->assertArrayHasKey('request_id', $details);
            $this->assertArrayHasKey('lines.0.quantity', $details);
            $this->assertArrayHasKey('lines.0.amount', $details);
        }
        $this->assertSame([], $this->gateway->slips);
        $this->assertSame(0, $this->gateway->locksReleased);
    }

    public function testReplayOfSameRequestReturnsFirstResultWithoutSecondSlip(): void
    {
        $first = $this->process($this->request());
        $second = $this->process($this->request());

        $this->assertCount(1, $this->gateway->slips);
        $this->assertCount(1, $this->gateway->restocks);
        $this->assertSame($first['order_slip_id'], $second['order_slip_id']);
        $this->assertTrue($second['replayed']);
        $this->assertFalse($first['replayed']);
    }

    public function testBusyOrderIsRejectedAsConflict(): void
    {
        $this->gateway->lockAvailable = false;

        try {
            $this->process($this->request());
            $this->fail('Expected rejection');
        } catch (RefundRequestException $e) {
            $this->assertSame(409, $e->getHttpCode());
        }
        $this->assertSame([], $this->gateway->slips);
    }

    public function testRestockFailureIsReportedWithoutUndoingTheSlip(): void
    {
        $this->gateway->failRestockFor = '3:7';

        $result = $this->process($this->request());

        $this->assertSame(501, $result['order_slip_id']);
        $this->assertSame([['id_product' => 3, 'id_product_attribute' => 7, 'quantity' => 1, 'error' => 'stock locked']], $result['restock_failed']);
        $this->assertFalse($result['refunded_lines'][0]['restocked']);
    }

    public function testNoStateChangeWhenRefundStateIsNotConfigured(): void
    {
        (new RefundProcessor($this->gateway, 0))->process($this->request());

        $this->assertSame([], $this->gateway->states);
    }
}
