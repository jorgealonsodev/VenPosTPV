<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Sale;

use Doctrine\DBAL\Connection;

/**
 * Writes the sale_line rows of a sale (docs/prd/informes-ventas.md, I1): one per
 * ticket line, with the reference and category the product had when it was sold and
 * its VAT rate, base and VAT amount. Called inside the sale transaction, so a failure
 * here rolls the whole sale back; the backfill command reuses it for old sales.
 */
class SaleLineWriter
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<array<string, mixed>> $lines one per ticket line, in ticket order:
     *        product_id, id_product_attribute, name, qty, price (unit, with VAT),
     *        price_net (unit, null when unknown), discount (line %, null when unknown),
     *        line_total and paid_total
     * @param bool $approximate the data comes from the current catalog, not the sale
     *
     * @return int the number of lines marked approximate
     */
    public function write(
        int $orderId,
        \DateTimeImmutable $soldAt,
        int $terminalId,
        int $sessionId,
        int $userId,
        array $lines,
        bool $approximate = false,
        bool $ignoreExisting = false,
    ): int {
        $approximateLines = 0;
        foreach (array_values($lines) as $index => $line) {
            $row = $this->row($orderId, $index, $soldAt, $terminalId, $sessionId, $userId, $line, $approximate);
            $approximateLines += $row['approximate'];
            if ($ignoreExisting) {
                $columns = array_keys($row);
                $this->connection->executeStatement(
                    sprintf(
                        'INSERT IGNORE INTO sale_line (%s) VALUES (%s)',
                        implode(', ', $columns),
                        implode(', ', array_fill(0, \count($columns), '?'))
                    ),
                    array_values($row)
                );
            } else {
                $this->connection->insert('sale_line', $row);
            }
        }

        return $approximateLines;
    }

    /**
     * @param array<string, mixed> $line
     *
     * @return array<string, mixed>
     */
    private function row(int $orderId, int $index, \DateTimeImmutable $soldAt, int $terminalId, int $sessionId, int $userId, array $line, bool $approximate): array
    {
        $productId = (int) ($line['product_id'] ?? 0);
        $combinationId = (int) ($line['id_product_attribute'] ?? 0);
        $qty = (int) ($line['qty'] ?? 1);
        $price = round((float) ($line['price'] ?? 0), 2);
        $gross = round((float) ($line['line_total'] ?? $qty * $price), 2);
        $paid = round((float) ($line['paid_total'] ?? $gross), 2);
        $net = isset($line['price_net']) && is_numeric($line['price_net']) ? (float) $line['price_net'] : null;
        $discount = \array_key_exists('discount', $line) && $line['discount'] === null
            ? null
            : round(max(0.0, min(100.0, (float) ($line['discount'] ?? 0))), 2);

        $vat = VatRate::fromPrices($price, $net);
        $split = VatRate::split($paid, $vat['rate']);
        $catalog = $this->catalog($productId, $combinationId);

        return [
            'order_id' => $orderId,
            'line_index' => $index,
            'sold_at' => $soldAt->format('Y-m-d H:i:s'),
            'terminal_id' => $terminalId,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'product_id' => $productId > 0 ? $productId : null,
            'id_product_attribute' => $combinationId,
            'reference' => $catalog['reference'],
            'name' => mb_substr((string) ($line['name'] ?? 'Producto'), 0, 255),
            'category_id' => $catalog['category_id'],
            'category_name' => $catalog['category_name'],
            'quantity' => $qty,
            'unit_price' => $price,
            'line_discount_pct' => $discount,
            'gross_amount' => $gross,
            'paid_amount' => $paid,
            'vat_rate' => $vat['rate'],
            'base_amount' => $split['base'],
            'vat_amount' => $split['vat'],
            'approximate' => $approximate || $vat['approximate'] ? 1 : 0,
        ];
    }

    /**
     * @return array{reference: ?string, category_id: ?int, category_name: ?string}
     */
    private function catalog(int $productId, int $combinationId): array
    {
        $none = ['reference' => null, 'category_id' => null, 'category_name' => null];
        if ($productId <= 0) {
            return $none;
        }
        $product = $this->connection->fetchAssociative(
            'SELECT p.reference, p.id_category_default, c.name AS category_name
             FROM catalog_product p LEFT JOIN catalog_category c ON c.id = p.id_category_default
             WHERE p.id = ?',
            [$productId]
        );
        if (!\is_array($product)) {
            return $none;
        }
        $reference = null;
        if ($combinationId > 0) {
            $combination = $this->connection->fetchOne(
                'SELECT reference FROM catalog_combination WHERE id = ? AND id_product = ?',
                [$combinationId, $productId]
            );
            $reference = \is_string($combination) && $combination !== '' ? $combination : null;
        }
        $reference ??= \is_string($product['reference'] ?? null) && $product['reference'] !== '' ? $product['reference'] : null;
        $categoryId = (int) ($product['id_category_default'] ?? 0);

        return [
            'reference' => $reference,
            'category_id' => $categoryId > 0 ? $categoryId : null,
            'category_name' => $categoryId > 0 && \is_string($product['category_name'] ?? null) ? $product['category_name'] : null,
        ];
    }
}
