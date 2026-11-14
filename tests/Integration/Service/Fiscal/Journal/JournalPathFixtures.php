<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use VenPosTPV\Service\Cart\CartService;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\TerminalService;
use VenPosTPV\Service\Checkout\CheckoutService;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\PrestaShop\CouponCreationService;
use VenPosTPV\Service\Fiscal\FiscalRecorder;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIntents;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Refund\OrderSearchService;
use VenPosTPV\Service\Refund\PsRefundClient;
use VenPosTPV\Service\Refund\RefundService;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Service\Sync\Outbox;
use VenPosTPV\Service\Sync\OutboxSyncTrigger;

/**
 * A till, its open session and one product, stored as the issuing paths expect
 * them, plus the services those paths run through. Only the test database is
 * touched; fiscal_record is emptied with DDL because the triggers refuse DELETE.
 */
final class JournalPathFixtures
{
    public const TERMINAL = 94701;
    public const LETTER = 'J';
    public const SESSION = 94701;
    public const PRODUCT = 947001;
    public const PRICE = 12.10;
    public const NIF = 'B12345674';
    /** PrestaShop ids used by these tests (coupon sales, uploaded sales, legacy refunds). */
    public const PS_ORDER_FIRST = 947100;
    public const PS_ORDER_LAST = 947199;

    /** @param \Closure(class-string): \PHPUnit\Framework\MockObject\MockObject $mock the test's createMock */
    public function __construct(private readonly Connection $connection, private readonly \Closure $mock)
    {
    }

    public function reset(): void
    {
        $c = $this->connection;
        $c->executeStatement('DROP TRIGGER IF EXISTS fiscal_record_no_update');
        $c->executeStatement('DROP TRIGGER IF EXISTS fiscal_record_no_delete');
        $c->executeStatement('TRUNCATE TABLE fiscal_record');
        $c->executeStatement('UPDATE fiscal_chain SET last_record_id = NULL, last_hash = NULL WHERE id = 1');
        $orders = array_map('intval', $c->fetchFirstColumn('SELECT id FROM order_meta WHERE terminal_id = ?', [self::TERMINAL]));
        $refunds = array_map('intval', $c->fetchFirstColumn('SELECT id FROM refund WHERE terminal_id = ?', [self::TERMINAL]));
        foreach ($orders as $id) {
            $c->executeStatement('DELETE FROM outbox WHERE order_id = ?', [$id]);
            $c->executeStatement('DELETE FROM sale_line WHERE order_id = ?', [$id]);
            $c->executeStatement('DELETE FROM transfer_payment WHERE order_id = ?', [$id]);
        }
        foreach ($refunds as $id) {
            $c->executeStatement('DELETE FROM refund_line WHERE refund_id = ?', [$id]);
            $c->executeStatement('DELETE FROM exchange WHERE refund_id = ?', [$id]);
            $request = $c->fetchOne('SELECT request_id FROM refund WHERE id = ?', [$id]);
            if (\is_string($request)) {
                $c->executeStatement('DELETE FROM outbox WHERE request_id = ?', [$request]);
            }
        }
        $c->executeStatement('DELETE FROM refund WHERE terminal_id = ?', [self::TERMINAL]);
        $c->executeStatement('DELETE FROM credit_note_sequence');
        $c->executeStatement('DELETE FROM movement WHERE session_id = ?', [self::SESSION]);
        $c->executeStatement('DELETE FROM order_meta WHERE terminal_id = ?', [self::TERMINAL]);
        $c->executeStatement('DELETE FROM session WHERE id = ?', [self::SESSION]);
        $c->executeStatement('DELETE FROM ticket_sequence WHERE letter = ?', [self::LETTER]);
        $c->executeStatement('DELETE FROM terminal WHERE id = ?', [self::TERMINAL]);
        $c->executeStatement('DELETE FROM catalog_stock WHERE id_product = ?', [self::PRODUCT]);
        $c->executeStatement('DELETE FROM catalog_product WHERE id = ?', [self::PRODUCT]);
        $_SESSION = [];
    }

