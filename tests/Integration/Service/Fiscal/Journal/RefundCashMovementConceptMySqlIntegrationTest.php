<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * The cash movement of a refund, as each path books it. Pinned before the concept
 * rule was moved into RefundService::cashMovementConcept(), so both callers keep
 * producing exactly the same rows.
 */
final class RefundCashMovementConceptMySqlIntegrationTest extends TestCase
{
    private ?Connection $connection = null;

    private JournalPathFixtures $fixtures;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->fixtures = new JournalPathFixtures($this->connection, fn (string $class) => $this->createMock($class));
        $this->fixtures->reset();
        $this->fixtures->install();
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->fixtures->reset();
            $this->connection->close();
        }
        $this->connection = null;
    }

    /** @return array{concept: string, order_id: ?int, ps_order_id: ?int, ps_order_slip_id: ?int, amount: string} */
    private function onlyMovement(): array
    {
        $rows = $this->fixtures->refundMovements();
        $this->assertCount(1, $rows);
        $row = $rows[0];

        return [
            'concept' => (string) $row['concept'],
            'order_id' => $row['order_id'] !== null ? (int) $row['order_id'] : null,
            'ps_order_id' => $row['ps_order_id'] !== null ? (int) $row['ps_order_id'] : null,
            'ps_order_slip_id' => $row['ps_order_slip_id'] !== null ? (int) $row['ps_order_slip_id'] : null,
            'amount' => (string) $row['amount'],
        ];
    }

    private function sell(): array
    {
        return $this->fixtures->checkout()->createOrder(1, $this->fixtures->cart() + ['cash_amount' => JournalPathFixtures::PRICE], 'cash', JournalPathFixtures::PRICE);
    }

    public function testRefundWithoutTicketCompletedLocally(): void
    {
        $this->fixtures->refunds()->processNoTicketRefund(1, 1, [['product_id' => JournalPathFixtures::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 1, 'restock' => false]], 'cash', 'size');

        $this->assertSame(['concept' => 'Devolución sin ticket', 'order_id' => null, 'ps_order_id' => null, 'ps_order_slip_id' => null, 'amount' => '12.10'], $this->onlyMovement());
    }

    public function testRefundOfATicketNotUploadedYet(): void
    {
        $sale = $this->sell();

        $this->fixtures->refunds()->processSaleRefund(1, $sale['order_id'], null, 'size');

        $this->assertSame(['concept' => 'Devolución ticket ' . $sale['reference'], 'order_id' => $sale['order_id'], 'ps_order_id' => null, 'ps_order_slip_id' => null, 'amount' => '12.10'], $this->onlyMovement());
    }

    public function testRefundOfAnUploadedTicket(): void
    {
        $sale = $this->sell();
        $this->connection->update('order_meta', ['ps_order_id' => 947150], ['id' => $sale['order_id']]);

        $this->fixtures->refunds()->processSaleRefund(1, $sale['order_id'], null, 'size');

        $this->assertSame(['concept' => 'Devolución pedido #947150', 'order_id' => $sale['order_id'], 'ps_order_id' => 947150, 'ps_order_slip_id' => null, 'amount' => '12.10'], $this->onlyMovement());
    }

    public function testLegacyPendingRefundOfAnOrderCompletedThroughPrestaShop(): void
    {
        $refundId = $this->fixtures->legacyPendingRefund(947160, 10.0);

        $this->fixtures->refunds(client: $this->fixtures->slipClient())->retryPending(1, $refundId);

        $this->assertSame(['concept' => 'Devolución pedido #947160', 'order_id' => null, 'ps_order_id' => 947160, 'ps_order_slip_id' => 77, 'amount' => '10.00'], $this->onlyMovement());
    }

    public function testLegacyPendingRefundWithoutTicketCompletedThroughPrestaShop(): void
    {
        $refundId = $this->fixtures->legacyPendingRefund(null, 8.0);

        $this->fixtures->refunds()->retryPending(1, $refundId);

        $this->assertSame(['concept' => 'Devolución sin ticket', 'order_id' => null, 'ps_order_id' => null, 'ps_order_slip_id' => null, 'amount' => '8.00'], $this->onlyMovement());
    }
}
