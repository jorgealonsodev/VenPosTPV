<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Invoice;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Fiscal\FiscalRecorder;
use VenPosTPV\Service\Sale\Prorate;
use VenPosTPV\Service\Sale\SaleLineWriter;
use VenPosTPV\Service\Sale\VatRate;

/**
 * Sales, customers and a till for the invoice tests, stored the way checkout stores
 * them (order_meta, sale_line, F2 record). Only the test database is touched.
 */
final class InvoiceFixtures
{
    public const TILL = 95101;
    public const SHOP_NIF = 'G12345674';
    public const EMAIL_DOMAIN = '@invoice-test.example';

    /** @var list<int> */
    private array $orders = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function reset(): void
    {
        $c = $this->connection;
        // fiscal_record refuses DELETE when the triggers exist; emptied with DDL.
        $c->executeStatement('DROP TRIGGER IF EXISTS fiscal_record_no_update');
        $c->executeStatement('DROP TRIGGER IF EXISTS fiscal_record_no_delete');
        $c->executeStatement('TRUNCATE TABLE fiscal_record');
        $c->executeStatement('UPDATE fiscal_chain SET last_record_id = NULL, last_hash = NULL WHERE id = 1');
        $c->executeStatement('DELETE FROM invoice');
        $c->executeStatement('DELETE FROM invoice_sequence');
        $c->executeStatement('DELETE FROM numbering_change');
        $c->executeStatement('DELETE FROM refund WHERE terminal_id = ?', [self::TILL]);
        $c->executeStatement('DELETE FROM order_meta WHERE terminal_id = ?', [self::TILL]);
        $c->executeStatement('DELETE FROM customer WHERE email LIKE ?', ['%' . self::EMAIL_DOMAIN]);
        $c->executeStatement('DELETE FROM terminal WHERE id = ?', [self::TILL]);
        $this->orders = [];
    }

    public function till(?string $taxId = self::SHOP_NIF): void
    {
        $this->connection->insert('terminal', [
            'id' => self::TILL,
            'ps_shop_id' => 1,
            'name' => 'Caja facturas',
            'legal_name' => 'Club Natación Ejemplo',
            'store_name' => 'Tienda del club',
            'store_address' => 'Avenida del Agua 3, 33001 Oviedo',
            'tax_id' => $taxId,
            'active' => 1,
        ]);
    }

    /** @param array<string, mixed> $tax */
    public function customer(string $name = 'deportes', array $tax = []): int
    {
        $this->connection->insert('customer', $tax + [
            'firstname' => 'Ana',
            'lastname' => 'Pérez',
            'email' => $name . self::EMAIL_DOMAIN,
            'active' => 1,
            'tax_name' => 'Deportes Ejemplo SL',
            'country' => 'ES',
            'tax_id_type' => 'NI',
            'tax_id' => 'B12345674',
            'address' => 'Calle Mayor 1',
            'postcode' => '33002',
            'city' => 'Oviedo',
            'province' => 'Asturias',
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * A ticket as checkout stores it, with its sale_line rows and F2 record.
     *
     * @param list<array{name: string, qty: int, price: float, price_net: ?float, discount?: float}> $lines
     * @param ?float $total the cart total after a cart discount; the lines' sum otherwise
     * @param array<string, float> $tender
     *
     * @return int the order id
     */
    public function sale(string $reference, string $soldAt, array $lines, ?float $total = null, array $tender = [], bool $withLines = true): int
    {
        $breakdownLines = [];
        $nets = [];
        $saleLines = [];
        foreach ($lines as $line) {
            $discount = (float) ($line['discount'] ?? 0);
            $nets[] = $line['qty'] * $line['price'] * (1 - $discount / 100);
            $breakdownLines[] = ['name' => $line['name'], 'product_id' => 0, 'id_product_attribute' => 0, 'qty' => $line['qty'], 'price' => $line['price'], 'line_total' => round($line['qty'] * $line['price'], 2)];
        }
        $total ??= round(array_sum($nets), 2);
        $paidByRate = [];
        foreach (Prorate::shares($nets, $total) as $index => $paid) {
            $breakdownLines[$index]['paid_total'] = $paid;
            $saleLines[] = $breakdownLines[$index] + ['price_net' => $lines[$index]['price_net'], 'discount' => (float) ($lines[$index]['discount'] ?? 0)];
            $rate = VatRate::fromPrices($lines[$index]['price'], $lines[$index]['price_net'])['rate'];
            $key = number_format($rate, 2, '.', '');
            $paidByRate[$key] = ($paidByRate[$key] ?? 0.0) + $paid;
        }
        $tax = round(array_sum(array_column(VatRate::summary($paidByRate), 'vat')), 2);
        $tender = $tender !== [] ? $tender : ['cash' => $total, 'card' => 0.0];

        $this->connection->insert('order_meta', [
            'reference' => $reference,
            'terminal_id' => self::TILL,
            'session_id' => 0,
            'user_id' => 1,
            'payment_breakdown' => json_encode([
                'reference' => $reference,
                'total' => $total,
                'payment_method' => 'cash',
                'subtotal' => array_sum(array_column($breakdownLines, 'line_total')),
                'tax' => $tax,
                'lines' => $breakdownLines,
                'tender' => $tender,
            ], JSON_THROW_ON_ERROR),
            'printed' => 1,
            'synced' => 0,
            'created_at' => $soldAt,
        ]);
        $orderId = (int) $this->connection->lastInsertId();
        $this->orders[] = $orderId;
        if ($withLines) {
            (new SaleLineWriter($this->connection))->write($orderId, new \DateTimeImmutable($soldAt), self::TILL, 0, 1, $saleLines);
        }
        $this->connection->transactional(fn () => (new FiscalRecorder($this->connection))->recordSale($orderId, self::TILL, $reference, $total, $tax, new \DateTimeImmutable($soldAt)));

        return $orderId;
    }

    /** @return array<string, mixed> */
    public function invoiceRow(int $orderId): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM invoice WHERE sale_order_id = ?', [$orderId]);
        if ($row === false) {
            throw new \RuntimeException('No invoice for sale ' . $orderId);
        }

        return $row;
    }

    public function lastInvoiceNumber(int $year = 2026): int
    {
        return (int) ($this->connection->fetchOne("SELECT last_number FROM invoice_sequence WHERE series = 'F' AND year = ?", [$year]) ?: 0);
    }

    public static function now(string $at = '2026-11-05 10:00:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at, new \DateTimeZone('Europe/Madrid'));
    }
}
