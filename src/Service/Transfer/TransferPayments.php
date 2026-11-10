<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Transfer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use VenPosTPV\Service\Print\CashierName;

/**
 * Sales paid by bank transfer (docs/pago-transferencia.md). The ticket is issued at
 * once, but the money is «Pendiente de pago» until an admin sees it in the bank and
 * marks it received («Tickets → Pendientes de pago»). One row per sale, written in
 * the sale's own transaction; afterwards only its status changes, once.
 */
class TransferPayments
{
    public const PENDING = 'pending';
    public const RECEIVED = 'received';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Inside the sale's transaction. A database before Version00000052 has no table
     * yet: the sale goes on (MySQL keeps the transaction after a failed statement)
     * and is simply not listed as pending.
     */
    public function record(int $orderId, float $amount, \DateTimeImmutable $at): void
    {
        try {
            $this->connection->insert('transfer_payment', [
                'order_id' => $orderId,
                'amount' => round($amount, 2),
                'status' => self::PENDING,
                'created_at' => $at->format('Y-m-d H:i:s'),
            ]);
        } catch (TableNotFoundException) {
        }
    }

    /**
     * The transfers still to be received, oldest first.
     *
     * @return list<array{id: int, order_id: int, reference: string, amount: float, created_at: string, cashier: string, terminal: string}>
     */
    public function pending(): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT t.id, t.order_id, t.amount, t.created_at, o.reference, o.user_id, o.payment_breakdown, te.name AS terminal
                 FROM transfer_payment t
                 JOIN order_meta o ON o.id = t.order_id
                 LEFT JOIN terminal te ON te.id = o.terminal_id
                 WHERE t.status = 'pending'
                 ORDER BY t.created_at, t.id"
            );
        } catch (TableNotFoundException) {
            return [];
        }

        return array_map(function (array $row): array {
            $breakdown = json_decode((string) $row['payment_breakdown'], true);
            $reference = \is_string($row['reference'] ?? null) && $row['reference'] !== ''
                ? $row['reference']
                : (string) (\is_array($breakdown) ? ($breakdown['reference'] ?? '') : '');

            return [
                'id' => (int) $row['id'],
                'order_id' => (int) $row['order_id'],
                'reference' => $reference,
                'amount' => round((float) $row['amount'], 2),
                'created_at' => (string) $row['created_at'],
                'cashier' => CashierName::of($this->connection, (int) $row['user_id']),
                'terminal' => (string) ($row['terminal'] ?? ''),
            ];
        }, $rows);
    }

    /** Whether the sale is paid by a transfer still to be received. */
    public function isPending(int $orderId): bool
    {
        try {
            return $this->connection->fetchOne("SELECT 1 FROM transfer_payment WHERE order_id = ? AND status = 'pending'", [$orderId]) !== false;
        } catch (TableNotFoundException) {
            return false;
        }
    }

    /**
     * @return 'received'|'already_received'|'not_found'
     */
    public function markReceived(int $id, int $userId, ?\DateTimeImmutable $at = null): string
    {
        $changed = $this->connection->executeStatement(
            "UPDATE transfer_payment SET status = 'received', received_at = ?, received_by_user_id = ? WHERE id = ? AND status = 'pending'",
            [($at ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'), $userId, $id]
        );
        if ($changed > 0) {
            return 'received';
        }

        return $this->connection->fetchOne('SELECT 1 FROM transfer_payment WHERE id = ?', [$id]) !== false ? 'already_received' : 'not_found';
    }
}
