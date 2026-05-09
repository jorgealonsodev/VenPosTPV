<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Refund;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\OrderSearchService;
use VenPosTPV\Service\Refund\PsRefundClient;
use VenPosTPV\Service\Refund\RefundService;

final class RefundServiceTest extends TestCase
{
    private function createService(
        ?OrderSearchService $orderSearch = null,
        ?PsRefundClient $client = null,
        ?Connection $conn = null,
        ?CashSessionService $cashSession = null,
    ): RefundService {
        return new RefundService(
            $orderSearch ?? $this->createMock(OrderSearchService::class),
            $client ?? $this->createMock(PsRefundClient::class),
            $conn ?? $this->createMock(Connection::class),
            $cashSession ?? $this->createMock(CashSessionService::class),
        );
    }

    public function testProcessRefundThrows404WhenOrderNotFound(): void
    {
        $orderSearch = $this->createMock(OrderSearchService::class);
        $orderSearch->method('getOrder')->with(99)->willReturn(null);

        $service = $this->createService($orderSearch);

        $this->expectException(RefundException::class);
        $this->expectExceptionMessage('Order 99 not found');

        $service->processRefund(1, 99, null, 'reason');
    }

    public function testProcessRefundThrows409WhenAlreadyFullyRefunded(): void
    {
        $orderSearch = $this->createMock(OrderSearchService::class);
        $orderSearch->method('getOrder')->with(42)->willReturn([
            'ps_order_id' => 42,
            'total' => 50.00,
            'refunded_amount' => 50.00,
            'payment_method' => 'cash',
            'lines' => [],
        ]);

        $service = $this->createService($orderSearch);

        $this->expectException(RefundException::class);
        $this->expectExceptionMessage('Order 42 already fully refunded');

        $service->processRefund(1, 42, null, 'reason');
    }

    public function testProcessRefundThrows422WhenRefundExceedsRemaining(): void
    {
        $orderSearch = $this->createMock(OrderSearchService::class);
        $orderSearch->method('getOrder')->with(42)->willReturn([
            'ps_order_id' => 42,
            'total' => 50.00,
            'refunded_amount' => 30.00,
            'payment_method' => 'cash',
            'lines' => [
                ['product_id' => 1, 'qty' => 1, 'price' => 25.00, 'line_total' => 25.00],
            ],
        ]);

        $service = $this->createService($orderSearch);

        $this->expectException(RefundException::class);
        $this->expectExceptionMessage('Refund amount exceeds remaining refundable amount');

        // Try to refund 25 when only 20 remains
        $service->processRefund(1, 42, [['id_product' => 1, 'quantity' => 1, 'amount' => 25.00]], 'reason');
    }

