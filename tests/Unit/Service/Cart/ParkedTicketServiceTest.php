<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Cart;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Cart\ParkedTicketService;

class ParkedTicketServiceTest extends TestCase
{
    public function testListParkedTicketsReturnsHydratedRows(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('FROM parked_ticket'))
            ->willReturn([
                [
                    'id' => 10,
                    'client_ref' => 'parked_1710000000_abcd',
                    'terminal_id' => 1,
                    'user_id' => 2,
                    'cashier_name' => 'María García',
                    'note' => 'Probador 2',
                    'customer' => json_encode(['id' => 5, 'name' => 'Ana López'], JSON_THROW_ON_ERROR),
                    'lines' => json_encode([
                        ['id' => 'l1', 'product_id' => 7, 'name' => 'Abrigo Gris', 'price' => 50.0, 'qty' => 2, 'discount' => 0],
                    ], JSON_THROW_ON_ERROR),
                    'discount' => json_encode(['type' => 'pct', 'value' => 10], JSON_THROW_ON_ERROR),
                    'total' => '90.00',
                    'created_at' => '2026-11-01 20:00:00',
                ],
            ]);

        $service = new ParkedTicketService($conn);
        $tickets = $service->list();

        $this->assertCount(1, $tickets);
        $this->assertSame(10, $tickets[0]['id']);
        $this->assertSame('parked_1710000000_abcd', $tickets[0]['client_ref']);
        $this->assertSame('Probador 2', $tickets[0]['note']);
        $this->assertSame('María García', $tickets[0]['cashier_name']);
        $this->assertSame('Ana López', $tickets[0]['customer']['name']);
        $this->assertSame('pct', $tickets[0]['discount']['type']);
        $this->assertSame(10, $tickets[0]['discount']['value']);
        $this->assertSame(90.0, $tickets[0]['total']);
        $this->assertCount(1, $tickets[0]['lines']);
    }

    public function testParkTicketRejectsEmptyLines(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->never())->method('insert');

        $service = new ParkedTicketService($conn);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot park an empty ticket');

        $service->park([
            'lines' => [],
            'user_id' => 1,
        ]);
    }

    public function testParkTicketPersistsAndComputesTotalWithDiscount(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                $this->stringContains('SELECT * FROM parked_ticket WHERE client_ref = ?'),
                ['parked_custom_ref']
            )
            ->willReturn(false);

        $conn->expects($this->once())
            ->method('insert')
            ->with(
                'parked_ticket',
                $this->callback(function (array $data): bool {
                    return $data['client_ref'] === 'parked_custom_ref'
                        && $data['note'] === 'Señora abrigo gris'
                        && $data['cashier_name'] === 'Carlos'
                        && (float) $data['total'] === 80.0;
                })
            );
        $conn->expects($this->once())->method('lastInsertId')->willReturn('42');

        $service = new ParkedTicketService($conn);
        $created = $service->park([
            'client_ref' => 'parked_custom_ref',
            'terminal_id' => 1,
            'user_id' => 3,
            'cashier_name' => 'Carlos',
            'note' => '  Señora abrigo gris  ',
            'customer' => ['name' => 'Consumidor final', 'default' => true],
            'lines' => [
                ['id' => 'l1', 'product_id' => 12, 'name' => 'Abrigo', 'price' => 100.0, 'qty' => 1, 'discount' => 0],
            ],
            'discount' => ['type' => 'pct', 'value' => 20],
        ]);

        $this->assertSame(42, $created['id']);
        $this->assertSame('parked_custom_ref', $created['client_ref']);
        $this->assertSame('Señora abrigo gris', $created['note']);
        $this->assertSame(80.0, $created['total']);
        $this->assertSame('pct', $created['discount']['type']);
    }

    public function testParkTicketIsIdempotentOnExistingClientRef(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 15,
                'client_ref' => 'parked_dup',
                'terminal_id' => 1,
                'user_id' => 2,
                'cashier_name' => 'María',
                'note' => 'Mesa 1',
                'customer' => json_encode(['name' => 'Consumidor final', 'default' => true], JSON_THROW_ON_ERROR),
                'lines' => json_encode([['id' => 'l1', 'product_id' => 1, 'name' => 'Camisa', 'price' => 25.0, 'qty' => 1]], JSON_THROW_ON_ERROR),
                'discount' => null,
                'total' => '25.00',
                'created_at' => '2026-11-01 20:05:00',
            ]);
        $conn->expects($this->never())->method('insert');

        $service = new ParkedTicketService($conn);
        $result = $service->park([
            'client_ref' => 'parked_dup',
            'lines' => [['id' => 'l1', 'product_id' => 1, 'name' => 'Camisa', 'price' => 25.0, 'qty' => 1]],
        ]);

        $this->assertSame(15, $result['id']);
        $this->assertSame('parked_dup', $result['client_ref']);
    }

    public function testDeleteParkedTicketByNumericIdOrClientRef(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnOnConsecutiveCalls(1, 1);

        $service = new ParkedTicketService($conn);

        $this->assertTrue($service->delete('10'));
        $this->assertTrue($service->delete('parked_ref_1'));
    }

    public function testClearAllRemovesAllParkedTickets(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with('DELETE FROM parked_ticket')
            ->willReturn(3);

        $service = new ParkedTicketService($conn);
        $this->assertSame(3, $service->clearAll());
    }
}
