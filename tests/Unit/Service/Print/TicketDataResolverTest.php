<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketVerificationService;

final class TicketDataResolverTest extends TestCase
{
    private function createService(
        ?Connection $conn = null,
        ?TicketVerificationService $verificationService = null,
    ): TicketDataResolver {
        return new TicketDataResolver(
            $conn ?? $this->createMock(Connection::class),
            $verificationService ?? $this->createMock(TicketVerificationService::class),
            'Mi Tienda',
            'Calle Falsa 123',
        );
    }

    public function testResolveReturnsNullWhenOrderNotFound(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(false);

        $service = $this->createService(conn: $conn);
        $result = $service->resolve(999);

        self::assertNull($result);
    }

    public function testResolveAssemblesTicketDataFromDbRows(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')
            ->willReturnMap([
                [
                    'SELECT ps_order_id, user_id, payment_breakdown, created_at FROM order_meta WHERE ps_order_id = ?',
                    [42],
                    [
                        'ps_order_id' => 42,
                        'user_id' => 5,
                        'payment_breakdown' => json_encode([
                            'reference' => 'ORD-042',
                            'total' => 99.99,
                            'payment_method' => 'cash',
                            'amount_tendered' => 100.0,
                            'change_due' => 0.01,
                            'subtotal' => 90.0,
                            'tax' => 9.99,
                            'lines' => [
                                ['name' => 'Café', 'qty' => 2, 'price' => 45.0, 'line_total' => 90.0],
                            ],
                        ]),
                        'created_at' => '2024-01-15 14:30:00',
                    ],
                ],
                [
                    'SELECT name FROM user WHERE id = ?',
                    [5],
                    ['name' => 'Juan'],
                ],
            ]);

        $verificationService = $this->createMock(TicketVerificationService::class);
        $verificationService->method('generateToken')->willReturn('test-token');
        $verificationService->method('buildVerificationUrl')->willReturn('https://example.com/verify?token=test-token');

        $service = $this->createService(conn: $conn, verificationService: $verificationService);
        $result = $service->resolve(42);

        self::assertNotNull($result);
        self::assertSame(42, $result->orderId);
        self::assertSame('ORD-042', $result->reference);
        self::assertSame('Mi Tienda', $result->storeName);
        self::assertSame('Calle Falsa 123', $result->storeAddress);
        self::assertSame('Juan', $result->cashierName);
        self::assertSame(99.99, $result->total);
        self::assertSame(90.0, $result->subtotal);
        self::assertSame(9.99, $result->tax);
        self::assertCount(1, $result->lines);
        self::assertSame('Café', $result->lines[0]->name);
        self::assertSame(2, $result->lines[0]->quantity);
        self::assertSame(45.0, $result->lines[0]->unitPrice);
        self::assertSame(90.0, $result->lines[0]->lineTotal);
    }

    public function testResolveUsesDefaultCashierWhenUserNotFound(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')
            ->willReturnMap([
                [
                    'SELECT ps_order_id, user_id, payment_breakdown, created_at FROM order_meta WHERE ps_order_id = ?',
                    [42],
                    [
                        'ps_order_id' => 42,
                        'user_id' => 99,
                        'payment_breakdown' => json_encode([
                            'reference' => 'ORD-042',
                            'total' => 10.0,
                            'payment_method' => 'card',
                            'amount_tendered' => 10.0,
                            'change_due' => 0.0,
                            'lines' => [],
                        ]),
                        'created_at' => '2024-01-15 14:30:00',
                    ],
                ],
                [
                    'SELECT name FROM user WHERE id = ?',
                    [99],
                    false,
                ],
            ]);

        $verificationService = $this->createMock(TicketVerificationService::class);
        $verificationService->method('generateToken')->willReturn('token');
        $verificationService->method('buildVerificationUrl')->willReturn('https://example.com/verify');

        $service = $this->createService(conn: $conn, verificationService: $verificationService);
        $result = $service->resolve(42);

        self::assertNotNull($result);
        self::assertSame('Cajero', $result->cashierName);
    }
}
