<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Cart;

use Doctrine\DBAL\Connection;

class ParkedTicketService
{
    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT * FROM parked_ticket ORDER BY created_at DESC, id DESC'
        );

        $tickets = [];
        foreach ($rows as $row) {
            $tickets[] = $this->hydrateRow($row);
        }

        return $tickets;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function park(array $input): array
    {
        $lines = $input['lines'] ?? [];
        if (!is_array($lines) || count($lines) === 0) {
            throw new \InvalidArgumentException('Cannot park an empty ticket');
        }

        $clientRef = isset($input['client_ref']) && is_string($input['client_ref']) && trim($input['client_ref']) !== ''
            ? substr(trim($input['client_ref']), 0, 64)
            : 'parked_' . (int) (microtime(true) * 1000) . '_' . bin2hex(random_bytes(3));

        $existing = $this->conn->fetchAssociative(
            'SELECT * FROM parked_ticket WHERE client_ref = ? LIMIT 1',
            [$clientRef]
        );
        if (is_array($existing)) {
            return $this->hydrateRow($existing);
        }

        $normalizedLines = [];
        $subtotal = 0.0;
        foreach ($lines as $idx => $line) {
            if (!is_array($line)) {
                continue;
            }
            $price = round((float) ($line['price'] ?? 0), 2);
            $qty = max(1, (int) ($line['qty'] ?? $line['quantity'] ?? 1));
            $lineDiscount = max(0.0, min(100.0, (float) ($line['discount'] ?? 0)));
            $subtotal += $price * $qty * (1.0 - $lineDiscount / 100.0);

            $normalizedLines[] = [
                'id' => (string) ($line['id'] ?? ('line_' . $idx)),
                'product_id' => (int) ($line['product_id'] ?? $line['id_product'] ?? 0),
                'id_product_attribute' => (int) ($line['id_product_attribute'] ?? 0),
                'name' => (string) ($line['name'] ?? ''),
                'reference' => (string) ($line['reference'] ?? ''),
                'price' => $price,
                'qty' => $qty,
                'discount' => $lineDiscount,
            ];
        }

        if (count($normalizedLines) === 0) {
            throw new \InvalidArgumentException('Cannot park an empty ticket');
        }

        $discount = null;
        $discountAmount = 0.0;
        if (isset($input['discount']) && is_array($input['discount']) && isset($input['discount']['type'], $input['discount']['value'])) {
            $discType = $input['discount']['type'] === 'amt' ? 'amt' : 'pct';
            $discValue = max(0.0, (float) $input['discount']['value']);
            if ($discValue > 0) {
                $discount = [
                    'type' => $discType,
                    'value' => $discValue,
                ];
                if ($discType === 'pct') {
                    $discountAmount = $subtotal * ($discValue / 100.0);
                } else {
                    $discountAmount = min($discValue, $subtotal);
                }
            }
        }

        $total = round(max(0.0, $subtotal - $discountAmount), 2);

        $rawNote = isset($input['note']) && is_string($input['note']) ? trim($input['note']) : '';
        $note = $rawNote !== '' ? mb_substr($rawNote, 0, 120) : null;

        $customer = isset($input['customer']) && is_array($input['customer'])
            ? $input['customer']
            : ['name' => 'Consumidor final', 'default' => true];

        $terminalId = isset($input['terminal_id']) && (int) $input['terminal_id'] > 0
            ? (int) $input['terminal_id']
            : 1;
        $userId = isset($input['user_id']) && (int) $input['user_id'] > 0
            ? (int) $input['user_id']
            : null;
        $cashierName = isset($input['cashier_name']) && is_string($input['cashier_name']) && trim($input['cashier_name']) !== ''
            ? mb_substr(trim($input['cashier_name']), 0, 128)
            : 'Cajero';
        $createdAt = isset($input['created_at']) && is_string($input['created_at']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $input['created_at']) === 1
            ? $input['created_at']
            : (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $rowData = [
            'client_ref' => $clientRef,
            'terminal_id' => $terminalId,
            'user_id' => $userId,
            'cashier_name' => $cashierName,
            'note' => $note,
            'customer' => json_encode($customer, JSON_THROW_ON_ERROR),
            'lines' => json_encode($normalizedLines, JSON_THROW_ON_ERROR),
            'discount' => $discount !== null ? json_encode($discount, JSON_THROW_ON_ERROR) : null,
            'total' => number_format($total, 2, '.', ''),
            'created_at' => $createdAt,
        ];

        $this->conn->insert('parked_ticket', $rowData);
        $insertedId = (int) $this->conn->lastInsertId();

        return [
            'id' => $insertedId,
            'client_ref' => $clientRef,
            'terminal_id' => $terminalId,
            'user_id' => $userId,
            'cashier_name' => $cashierName,
            'note' => $note,
            'customer' => $customer,
            'lines' => $normalizedLines,
            'discount' => $discount,
            'total' => $total,
            'created_at' => $createdAt,
        ];
    }

    public function delete(string $identifier): bool
    {
        $trimmed = trim($identifier);
        if ($trimmed === '') {
            return false;
        }

        if (ctype_digit($trimmed)) {
            $affected = $this->conn->executeStatement(
                'DELETE FROM parked_ticket WHERE id = ? OR client_ref = ?',
                [(int) $trimmed, $trimmed]
            );
        } else {
            $affected = $this->conn->executeStatement(
                'DELETE FROM parked_ticket WHERE client_ref = ?',
                [$trimmed]
            );
        }

        return $affected > 0;
    }

    public function clearAll(): int
    {
        return (int) $this->conn->executeStatement('DELETE FROM parked_ticket');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrateRow(array $row): array
    {
        $customer = ['name' => 'Consumidor final', 'default' => true];
        if (isset($row['customer']) && is_string($row['customer']) && $row['customer'] !== '') {
            $decodedCustomer = json_decode($row['customer'], true);
            if (is_array($decodedCustomer)) {
                $customer = $decodedCustomer;
            }
        }

        $lines = [];
        if (isset($row['lines']) && is_string($row['lines']) && $row['lines'] !== '') {
            $decodedLines = json_decode($row['lines'], true);
            if (is_array($decodedLines)) {
                $lines = $decodedLines;
            }
        }

        $discount = null;
        if (isset($row['discount']) && is_string($row['discount']) && $row['discount'] !== '') {
            $decodedDiscount = json_decode($row['discount'], true);
            if (is_array($decodedDiscount)) {
                $discount = $decodedDiscount;
            }
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'client_ref' => (string) ($row['client_ref'] ?? ''),
            'terminal_id' => (int) ($row['terminal_id'] ?? 1),
            'user_id' => isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'cashier_name' => (string) ($row['cashier_name'] ?? 'Cajero'),
            'note' => isset($row['note']) && $row['note'] !== null ? (string) $row['note'] : null,
            'customer' => $customer,
            'lines' => $lines,
            'discount' => $discount,
            'total' => round((float) ($row['total'] ?? 0), 2),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
