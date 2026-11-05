<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Sale;

use Doctrine\DBAL\Connection;

/**
 * Writes the sale_line rows of sales stored before the table existed
 * (docs/prd/informes-ventas.md, I1 «Relleno de ventas antiguas»). Sales without lines
 * are read by id in batches, each batch in its own transaction, so the run can be
 * interrupted and started again: a sale that already has lines is never touched, and
 * any write error rolls its batch back and stops the run (no line is silently dropped).
 *
 * The reference, category and net price come from the current catalog, so every
 * backfilled line is approximate. A sale stored before paid_total spreads its total
 * over the lines by line_total; the line discount % is unknown (NULL).
 */
class SaleLineBackfill
{
    public const BATCH_SIZE = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly SaleLineWriter $writer,
    ) {
    }

    /**
     * @return array{sales: int, lines: int, approximate: int, unreadable: list<int>}
     */
    public function run(int $batchSize = self::BATCH_SIZE): array
    {
        $result = ['sales' => 0, 'lines' => 0, 'approximate' => 0, 'unreadable' => []];
        $afterId = 0;

        while (true) {
            $orders = $this->connection->fetchAllAssociative(
                sprintf(
                    'SELECT o.id, o.terminal_id, o.session_id, o.user_id, o.created_at, o.payment_breakdown
                     FROM order_meta o
                     WHERE o.id > ? AND NOT EXISTS (SELECT 1 FROM sale_line s WHERE s.order_id = o.id)
                     ORDER BY o.id LIMIT %d',
                    $batchSize
                ),
                [$afterId]
            );
            if ($orders === []) {
                return $result;
            }

            $this->connection->transactional(function () use ($orders, &$result): void {
                foreach ($orders as $order) {
                    $lines = $this->lines((string) $order['payment_breakdown']);
                    if ($lines === null) {
                        $result['unreadable'][] = (int) $order['id'];
                        continue;
                    }
                    $result['approximate'] += $this->writer->write(
                        (int) $order['id'],
                        new \DateTimeImmutable((string) $order['created_at']),
                        (int) $order['terminal_id'],
                        (int) $order['session_id'],
                        (int) $order['user_id'],
                        $lines,
                        approximate: true,
                    );
                    ++$result['sales'];
                    $result['lines'] += \count($lines);
                }
            });
            $afterId = (int) $orders[array_key_last($orders)]['id'];
        }
    }

    /**
     * The ticket lines of a stored sale, ready for SaleLineWriter; null when the
     * breakdown has no readable lines.
     *
     * @return list<array<string, mixed>>|null
     */
    private function lines(string $breakdownJson): ?array
    {
        try {
            $breakdown = json_decode($breakdownJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $stored = \is_array($breakdown) ? ($breakdown['lines'] ?? null) : null;
        if (!\is_array($stored) || $stored === []) {
            return null;
        }

        $lines = [];
        foreach ($stored as $line) {
            if (!\is_array($line)) {
                return null;
            }
            $qty = (int) ($line['qty'] ?? 1);
            $price = (float) ($line['price'] ?? 0);
            $productId = (int) ($line['product_id'] ?? 0);
            $combinationId = (int) ($line['id_product_attribute'] ?? 0);
            $lines[] = [
                'product_id' => $productId,
                'id_product_attribute' => $combinationId,
                'name' => (string) ($line['name'] ?? 'Producto'),
                'qty' => $qty,
                'price' => $price,
                'line_total' => isset($line['line_total']) ? (float) $line['line_total'] : round($qty * $price, 2),
                'paid_total' => isset($line['paid_total']) ? (float) $line['paid_total'] : null,
                'price_net' => $this->currentNetPrice($productId, $combinationId),
                'discount' => null,
            ];
        }

        if (\in_array(null, array_column($lines, 'paid_total'), true)) {
            $total = (float) ($breakdown['total'] ?? array_sum(array_column($lines, 'line_total')));
            foreach (Prorate::shares(array_column($lines, 'line_total'), $total) as $index => $paid) {
                $lines[$index]['paid_total'] = $paid;
            }
        }

        return $lines;
    }

    private function currentNetPrice(int $productId, int $combinationId): ?float
    {
        if ($productId <= 0) {
            return null;
        }
        if ($combinationId > 0) {
            $net = $this->connection->fetchOne(
                'SELECT price_net FROM catalog_combination WHERE id = ? AND id_product = ?',
                [$combinationId, $productId]
            );
            if (is_numeric($net)) {
                return (float) $net;
            }
        }
        $net = $this->connection->fetchOne('SELECT price_net FROM catalog_product WHERE id = ?', [$productId]);

        return is_numeric($net) ? (float) $net : null;
    }
}
