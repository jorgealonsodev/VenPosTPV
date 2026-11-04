<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Refund;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\Exception\NoOpenCashSessionException;
use VenPosTPV\Service\Catalog\CatalogPriceResolver;
use VenPosTPV\Service\Refund\CreditNoteNumberer;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\OrderSearchService;
use VenPosTPV\Service\Refund\PsRefundClient;
use VenPosTPV\Service\Refund\RefundService;

final class RefundServiceTest extends TestCase
{
    public function testRefundCompletingInASessionClosedMeanwhileIsA409AndBooksNothing(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('transactional')->willReturnCallback(static fn (callable $callback): mixed => $callback($conn));
        $conn->method('lastInsertId')->willReturn('41');
        $conn->expects($this->never())->method('update');
        $conn->method('insert')->willReturnCallback(function (string $table): int {
            $this->assertNotSame('movement', $table, 'no cash movement in a closed session');

            return 1;
        });

        $cash = $this->createMock(CashSessionService::class);
        $cash->method('requireOpenSession')->willReturn(['session_id' => 7, 'terminal_id' => 1, 'user_id' => 1]);
        $cash->expects($this->once())->method('lockOpenSessionInTransaction')->with(7)->willThrowException(new NoOpenCashSessionException());

        $prices = $this->createMock(CatalogPriceResolver::class);
        $prices->method('unitPrice')->willReturn(10.0);

        $service = new RefundService(
            $this->createMock(OrderSearchService::class),
            $this->createMock(PsRefundClient::class),
            $conn,
            $cash,
            $this->createMock(CreditNoteNumberer::class),
            null,
            null,
            $prices,
        );

        try {
            $service->processNoTicketRefund(1, 1, [['product_id' => 3, 'quantity' => 1, 'restock' => false]], 'cash', 'size');
            $this->fail('A refund must not complete in a closed session');
        } catch (RefundException $e) {
            $this->assertSame(409, $e->getCode());
        }
    }
}
