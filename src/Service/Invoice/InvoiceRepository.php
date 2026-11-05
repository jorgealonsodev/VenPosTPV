<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Invoice;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;

/**
 * Reads the stored invoices. An invoice keeps a copy of everything it shows
 * (issuer, recipient, lines, VAT box, payments), so a reprint or a resend gives the
 * same document; the only later write is the customer's email consent.
 */
class InvoiceRepository
{
    // MySQL's JSON type reorders keys and stores 10.0 as 10: the views are rebuilt in
    // this order with these types, so every read returns the invoice as it was issued.
    private const ISSUER = ['name' => 's', 'tax_id' => 's', 'address' => 's'];
    private const RECIPIENT = ['customer_id' => 'i', 'name' => 's', 'country' => 's', 'tax_id_type' => 's', 'tax_id' => 's', 'address' => 's', 'postcode' => 's', 'city' => 's', 'province' => 's'];
    private const LINE = ['name' => 's', 'reference' => 's', 'quantity' => 'i', 'unit_price' => 'f', 'unit_price_net' => 'f', 'discount_pct' => 'f', 'discount_net' => 'f', 'net_amount' => 'f', 'vat_rate' => 'f', 'paid_amount' => 'f'];
    private const RATE = ['rate' => 'f', 'base' => 'f', 'vat' => 'f'];
    private const PAYMENT = ['method' => 's', 'label' => 's', 'amount' => 'f'];

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM invoice WHERE id = ?', [$id]);
    }

    /**
     * The invoice that replaced a sale's ticket; null when there is none, also on a
     * database without the invoice table yet.
     *
     * @return array<string, mixed>|null
     */
    public function findBySale(int $orderId): ?array
    {
        try {
            return $this->one('SELECT * FROM invoice WHERE sale_order_id = ?', [$orderId]);
        } catch (TableNotFoundException) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    public function findByRequest(string $requestId): ?array
    {
        return $this->one('SELECT * FROM invoice WHERE request_id = ?', [$requestId]);
    }

    /**
     * Records that the customer accepts the invoice by email (RD 1619/2012 art. 9.2).
     * The first consent stays; later ones change nothing.
     */
    public function recordEmailConsent(int $id, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'UPDATE invoice SET email_consent_at = ? WHERE id = ? AND email_consent_at IS NULL',
            [$now->format('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * @param list<mixed> $params
     *
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $row = $this->connection->fetchAssociative($sql, $params);

        return $row === false ? null : self::view($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function view(array $row): array
    {
        $json = static function (mixed $value): array {
            $decoded = json_decode((string) $value, true);

            return \is_array($decoded) ? $decoded : [];
        };

        return [
            'id' => (int) $row['id'],
            'number' => (string) $row['number'],
            'series' => (string) $row['series'],
            'year' => (int) $row['year'],
            'seq' => (int) $row['seq'],
            'invoice_type' => (string) $row['invoice_type'],
            'sale_order_id' => $row['sale_order_id'] !== null ? (int) $row['sale_order_id'] : null,
            'replaced_reference' => $row['replaced_reference'] !== null ? (string) $row['replaced_reference'] : null,
            'replaced_date' => $row['replaced_date'] !== null ? (string) $row['replaced_date'] : null,
            'customer_id' => $row['customer_id'] !== null ? (int) $row['customer_id'] : null,
            'issuer' => self::shape($json($row['issuer']), self::ISSUER),
            'recipient' => self::shape($json($row['recipient']), self::RECIPIENT),
            'lines' => array_map(static fn (mixed $line): array => self::shape(\is_array($line) ? $line : [], self::LINE), $json($row['line_items'])),
            'tax_breakdown' => array_map(static fn (mixed $rate): array => self::shape(\is_array($rate) ? $rate : [], self::RATE), $json($row['tax_breakdown'])),
            'payments' => array_map(static fn (mixed $payment): array => self::shape(\is_array($payment) ? $payment : [], self::PAYMENT), $json($row['payments'])),
            'tax_total' => round((float) $row['tax_total'], 2),
            'total' => round((float) $row['total'], 2),
            'issue_date' => (string) $row['issue_date'],
            'operation_date' => (string) $row['operation_date'],
            'email_consent_at' => $row['email_consent_at'] !== null ? (string) $row['email_consent_at'] : null,
            'terminal_id' => (int) $row['terminal_id'],
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $types  key => s (string), i (int) or f (float)
     *
     * @return array<string, mixed>
     */
    private static function shape(array $values, array $types): array
    {
        $shaped = [];
        foreach ($types as $key => $type) {
            $value = $values[$key] ?? null;
            $shaped[$key] = match ($type) {
                'i' => (int) $value,
                'f' => round((float) $value, 2),
                default => (string) $value,
            };
        }

        return $shaped;
    }
}
