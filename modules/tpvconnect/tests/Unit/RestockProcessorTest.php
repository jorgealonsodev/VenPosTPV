<?php

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use PHPUnit\Framework\TestCase;
use RefundRequestException;
use RestockGateway;
use RestockProcessor;

final class FakeRestockGateway implements RestockGateway
{
    /** @var array<string, bool> "product:attribute" => exists */
    public array $products = ['3:7' => true, '4:0' => true];
    /** @var array<int, array{int, int, int, int}> */
    public array $restocks = [];
    /** @var array<string, array<string, mixed>> */
    public array $requests = [];
    /** @var array<int, string> */
    public array $locks = [];
    public bool $lockAvailable = true;
    public int $locksReleased = 0;
    public ?string $failRestockFor = null;
    /** @var array<int, array<string, mixed>> what was stored, in order */
    public array $rememberLog = [];

    public function productExists(int $productId, int $attributeId): bool
    {
        return $this->products[$productId . ':' . $attributeId] ?? false;
    }

    public function shopId(): int
    {
        return 1;
    }

    public function restock(int $productId, int $attributeId, int $quantity, int $shopId): void
    {
        if ($this->failRestockFor === $productId . ':' . $attributeId) {
            throw new \RuntimeException('stock locked');
        }
        $this->restocks[] = [$productId, $attributeId, $quantity, $shopId];
    }

    public function findProcessedRequest(string $requestId): ?array
    {
        return $this->requests[$requestId] ?? null;
    }

    public function rememberProcessedRequest(string $requestId, array $result): void
    {
        $this->requests[$requestId] = $result;
        $this->rememberLog[] = $result;
    }

    public function acquireLock(string $name): bool
    {
        $this->locks[] = $name;

        return $this->lockAvailable;
    }

    public function releaseLock(string $name): void
    {
        ++$this->locksReleased;
    }
}

final class RestockProcessorTest extends TestCase
{
    private FakeRestockGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new FakeRestockGateway();
    }

    /** @param array<string, mixed> $overrides */
    private function request(array $overrides = []): array
    {
        return $overrides + [
            'request_id' => 'noticket-0001-abcd',
            'lines' => [
                ['id_product' => 3, 'id_product_attribute' => 7, 'quantity' => 2],
                ['id_product' => 4, 'id_product_attribute' => 0, 'quantity' => 1],
            ],
        ];
    }

    private function process(array $request): array
    {
        return (new RestockProcessor($this->gateway))->process($request);
    }

    private function expectRejection(int $httpCode, callable $call): RefundRequestException
    {
        try {
            $call();
        } catch (RefundRequestException $e) {
            $this->assertSame($httpCode, $e->getHttpCode(), $e->getMessage());

            return $e;
        }
        $this->fail('Expected RefundRequestException ' . $httpCode);
    }

    public function testAddsEachQuantityAsADeltaToTheExactCombination(): void
    {
        $result = $this->process($this->request());

        $this->assertFalse($result['replayed']);
        $this->assertSame([[3, 7, 2, 1], [4, 0, 1, 1]], $this->gateway->restocks);
        $this->assertSame([
            ['id_product' => 3, 'id_product_attribute' => 7, 'quantity' => 2, 'restocked' => true],
            ['id_product' => 4, 'id_product_attribute' => 0, 'quantity' => 1, 'restocked' => true],
        ], $result['restocked_lines']);
        $this->assertSame([], $result['restock_failed']);
        $this->assertSame(1, $this->gateway->locksReleased);
    }

    public function testTheSameRequestIdReplaysTheFirstResultWithoutRestockingTwice(): void
    {
        $first = $this->process($this->request());
        $second = $this->process($this->request());

        $this->assertTrue($second['replayed']);
        $this->assertSame($first['restocked_lines'], $second['restocked_lines']);
        $this->assertCount(2, $this->gateway->restocks);
    }

    public function testAnInterruptionIsRecordedBeforeTheStockMovesSoAReplayNeverRestocksTwice(): void
    {
        $this->process($this->request());

        // The first stored result says "not confirmed": a crash right after the
        // stock update replays as a reported failure, never as a second restock.
        $this->assertCount(2, $this->gateway->rememberLog);
        $this->assertSame([false, false], array_column($this->gateway->rememberLog[0]['restocked_lines'], 'restocked'));
        $this->assertCount(2, $this->gateway->rememberLog[0]['restock_failed']);
    }

    public function testUnknownProductOrCombinationIsRejectedBeforeAnyStockMoves(): void
    {
        unset($this->gateway->products['4:0']);

        $e = $this->expectRejection(404, fn () => $this->process($this->request()));

        $this->assertArrayHasKey('lines.1.id_product', $e->getDetails());
        $this->assertSame([], $this->gateway->restocks);
        $this->assertSame([], $this->gateway->requests);
    }

    public function testInvalidRequestsAreRejected(): void
    {
        $this->expectRejection(422, fn () => $this->process($this->request(['request_id' => 'short'])));
        $this->expectRejection(422, fn () => $this->process($this->request(['lines' => []])));
        $this->expectRejection(422, fn () => $this->process($this->request(['lines' => [['id_product' => 3, 'id_product_attribute' => 7, 'quantity' => 0]]])));
        $this->expectRejection(422, fn () => $this->process($this->request(['lines' => [['id_product' => '3', 'quantity' => 1]]])));
        $this->assertSame([], $this->gateway->restocks);
    }

    public function testAFailedLineIsReportedAndTheOthersStillRestock(): void
    {
        $this->gateway->failRestockFor = '3:7';

        $result = $this->process($this->request());

        $this->assertSame([[4, 0, 1, 1]], $this->gateway->restocks);
        $this->assertSame([false, true], array_column($result['restocked_lines'], 'restocked'));
        $this->assertSame([['id_product' => 3, 'id_product_attribute' => 7, 'quantity' => 2, 'error' => 'stock locked']], $result['restock_failed']);
    }

    public function testABusyRequestLockIs409(): void
    {
        $this->gateway->lockAvailable = false;

        $this->expectRejection(409, fn () => $this->process($this->request()));

        $this->assertSame([], $this->gateway->restocks);
        $this->assertStringContainsString(md5('noticket-0001-abcd'), $this->gateway->locks[0]);
    }
}