    public function testProcessRefundFullRefundCallsPsClientAndUpdatesState(): void
    {
        $orderSearch = $this->createMock(OrderSearchService::class);
        $orderSearch->method('getOrder')->with(42)->willReturn([
            'ps_order_id' => 42,
            'total' => 50.00,
            'refunded_amount' => 0.00,
            'payment_method' => 'cash',
            'lines' => [
                ['product_id' => 1, 'qty' => 2, 'price' => 25.00, 'line_total' => 50.00],
            ],
        ]);

        $client = $this->createMock(PsRefundClient::class);
        $client->expects($this->once())
            ->method('createRefund')
            ->willReturn([
                'order_slip_id' => 7,
                'refunded_amount' => 50.00,
                'refunded_lines' => [['id_product' => 1, 'quantity' => 2, 'amount' => 50.00]],
            ]);

        $cashSession = $this->createMock(CashSessionService::class);
        $cashSession->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 1]);
        $cashSession->expects($this->once())
            ->method('addMovement')
            ->with(1, 'refund', -50.00, $this->stringContains('Devolución pedido #42'), 42);

        $conn = $this->createMock(Connection::class);
        $conn->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) {
                if (str_contains($sql, 'catalog_stock')) {
                    $this->assertSame([2, 1], $params);
                }

                return 1;
            });
        $conn->expects($this->once())
            ->method('fetchOne')
            ->willReturn(312);

        $service = $this->createService($orderSearch, $client, $conn, $cashSession);
        $result = $service->processRefund(1, 42, null, 'reason');

        $this->assertSame(7, $result['order_slip_id']);
        $this->assertSame(50.00, $result['refunded_amount']);
        $this->assertSame(312, $result['movement_id']);
        $this->assertFalse($result['saga_error']);
    }

    public function testProcessRefundCardOnlyRecordsZeroCashMovement(): void
    {
        $orderSearch = $this->createMock(OrderSearchService::class);
        $orderSearch->method('getOrder')->with(43)->willReturn([
            'ps_order_id' => 43,
            'total' => 50.00,
            'refunded_amount' => 0.00,
            'payment_method' => 'card',
            'lines' => [
                ['product_id' => 1, 'qty' => 1, 'price' => 50.00, 'line_total' => 50.00],
            ],
        ]);

        $client = $this->createMock(PsRefundClient::class);
        $client->method('createRefund')->willReturn([
            'order_slip_id' => 8,
            'refunded_amount' => 50.00,
            'refunded_lines' => [],
        ]);

        $cashSession = $this->createMock(CashSessionService::class);
        $cashSession->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 1]);
        $cashSession->expects($this->once())
            ->method('addMovement')
            ->with(1, 'refund', 0.0, $this->anything(), 43);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(313);

        $service = $this->createService($orderSearch, $client, $conn, $cashSession);
        $result = $service->processRefund(1, 43, null, 'reason');

        $this->assertSame(8, $result['order_slip_id']);
    }

    public function testProcessRefundPartialRefundOnlySelectedLines(): void
    {
        $orderSearch = $this->createMock(OrderSearchService::class);
        $orderSearch->method('getOrder')->with(42)->willReturn([
            'ps_order_id' => 42,
            'total' => 75.00,
            'refunded_amount' => 0.00,
            'payment_method' => 'cash',
            'lines' => [
                ['product_id' => 1, 'qty' => 1, 'price' => 25.00, 'line_total' => 25.00],
                ['product_id' => 2, 'qty' => 2, 'price' => 25.00, 'line_total' => 50.00],
            ],
        ]);

        $client = $this->createMock(PsRefundClient::class);
        $client->expects($this->once())
            ->method('createRefund')
            ->willReturn([
                'order_slip_id' => 9,
                'refunded_amount' => 25.00,
                'refunded_lines' => [['id_product' => 1, 'quantity' => 1, 'amount' => 25.00]],
            ]);

        $cashSession = $this->createMock(CashSessionService::class);
        $cashSession->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 1]);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(314);

        $service = $this->createService($orderSearch, $client, $conn, $cashSession);
        $result = $service->processRefund(1, 42, [['id_product' => 1, 'quantity' => 1, 'amount' => 25.00]], 'reason');

        $this->assertSame(9, $result['order_slip_id']);
        $this->assertSame(25.00, $result['refunded_amount']);
    }

    public function testProcessRefundSagaErrorOnPartialFailure(): void
    {
        $orderSearch = $this->createMock(OrderSearchService::class);
        $orderSearch->method('getOrder')->with(42)->willReturn([
            'ps_order_id' => 42,
            'total' => 50.00,
            'refunded_amount' => 0.00,
            'payment_method' => 'cash',
            'lines' => [
                ['product_id' => 1, 'qty' => 1, 'price' => 50.00, 'line_total' => 50.00],
            ],
        ]);

        $client = $this->createMock(PsRefundClient::class);
        $client->method('createRefund')->willReturn([
            'order_slip_id' => 7,
            'refunded_amount' => 50.00,
            'refunded_lines' => [],
        ]);

        $cashSession = $this->createMock(CashSessionService::class);
        $cashSession->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 1]);
        $cashSession->method('addMovement')->willThrowException(new \RuntimeException('DB error'));

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())->method('insert'); // audit_log

        $service = $this->createService($orderSearch, $client, $conn, $cashSession);
        $result = $service->processRefund(1, 42, null, 'reason');

        $this->assertTrue($result['saga_error']);
    }
}
