<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Report;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Sale\VatRate;

/**
 * The acceptance data of docs/prd/informes-ventas.md §7: all at 21 % VAT; Camiseta
 * 25,00, Gorro 10,00, Bañador 40,00. Till A, session 1 opened 10/03/2026; till B,
 * session 2 of 10/03 and session 3 of 12/03. Sessions stay open so the tests can
 * close them through CashSessionService.
 */
final class SalesReportFixture
{
    public const TILL_A = 9551;
    public const TILL_B = 9552;
    public const ANA = 9551;
    public const LUIS = 9552;
    public const SESSION_1 = 9551;
    public const SESSION_2 = 9552;
    public const SESSION_3 = 9553;
    public const T1 = 955001;
    public const T2 = 955002;
    public const T3 = 955003;
    public const T4 = 955004;
    public const C1_SALE = 955005;
    /** A sale written before sale_line existed: no lines (missing_lines). */
    public const NO_LINES = 955006;

    public const SHIRT = [955101, 'Camiseta', 25.00];
    public const HAT = [955102, 'Gorro', 10.00];
    public const SWIMSUIT = [955103, 'Bañador', 40.00];
    /** In the catalog at 10 % VAT, never sold: only refunded without ticket. */
    public const BOOK = [955104, 'Libro', 11.00];
    /** Active in the catalog and never sold («Sin ventas»). */
    public const GOGGLES = [955105, 'Gafas', 15.00];
    public const CLOTHES = 9551;
    public const ACCESSORIES = 9552;
    public const BOOKS = 9553;
    /** First id of the extra sales the detail tests add (addSale). */
    public const EXTRA_SALE = 955010;

    public function __construct(private readonly Connection $c)
    {
    }