    public function install(): void
    {
        $c = $this->connection;
        $c->insert('terminal', ['id' => self::TERMINAL, 'ps_shop_id' => 1, 'name' => 'Journal', 'ticket_letter' => self::LETTER, 'tax_id' => self::NIF, 'active' => 1, 'refund_window_days' => 30]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->insert('session', ['id' => self::SESSION, 'terminal_id' => self::TERMINAL, 'user_id' => 1, 'opened_at' => '2026-11-03 09:00:00', 'opening_cash' => 100]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $c->insert('catalog_product', ['id' => self::PRODUCT, 'name' => 'Camiseta', 'price' => 10.0, 'price_ttc' => self::PRICE, 'price_net' => 10.0]);
        $c->insert('catalog_stock', ['id_product' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 50]);
        $_SESSION = ['shop_id' => 1, 'cash_session_id' => self::SESSION, 'user_id' => 1, 'terminal_id' => self::TERMINAL];
    }

    /** @return array<string, mixed> */
    public function cart(int $qty = 1): array
    {
        return ['lines' => [['product_id' => self::PRODUCT, 'id_product_attribute' => 0, 'qty' => $qty, 'price' => self::PRICE, 'name' => 'Camiseta']], 'total' => round(self::PRICE * $qty, 2)];
    }

    public function recorder(?FiscalJournalIntents $intents = null): FiscalRecorder
    {
        return new FiscalRecorder($this->connection, journal: $intents);
    }

    public function cash(): CashSessionService
    {
        return new CashSessionService($this->connection, ($this->mock)(TerminalService::class), new RequestStack());
    }

    public function checkout(?FiscalRecorder $recorder = null, ?PsOrderClient $client = null, bool $coupons = false): CheckoutService
    {
                $bus = ($this->mock)(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        return new CheckoutService(
            ($this->mock)(CartService::class),
            $client ?? ($this->mock)(PsOrderClient::class),
            $this->connection,
            $this->cash(),
            $bus,
            ($this->mock)(PrinterService::class),
            $coupons,
            null,
            new TicketNumberer($this->connection),
            new Outbox($this->connection),
            new OutboxSyncTrigger(),
            fiscalRecorder: $recorder,
        );
    }

    public function refunds(?FiscalRecorder $recorder = null, ?PsRefundClient $client = null, ?FiscalJournalIntents $intents = null): RefundService
    {
        
        return new RefundService(
            new OrderSearchService($this->connection),
            $client ?? ($this->mock)(PsRefundClient::class),
            $this->connection,
            $this->cash(),
            null,
            null,
            ($this->mock)(CouponCreationService::class),
            null,
            new Outbox($this->connection),
            null,
            $recorder,
            $intents,
        );
    }

    /** A refund client that answers every credit slip with id 77 and nothing restocked. */
    public function slipClient(): PsRefundClient
    {
        $client = ($this->mock)(PsRefundClient::class);
        $client->method('createRefund')->willReturnCallback(static fn (array $payload): array => [
            'order_slip_id' => 77,
            'refunded_amount' => 0.0,
            'refunded_lines' => [],
            'restock_failed' => [],
            'replayed' => false,
        ]);

        return $client;
    }

    /**
     * A refund still pending from before local-first refunds (no `local_first` in its
     * payload): retrying it goes through PrestaShop and RefundService::complete().
     *
     * @return int the refund id
     */
    public function legacyPendingRefund(?int $psOrderId, float $cash): int
    {
        $payload = $psOrderId !== null
            ? ['ps_order_id' => $psOrderId, 'request_id' => bin2hex(random_bytes(8)), 'lines' => []]
            : ['kind' => 'no_ticket', 'request_id' => bin2hex(random_bytes(8)), 'lines' => []];
        $this->connection->insert('refund', [
            'request_id' => $payload['request_id'],
            'status' => 'pending',
            'ps_order_id' => $psOrderId,
            'terminal_id' => self::TERMINAL,
            'session_id' => self::SESSION,
            'user_id' => 1,
            'reason_code' => 'size',
            'total' => $cash,
            'cash_amount' => $cash,
            'ps_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function refundMovements(): array
    {
        return $this->connection->fetchAllAssociative("SELECT * FROM movement WHERE session_id = ? AND type = 'refund' ORDER BY id", [self::SESSION]);
    }
}
