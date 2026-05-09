<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Refund;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Refund\OrderSearchService;

final class OrderSearchServiceTest extends TestCase
{
    private function createService(array $fetchAllResult = [], ?array $fetchOneResult = null): OrderSearchService
    {
        $conn = $this->createMock(Connection::class);

        if ($fetchOneResult !== null) {
            $conn->method('fetchAssociative')->willReturn($fetchOneResult);
        } else {
            $conn->method('fetchAllAssociative')->willReturn($fetchAllResult);
            $conn->method('fetchAssociative')->willReturn(false);
        }

        return new OrderSearchService($conn);
    }

    public function testSearchByPsOrderId(): void
    {
        $service = $this->createService([
            [
                'ps_order_id' => 42,
                'terminal_id' => 1,
                'session_id' => 1,
                'user_id' => 1,
                'payment_breakdown' => json_encode([
                    'reference' => 'ORD-042',
                    'total' => 29.90,
                    'payment_method' => 'cash',
                    'lines' => [['name' => 'Product A', 'qty' => 1, 'price' => 29.90, 'line_total' => 29.90]],
                ]),
                'refunded_amount' => 0.00,
                'created_at' => '2026-01-15 10:00:00',
            ],
        ]);

        $results = $service->search(['ps_order_id' => 42]);

        $this->assertCount(1, $results);
        $this->assertSame(42, $results[0]['ps_order_id']);
        $this->assertSame('ORD-042', $results[0]['reference']);
        $this->assertSame(29.90, $results[0]['total']);
        $this->assertSame('cash', $results[0]['payment_method']);
    }

    public function testSearchByReference(): void
    {
        $service = $this->createService([
            [
                'ps_order_id' => 55,
                'terminal_id' => 1,
                'session_id' => 2,
                'user_id' => 1,
                'payment_breakdown' => json_encode([
                    'reference' => 'ABC-123',
                    'total' => 15.00,
                    'payment_method' => 'card',
                    'lines' => [],
                ]),
                'refunded_amount' => 5.00,
                'created_at' => '2026-01-20 14:30:00',
            ],
        ]);

        $results = $service->search(['reference' => 'ABC-123']);

        $this->assertCount(1, $results);
        $this->assertSame(55, $results[0]['ps_order_id']);
        $this->assertSame('ABC-123', $results[0]['reference']);
        $this->assertSame(5.00, $results[0]['refunded_amount']);
    }

    public function testSearchByDateRange(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->stringContains('created_at >= ?'),
                $this->equalTo(['2026-01-01 00:00:00', '2026-01-31 23:59:59'])
            )
            ->willReturn([]);

        $service = new OrderSearchService($conn);
        $results = $service->search(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);

        $this->assertSame([], $results);
    }

    public function testGetOrderReturnsHydratedData(): void
    {
        $service = $this->createService([], [
            'ps_order_id' => 77,
            'terminal_id' => 1,
            'session_id' => 3,
            'user_id' => 2,
            'payment_breakdown' => json_encode([
                'reference' => 'ORD-077',
                'total' => 100.00,
                'payment_method' => 'mixed',
                'amount_tendered' => 60.00,
                'change_due' => 0.00,
                'lines' => [],
            ]),
            'refunded_amount' => 0.00,
            'created_at' => '2026-02-01 09:00:00',
        ]);

        $order = $service->getOrder(77);

        $this->assertNotNull($order);
        $this->assertSame(77, $order['ps_order_id']);
        $this->assertSame('ORD-077', $order['reference']);
        $this->assertSame(100.00, $order['total']);
        $this->assertSame('mixed', $order['payment_method']);
        $this->assertSame(60.00, $order['amount_tendered']);
    }

    public function testGetOrderReturnsNullForMissingOrder(): void
    {
        $service = $this->createService();

        $order = $service->getOrder(999);

        $this->assertNull($order);
    }

    public function testSearchReturnsEmptyArrayWhenNoMatches(): void
    {
        $service = $this->createService([]);

        $results = $service->search(['ps_order_id' => 999]);

        $this->assertSame([], $results);
    }

    public function testSearchWithHasRefundFilter(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->stringContains('refunded_amount > 0'),
                $this->equalTo([])
            )
            ->willReturn([
                [
                    'ps_order_id' => 10,
                    'terminal_id' => 1,
                    'session_id' => 1,
                    'user_id' => 1,
                    'payment_breakdown' => json_encode([
                        'reference' => 'REF-010',
                        'total' => 20.00,
                        'payment_method' => 'cash',
                        'lines' => [],
                    ]),
                    'refunded_amount' => 20.00,
                    'created_at' => '2026-02-10 10:00:00',
                ],
            ]);

        $service = new OrderSearchService($conn);
        $results = $service->search(['has_refund' => true]);

        $this->assertCount(1, $results);
        $this->assertSame(10, $results[0]['ps_order_id']);
        $this->assertSame(20.00, $results[0]['refunded_amount']);
    }

    public function testSearchWithoutHasRefundFilterOmitsCondition(): void
    {
        $conn = $this->createMock(Connection::class);
        $capturedSql = null;
        $conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql, array $params = [], array $types = []) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [];
            });

        $service = new OrderSearchService($conn);
        $results = $service->search([]);

        $this->assertNotNull($capturedSql);
        $this->assertStringNotContainsString('refunded_amount > 0', (string) $capturedSql);
        $this->assertSame([], $results);
    }
}