    public function load(): void
    {
        $c = $this->c;
        $this->clean();
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->insert('terminal', ['id' => self::TILL_A, 'ps_shop_id' => 1, 'name' => 'Caja A', 'store_address' => '']);
        $c->insert('terminal', ['id' => self::TILL_B, 'ps_shop_id' => 1, 'name' => 'Caja B', 'store_address' => '']);
        $c->insert('user', ['id' => self::ANA, 'role_id' => 1, 'name' => 'Ana', 'pin_hash' => 'x', 'active' => 1]);
        $c->insert('user', ['id' => self::LUIS, 'role_id' => 1, 'name' => 'Luis', 'pin_hash' => 'x', 'active' => 1]);
        $c->insert('session', ['id' => self::SESSION_1, 'terminal_id' => self::TILL_A, 'user_id' => self::ANA, 'opened_at' => '2026-03-10 08:00:00', 'opening_cash' => 50]);
        $c->insert('session', ['id' => self::SESSION_2, 'terminal_id' => self::TILL_B, 'user_id' => self::LUIS, 'opened_at' => '2026-03-10 08:00:00', 'opening_cash' => 50]);
        $c->insert('session', ['id' => self::SESSION_3, 'terminal_id' => self::TILL_B, 'user_id' => self::LUIS, 'opened_at' => '2026-03-12 08:00:00', 'opening_cash' => 50]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        // T1 10/03 09:15, A / Ana: 2 Camiseta + 1 Gorro, cash 60,00.
        $this->sale(self::T1, '2026-03-10 09:15:00', self::TILL_A, self::SESSION_1, self::ANA, 'cash', ['cash' => 60.0, 'card' => 0.0],
            [[self::SHIRT, 2, 50.00], [self::HAT, 1, 10.00]]);
        // T2 10/03 18:40, B / Luis: 1 Bañador with 10 % line discount → 36,00, card.
        $this->sale(self::T2, '2026-03-10 18:40:00', self::TILL_B, self::SESSION_2, self::LUIS, 'card', ['cash' => 0.0, 'card' => 36.0],
            [[self::SWIMSUIT, 1, 36.00]]);
        // T3 10/03 23:59:30, A / Ana: 1 Gorro, mixed 4,00 cash + 6,00 card.
        $this->sale(self::T3, '2026-03-10 23:59:30', self::TILL_A, self::SESSION_1, self::ANA, 'mixed', ['cash' => 4.0, 'card' => 6.0],
            [[self::HAT, 1, 10.00]]);
        // T4 11/03 00:00:10, A / Ana: 1 Camiseta, cash 25,00.
        $this->sale(self::T4, '2026-03-11 00:00:10', self::TILL_A, self::SESSION_1, self::ANA, 'cash', ['cash' => 25.0, 'card' => 0.0],
            [[self::SHIRT, 1, 25.00]]);

        // D1 11/03 10:00, A / Ana: returns 1 Camiseta of T1, cash 25,00.
        $this->refund('rpt955-d1', self::T1, '2026-03-11 10:00:00', self::TILL_A, self::SESSION_1, self::ANA, 25.0, 25.0, null, self::SHIRT, 0);
        $c->insert('movement', ['session_id' => self::SESSION_1, 'type' => 'refund', 'amount' => 25.0, 'order_id' => self::T1, 'created_at' => '2026-03-11 10:00:00']);

        // C1 12/03 11:00, B / Luis: returns the Bañador of T2 (credit 36,00) and takes
        // another Bañador of 40,00: credit 36,00 + card 4,00.
        $c1 = $this->refund('rpt955-c1', self::T2, '2026-03-12 11:00:00', self::TILL_B, self::SESSION_3, self::LUIS, 36.0, 0.0, 36.0, self::SWIMSUIT, 0);
        $this->sale(self::C1_SALE, '2026-03-12 11:00:00', self::TILL_B, self::SESSION_3, self::LUIS, 'card', ['cash' => 0.0, 'card' => 4.0, 'exchange_credit' => 36.0],
            [[self::SWIMSUIT, 1, 40.00]]);
        $c->insert('exchange', ['status' => 'done', 'order_id' => self::T2, 'refund_id' => $c1, 'user_id' => self::LUIS, 'credit' => 36.0, 'credit_applied' => 36.0,
            'new_total' => 40.0, 'balance' => 4.0, 'payment_method' => 'card', 'cart_data' => '{}', 'new_order_id' => self::C1_SALE,
            'created_at' => '2026-03-12 11:00:00', 'completed_at' => '2026-03-12 11:00:00']);
    }

    /** A sale of 10/03 12:00 on till A that has no sale_line rows yet. */
    public function addSaleWithoutLines(): void
    {
        $this->c->insert('order_meta', ['id' => self::NO_LINES, 'request_id' => 'rpt955-' . self::NO_LINES, 'terminal_id' => self::TILL_A, 'session_id' => self::SESSION_1,
            'user_id' => self::ANA, 'payment_breakdown' => json_encode(['total' => 5.0, 'payment_method' => 'card', 'tender' => ['cash' => 0.0, 'card' => 5.0], 'lines' => []]),
            'created_at' => '2026-03-10 12:00:00']);
    }

    /**
     * The catalog of the fixture products and their categories: Camiseta and Bañador
     * in «Ropa», Gorro in «Accesorios», Libro (10 % VAT) in «Libros», Gafas active and
     * never sold, plus an inactive product. The sale lines get the category they had
     * when sold, as SaleLineWriter stores it.
     */
    public function addCatalog(): void
    {
        $c = $this->c;
        foreach ([[self::CLOTHES, 'Ropa'], [self::ACCESSORIES, 'Accesorios'], [self::BOOKS, 'Libros']] as [$id, $name]) {
            $c->insert('catalog_category', ['id' => $id, 'name' => $name, 'id_parent' => 2, 'active' => 1]);
        }
        $products = [
            [self::SHIRT, 'CAM-01', self::CLOTHES, 20.66, 1],
            [self::HAT, 'GOR-01', self::ACCESSORIES, 8.26, 1],
            [self::SWIMSUIT, 'BAN-01', self::CLOTHES, 33.06, 1],
            [self::BOOK, 'LIB-01', self::BOOKS, 10.00, 1],
            [self::GOGGLES, 'GAF-01', self::ACCESSORIES, 12.40, 1],
            [[955106, 'Antiguo', 5.00], 'ANT-01', self::ACCESSORIES, 4.13, 0],
        ];
        foreach ($products as [[$id, $name, $price], $reference, $category, $net, $active]) {
            $c->insert('catalog_product', ['id' => $id, 'name' => $name, 'reference' => $reference, 'price' => $net, 'price_ttc' => $price,
                'price_net' => $net, 'id_category_default' => $category, 'active' => $active]);
        }
        $categories = [self::SHIRT[0] => [self::CLOTHES, 'Ropa', 'CAM-01'], self::HAT[0] => [self::ACCESSORIES, 'Accesorios', 'GOR-01'],
            self::SWIMSUIT[0] => [self::CLOTHES, 'Ropa', 'BAN-01']];
        foreach ($categories as $productId => [$category, $name, $reference]) {
            $c->executeStatement('UPDATE sale_line SET category_id = ?, category_name = ?, reference = ? WHERE product_id = ? AND order_id BETWEEN 955001 AND 955099',
                [$category, $name, $reference, $productId]);
        }
    }

    /**
     * A sale on till A, session 1, by Ana, paid in cash, at 21 % VAT. A line with a
     * null product id is a free item.
     *
     * @param list<array{0: array{0: ?int, 1: string, 2: float}, 1: int, 2: float}> $lines product, quantity, paid
     */
    public function addSale(int $id, string $at, array $lines): void
    {
        $paid = array_sum(array_map(static fn (array $line): float => $line[2], $lines));
        $this->sale($id, $at, self::TILL_A, self::SESSION_1, self::ANA, 'cash', ['cash' => round($paid, 2), 'card' => 0.0], $lines);
    }

    /**
     * A refund without ticket (order_id NULL), paid back in cash or with a voucher.
     *
     * @param array{0: int, 1: string, 2: float} $product
     */
    public function addRefundWithoutTicket(string $requestId, string $at, int $till, int $session, int $user, array $product, float $amount, bool $voucher = false): int
    {
        $this->c->insert('refund', ['request_id' => $requestId, 'status' => 'done', 'order_id' => null, 'terminal_id' => $till, 'session_id' => $session,
            'user_id' => $user, 'reason_code' => 'size', 'total' => $amount, 'cash_amount' => $voucher ? 0 : $amount, 'card_amount' => 0,
            'voucher_amount' => $voucher ? $amount : null, 'created_at' => $at, 'completed_at' => $at]);
        $id = (int) $this->c->lastInsertId();
        $this->c->insert('refund_line', ['refund_id' => $id, 'line_index' => null, 'product_id' => $product[0], 'id_product_attribute' => 0,
            'name' => $product[1], 'quantity' => 1, 'amount' => $amount]);
        if (!$voucher) {
            $this->c->insert('movement', ['session_id' => $session, 'type' => 'refund', 'amount' => $amount, 'created_at' => $at]);
        }

        return $id;
    }

    public function clean(): void
    {
        $c = $this->c;
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->executeStatement('DELETE FROM catalog_product WHERE id BETWEEN 955101 AND 955199');
        $c->executeStatement('DELETE FROM catalog_category WHERE id IN (?, ?, ?)', [self::CLOTHES, self::ACCESSORIES, self::BOOKS]);
        $refunds = $c->fetchFirstColumn("SELECT id FROM refund WHERE request_id LIKE 'rpt955-%'");
        foreach ($refunds as $id) {
            $c->executeStatement('DELETE FROM exchange WHERE refund_id = ?', [(int) $id]);
            $c->executeStatement('DELETE FROM refund_line WHERE refund_id = ?', [(int) $id]);
        }
        $c->executeStatement("DELETE FROM refund WHERE request_id LIKE 'rpt955-%'");
        $c->executeStatement("DELETE FROM outbox WHERE request_id LIKE 'rpt955-%'");
        $c->executeStatement('DELETE FROM sale_line WHERE order_id BETWEEN 955001 AND 955099');
        $c->executeStatement('DELETE FROM order_meta WHERE id BETWEEN 955001 AND 955099');
        $c->executeStatement('DELETE FROM movement WHERE session_id IN (?, ?, ?)', [self::SESSION_1, self::SESSION_2, self::SESSION_3]);
        $c->executeStatement('DELETE FROM z_report WHERE session_id IN (?, ?, ?)', [self::SESSION_1, self::SESSION_2, self::SESSION_3]);
        $c->executeStatement('DELETE FROM session WHERE id IN (?, ?, ?)', [self::SESSION_1, self::SESSION_2, self::SESSION_3]);
        $c->executeStatement('DELETE FROM terminal WHERE id IN (?, ?)', [self::TILL_A, self::TILL_B]);
        $c->executeStatement('DELETE FROM user WHERE id IN (?, ?)', [self::ANA, self::LUIS]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @param array{cash: float, card: float, exchange_credit?: float} $tender
     * @param list<array{0: array{0: ?int, 1: string, 2: float}, 1: int, 2: float}> $lines product, quantity, paid
     */
    private function sale(int $id, string $at, int $till, int $session, int $user, string $method, array $tender, array $lines): void
    {
        $jsonLines = [];
        $total = 0.0;
        foreach ($lines as [[$productId, $name, $price], $qty, $paid]) {
            $jsonLines[] = ['name' => $name, 'product_id' => $productId, 'id_product_attribute' => 0, 'qty' => $qty, 'price' => $price,
                'line_total' => round($qty * $price, 2), 'paid_total' => $paid];
            $total += $paid;
        }
        $this->c->insert('order_meta', ['id' => $id, 'request_id' => 'rpt955-' . $id, 'terminal_id' => $till, 'session_id' => $session, 'user_id' => $user,
            'payment_breakdown' => json_encode(['total' => round($total, 2), 'payment_method' => $method, 'tender' => $tender, 'lines' => $jsonLines]),
            'created_at' => $at]);
        foreach ($jsonLines as $index => $line) {
            $split = VatRate::split($line['paid_total'], 21.0);
            $this->c->insert('sale_line', ['order_id' => $id, 'line_index' => $index, 'sold_at' => $at, 'terminal_id' => $till, 'session_id' => $session,
                'user_id' => $user, 'product_id' => $line['product_id'], 'id_product_attribute' => 0, 'name' => $line['name'], 'quantity' => $line['qty'],
                'unit_price' => $line['price'], 'gross_amount' => $line['line_total'], 'paid_amount' => $line['paid_total'], 'vat_rate' => 21.0,
                'base_amount' => $split['base'], 'vat_amount' => $split['vat'], 'approximate' => 0]);
        }
        if ($tender['cash'] > 0) {
            $this->c->insert('movement', ['session_id' => $session, 'type' => 'sale', 'amount' => $tender['cash'], 'order_id' => $id, 'created_at' => $at]);
        }
    }

    /** @param array{0: int, 1: string, 2: float} $product */
    private function refund(string $requestId, int $orderId, string $at, int $till, int $session, int $user, float $total, float $cash, ?float $credit, array $product, int $lineIndex): int
    {
        $this->c->insert('refund', ['request_id' => $requestId, 'status' => 'done', 'order_id' => $orderId, 'terminal_id' => $till, 'session_id' => $session,
            'user_id' => $user, 'reason_code' => 'size', 'total' => $total, 'cash_amount' => $cash, 'card_amount' => 0, 'exchange_credit' => $credit,
            'created_at' => $at, 'completed_at' => $at]);
        $id = (int) $this->c->lastInsertId();
        $this->c->insert('refund_line', ['refund_id' => $id, 'line_index' => $lineIndex, 'product_id' => $product[0], 'id_product_attribute' => 0,
            'name' => $product[1], 'quantity' => 1, 'amount' => $total]);

        return $id;
    }
}
