<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Report;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Cash\CashTotalsAggregator;
use VenPosTPV\Service\Cash\CashTotalsScope;
use VenPosTPV\Service\Sale\VatRate;

/**
 * The sales reports of the till (docs/prd/informes-ventas.md I2, metrics in §3):
 * Resumen, Por día, Por hora, Por producto, Por categoría, Por cajero, Por forma de
 * pago and IVA, read from the local database only.
 *
 * Sales come from sale_line by sold_at and tickets from order_meta by created_at;
 * refunds count on the day they were made (refund.completed_at), only when done and
 * not online. Every query filters with a half-open range on the indexed column
 * itself; DATE() and HOUR() appear only in GROUP BY (I6).
 *
 * A refund line with ticket takes the category and VAT rate of the sale line it
 * returns (order_id + line_index); without ticket, those of the current catalog.
 */
class SalesReportService
{
    public const TYPES = ['summary', 'daily', 'hourly', 'products', 'categories', 'cashiers', 'payments', 'vat'];

    /** Rows per page of «Por producto» (I5); a CSV export has every row. */
    public const PAGE_SIZE = 50;

    public const UNCATEGORISED = 'Sin categoría';

    /** The payment methods of a sale, always listed even when no ticket used them. */
    private const METHODS = ['cash', 'card', 'mixed', 'transfer', 'exchange'];

    /** The outbox states of an operation still to upload. */
    private const PENDING = "('pending', 'processing', 'incident')";

    private readonly CashTotalsAggregator $totals;

    public function __construct(
        private readonly Connection $connection,
        ?CashTotalsAggregator $totals = null,
    ) {
        $this->totals = $totals ?? new CashTotalsAggregator($connection);
    }

    public function supports(string $type): bool
    {
        return \in_array($type, self::TYPES, true);
    }

    /**
     * @return array{data: array<string, mixed>, approximate_amount: float, missing_lines: int}
     *
     * @throws ReportNotFound when filtered by an unknown cash session
     */
    public function build(string $type, ReportFilter $filter): array
    {
        return match ($type) {
            'summary' => $this->summary($filter),
            'daily' => $this->daily($filter),
            'hourly' => $this->hourly($filter),
            'products' => $filter->view === 'unsold' ? $this->unsold($filter) : $this->products($filter),
            'categories' => $this->categories($filter),
            'cashiers' => $this->cashiers($filter),
            'payments' => $this->payments($filter),
            'vat' => $this->vat($filter),
            default => throw new \InvalidArgumentException(sprintf('Unknown report type "%s"', $type)),
        };
    }

    /** @return array{data: array<string, mixed>, approximate_amount: float, missing_lines: int} */
    private function summary(ReportFilter $filter): array
    {
        $current = $this->metrics($filter);
        $previousFilter = $filter->previous();
        $previous = $this->metrics($previousFilter);

        $comparison = [];
        foreach ($current as $key => $value) {
            $difference = \is_int($value) ? $value - $previous[$key] : round($value - $previous[$key], 2);
            $comparison[$key] = [
                'difference' => $difference,
                'percent' => $previous[$key] == 0 ? null : round($difference / abs($previous[$key]) * 100, 2),
            ];
        }

        return [
            'data' => $this->range($filter) + $current + [
                'pending_upload' => $this->pendingUpload($filter),
                'previous' => $this->range($previousFilter) + $previous,
                'comparison' => $comparison,
            ],
        ] + $this->quality($filter);
    }

    /** @return array{data: array<string, mixed>, approximate_amount: float, missing_lines: int} */
    private function daily(ReportFilter $filter): array
    {
        [$lineWhere, $lineParams] = $this->where('l.sold_at', 'l.terminal_id', 'l.user_id', $filter);
        [$saleWhere, $saleParams] = $this->where('o.created_at', 'o.terminal_id', 'o.user_id', $filter);
        [$refundWhere, $refundParams] = $this->where('r.completed_at', 'r.terminal_id', 'r.user_id', $filter);

        $lines = $this->byDay(
            'SELECT DATE(l.sold_at) AS day, COALESCE(SUM(l.quantity), 0) AS units, COALESCE(SUM(l.paid_amount), 0) AS paid
             FROM sale_line l WHERE ' . $lineWhere . ' GROUP BY DATE(l.sold_at)',
            $lineParams
        );
        $tickets = $this->byDay(
            'SELECT DATE(o.created_at) AS day, COUNT(*) AS tickets FROM order_meta o WHERE ' . $saleWhere . ' GROUP BY DATE(o.created_at)',
            $saleParams
        );
        $refunds = $this->byDay(
            "SELECT DATE(r.completed_at) AS day, COALESCE(SUM(r.total), 0) AS refunds
             FROM refund r WHERE r.status = 'done' AND r.online = 0 AND " . $refundWhere . ' GROUP BY DATE(r.completed_at)',
            $refundParams
        );

        $days = [];
        $totals = ['tickets' => 0, 'units' => 0, 'gross_sales' => 0.0, 'refunds' => 0.0, 'net_sales' => 0.0];
        $end = $filter->toExclusive();
        for ($day = $filter->from(); $day < $end; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $gross = round((float) ($lines[$key]['paid'] ?? 0), 2);
            $refunded = round((float) ($refunds[$key]['refunds'] ?? 0), 2);
            $row = [
                'date' => $key,
                'tickets' => (int) ($tickets[$key]['tickets'] ?? 0),
                'units' => (int) ($lines[$key]['units'] ?? 0),
                'gross_sales' => $gross,
                'refunds' => $refunded,
                'net_sales' => round($gross - $refunded, 2) + 0.0,
            ];
            $days[] = $row;
            $totals['tickets'] += $row['tickets'];
            $totals['units'] += $row['units'];
            $totals['gross_sales'] += $gross;
            $totals['refunds'] += $refunded;
        }
        $totals['gross_sales'] = round($totals['gross_sales'], 2);
        $totals['refunds'] = round($totals['refunds'], 2);
        $totals['net_sales'] = round($totals['gross_sales'] - $totals['refunds'], 2) + 0.0;

        return ['data' => $this->range($filter) + ['days' => $days, 'totals' => $totals]] + $this->quality($filter);
    }

    /**
     * 24 slots (0-23) with the tickets and gross sales of every day of the range.
     *
     * @return array{data: array<string, mixed>, approximate_amount: float, missing_lines: int}
     */
    private function hourly(ReportFilter $filter): array
    {
        [$lineWhere, $lineParams] = $this->where('l.sold_at', 'l.terminal_id', 'l.user_id', $filter);
        [$saleWhere, $saleParams] = $this->where('o.created_at', 'o.terminal_id', 'o.user_id', $filter);

        $paid = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT HOUR(l.sold_at) AS hour, COALESCE(SUM(l.paid_amount), 0) AS paid FROM sale_line l WHERE ' . $lineWhere . ' GROUP BY HOUR(l.sold_at)',
            $lineParams
        ) as $row) {
            $paid[(int) $row['hour']] = (float) $row['paid'];
        }
        $tickets = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT HOUR(o.created_at) AS hour, COUNT(*) AS tickets FROM order_meta o WHERE ' . $saleWhere . ' GROUP BY HOUR(o.created_at)',
            $saleParams
        ) as $row) {
            $tickets[(int) $row['hour']] = (int) $row['tickets'];
        }

        $hours = [];
        $totals = ['tickets' => 0, 'gross_sales' => 0.0];
        for ($hour = 0; $hour < 24; ++$hour) {
            $row = ['hour' => $hour, 'tickets' => $tickets[$hour] ?? 0, 'gross_sales' => round($paid[$hour] ?? 0.0, 2)];
            $hours[] = $row;
            $totals['tickets'] += $row['tickets'];
            $totals['gross_sales'] += $row['gross_sales'];
        }
        $totals['gross_sales'] = round($totals['gross_sales'], 2);

        return ['data' => $this->range($filter) + ['hours' => $hours, 'totals' => $totals]] + $this->quality($filter);
    }

    /**
     * One row per product and combination (or per product), free items grouped by
     * name; sold units and amounts from sale_line, refunded ones from refund_line.
     * Pages of 50 rows by offset cursor; the totals are of every row.
     *
     * @return array{data: array<string, mixed>, approximate_amount: float, missing_lines: int}
     */
    private function products(ReportFilter $filter): array
    {
        [$lineWhere, $lineParams] = $this->where('l.sold_at', 'l.terminal_id', 'l.user_id', $filter);
        $byProduct = $filter->group === 'product';
        // The name and reference of the most recent line: a renamed product shows the last one.
        $latest = static fn (string $column): string => "SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(l.$column, '') ORDER BY l.sold_at DESC, l.id DESC SEPARATOR '\n'), '\n', 1)";
        $sql = 'SELECT l.product_id, ' . ($byProduct ? '0' : 'l.id_product_attribute') . ' AS attribute,
                       CASE WHEN l.product_id IS NULL THEN l.name END AS free_name,
                       ' . $latest('name') . ' AS name, ' . $latest('reference') . ' AS reference,
                       SUM(l.quantity) AS units, SUM(l.paid_amount) AS paid
                FROM sale_line l WHERE ' . $lineWhere . '
                GROUP BY l.product_id, ' . ($byProduct ? '' : 'l.id_product_attribute, ') . 'free_name';

        $rows = [];
        foreach ($this->connection->fetchAllAssociative($sql, $lineParams) as $line) {
            $free = $line['product_id'] === null;
            $key = $free ? 'free:' . $line['free_name'] : $line['product_id'] . ':' . $line['attribute'];
            $rows[$key] = $this->productRow($free ? null : (int) $line['product_id'], $free || $byProduct ? null : (int) $line['attribute'],
                $line['reference'] === '' ? null : (string) $line['reference'], (string) $line['name']);
            $rows[$key]['units_sold'] = (int) $line['units'];
            $rows[$key]['sales_amount'] = round((float) $line['paid'], 2);
        }

        foreach ($this->refundLines($filter) as $refund) {
            $free = $refund['product_id'] === null;
            $attribute = $byProduct ? 0 : $refund['id_product_attribute'];
            $key = $free ? 'free:' . $refund['name'] : $refund['product_id'] . ':' . $attribute;
            $rows[$key] ??= $this->productRow($refund['product_id'], $free || $byProduct ? null : $attribute, $refund['reference'], $refund['name']);
            $rows[$key]['units_refunded'] += $refund['quantity'];
            $rows[$key]['refunds_amount'] += $refund['amount'];
        }

        if ($byProduct) {
            $this->useCatalogNames($rows);
        }

        $totals = ['units_sold' => 0, 'units_refunded' => 0, 'units_net' => 0, 'sales_amount' => 0.0, 'refunds_amount' => 0.0, 'net_amount' => 0.0];
        foreach ($rows as &$row) {
            $row['refunds_amount'] = round($row['refunds_amount'], 2);
            $row['units_net'] = $row['units_sold'] - $row['units_refunded'];
            $row['net_amount'] = round($row['sales_amount'] - $row['refunds_amount'], 2) + 0.0;
            foreach (['units_sold', 'units_refunded', 'units_net', 'sales_amount', 'refunds_amount'] as $column) {
                $totals[$column] += $row[$column];
            }
        }
        unset($row);
        $totals = $this->roundTotals($totals);
        $rows = $this->withPercent(array_values($rows), $totals['net_amount']);

        $units = $filter->sort === 'units';
        usort($rows, static fn (array $a, array $b): int => $units
            ? [$b['units_net'], $b['net_amount'], $a['name'], $a['product_id'] ?? 0, $a['id_product_attribute'] ?? 0] <=> [$a['units_net'], $a['net_amount'], $b['name'], $b['product_id'] ?? 0, $b['id_product_attribute'] ?? 0]
            : [$b['net_amount'], $b['units_net'], $a['name'], $a['product_id'] ?? 0, $a['id_product_attribute'] ?? 0] <=> [$a['net_amount'], $a['units_net'], $b['name'], $b['product_id'] ?? 0, $b['id_product_attribute'] ?? 0]);

        [$page, $info] = $this->page($rows, \count($rows), $filter);

        return [
            'data' => $this->range($filter) + ['view' => 'sold', 'sort' => $filter->sort, 'group' => $filter->group, 'rows' => $page, 'totals' => $totals, 'page' => $info],
        ] + $this->quality($filter);
    }

    /**
     * «Sin ventas»: active catalog products without any sale line in the range (with
     * the same till and cashier filters), by name.
     *
     * @return array{data: array<string, mixed>, approximate_amount: float, missing_lines: int}
     */
    private function unsold(ReportFilter $filter): array
    {
        [$lineWhere, $lineParams] = $this->where('l.sold_at', 'l.terminal_id', 'l.user_id', $filter);
        $from = 'FROM catalog_product p LEFT JOIN catalog_category c ON c.id = p.id_category_default
                 WHERE p.active = 1 AND NOT EXISTS (SELECT 1 FROM sale_line l WHERE l.product_id = p.id AND ' . $lineWhere . ')';
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) ' . $from, $lineParams);
        $sql = 'SELECT p.id, p.reference, p.name, c.name AS category_name ' . $from . ' ORDER BY p.name, p.id';
        if ($filter->format !== 'csv') {
            $sql .= sprintf(' LIMIT %d OFFSET %d', self::PAGE_SIZE, $filter->cursor);
        }
        $rows = array_map(static fn (array $row): array => [
            'product_id' => (int) $row['id'],
            'reference' => \is_string($row['reference']) && $row['reference'] !== '' ? $row['reference'] : null,
            'name' => (string) $row['name'],
            'category_name' => $row['category_name'] !== null ? (string) $row['category_name'] : null,
        ], $this->connection->fetchAllAssociative($sql, $lineParams));
        [, $info] = $this->page([], $total, $filter);

        return [
            'data' => $this->range($filter) + ['view' => 'unsold', 'sort' => $filter->sort, 'group' => $filter->group, 'rows' => $rows, 'totals' => ['products' => $total], 'page' => $info],
        ] + $this->quality($filter);
    }

    /**
     * Net amount, units and % per category stored in the sale line (NULL is «Sin
     * categoría»), with the stored category name.
     *
     * @return array{data: array<string, mixed>, approximate_amount: float, missing_lines: int}
     */
    private function categories(ReportFilter $filter): array
    {
        [$lineWhere, $lineParams] = $this->where('l.sold_at', 'l.terminal_id', 'l.user_id', $filter);
        $rows = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT l.category_id, SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(l.category_name, '') ORDER BY l.sold_at DESC, l.id DESC SEPARATOR '\n'), '\n', 1) AS name,
                    SUM(l.quantity) AS units, SUM(l.paid_amount) AS paid
             FROM sale_line l WHERE " . $lineWhere . ' GROUP BY l.category_id',
            $lineParams
        ) as $line) {
            $id = $line['category_id'] !== null ? (int) $line['category_id'] : null;
            $rows[$id ?? 0] = $this->categoryRow($id, (string) $line['name']);
            $rows[$id ?? 0]['units_sold'] = (int) $line['units'];
            $rows[$id ?? 0]['sales_amount'] = round((float) $line['paid'], 2);
        }
        foreach ($this->refundLines($filter) as $refund) {
            $id = $refund['category_id'];
            $rows[$id ?? 0] ??= $this->categoryRow($id, (string) $refund['category_name']);
            $rows[$id ?? 0]['units_refunded'] += $refund['quantity'];
            $rows[$id ?? 0]['refunds_amount'] += $refund['amount'];
        }

        $totals = ['units_sold' => 0, 'units_refunded' => 0, 'units_net' => 0, 'sales_amount' => 0.0, 'refunds_amount' => 0.0, 'net_amount' => 0.0];
        foreach ($rows as &$row) {
            $row['refunds_amount'] = round($row['refunds_amount'], 2);
            $row['units_net'] = $row['units_sold'] - $row['units_refunded'];
            $row['net_amount'] = round($row['sales_amount'] - $row['refunds_amount'], 2) + 0.0;
            foreach (['units_sold', 'units_refunded', 'units_net', 'sales_amount', 'refunds_amount'] as $column) {
                $totals[$column] += $row[$column];
            }
        }
        unset($row);
        $totals = $this->roundTotals($totals);
        $rows = $this->withPercent(array_values($rows), $totals['net_amount']);
        usort($rows, static fn (array $a, array $b): int => [$b['net_amount'], $a['name'], $a['category_id'] ?? 0] <=> [$a['net_amount'], $b['name'], $b['category_id'] ?? 0]);

        return ['data' => $this->range($filter) + ['rows' => $rows, 'totals' => $totals]] + $this->quality($filter);
    }

    /**
     * Per employee: the tickets they sold and the refunds they made (refund.user_id),
     * with their current name.
     *
     * @return array{data: array<string, mixed>, approximate_amount: float, missing_lines: int}
     */
    private function cashiers(ReportFilter $filter): array
    {
        [$lineWhere, $lineParams] = $this->where('l.sold_at', 'l.terminal_id', 'l.user_id', $filter);
        [$saleWhere, $saleParams] = $this->where('o.created_at', 'o.terminal_id', 'o.user_id', $filter);
        [$refundWhere, $refundParams] = $this->where('r.completed_at', 'r.terminal_id', 'r.user_id', $filter);

        $rows = [];
        $row = static fn (int $id): array => ['user_id' => $id, 'name' => '', 'tickets' => 0, 'gross_sales' => 0.0, 'average_ticket' => 0.0, 'refunds' => 0.0, 'net_sales' => 0.0];
        foreach ($this->connection->fetchAllAssociative('SELECT o.user_id, COUNT(*) AS tickets FROM order_meta o WHERE ' . $saleWhere . ' GROUP BY o.user_id', $saleParams) as $r) {
            $rows[(int) $r['user_id']] ??= $row((int) $r['user_id']);
            $rows[(int) $r['user_id']]['tickets'] = (int) $r['tickets'];
        }
        foreach ($this->connection->fetchAllAssociative('SELECT l.user_id, SUM(l.paid_amount) AS paid FROM sale_line l WHERE ' . $lineWhere . ' GROUP BY l.user_id', $lineParams) as $r) {
            $rows[(int) $r['user_id']] ??= $row((int) $r['user_id']);
            $rows[(int) $r['user_id']]['gross_sales'] = round((float) $r['paid'], 2);
        }
        foreach ($this->connection->fetchAllAssociative(
            "SELECT r.user_id, SUM(r.total) AS refunds FROM refund r WHERE r.status = 'done' AND r.online = 0 AND " . $refundWhere . ' GROUP BY r.user_id',
            $refundParams
        ) as $r) {
            $rows[(int) $r['user_id']] ??= $row((int) $r['user_id']);
            $rows[(int) $r['user_id']]['refunds'] = round((float) $r['refunds'], 2);
        }

        $names = [];
        if ($rows !== []) {
            $names = $this->connection->fetchAllKeyValue('SELECT id, name FROM user WHERE id IN (?)', [array_keys($rows)], [ArrayParameterType::INTEGER]);
        }
        $totals = ['tickets' => 0, 'gross_sales' => 0.0, 'average_ticket' => 0.0, 'refunds' => 0.0, 'net_sales' => 0.0];
        foreach ($rows as $id => &$r) {
            $r['name'] = isset($names[$id]) ? (string) $names[$id] : 'Empleado #' . $id;
            $r['average_ticket'] = $r['tickets'] > 0 ? round($r['gross_sales'] / $r['tickets'], 2) : 0.0;
            $r['net_sales'] = round($r['gross_sales'] - $r['refunds'], 2) + 0.0;
            $totals['tickets'] += $r['tickets'];
            $totals['gross_sales'] += $r['gross_sales'];
            $totals['refunds'] += $r['refunds'];
        }
        unset($r);
        $totals['gross_sales'] = round($totals['gross_sales'], 2);
        $totals['refunds'] = round($totals['refunds'], 2);
        $totals['average_ticket'] = $totals['tickets'] > 0 ? round($totals['gross_sales'] / $totals['tickets'], 2) : 0.0;
        $totals['net_sales'] = round($totals['gross_sales'] - $totals['refunds'], 2) + 0.0;
        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => [$b['net_sales'], $a['name'], $a['user_id']] <=> [$a['net_sales'], $b['name'], $b['user_id']]);

        return ['data' => $this->range($filter) + ['rows' => $rows, 'totals' => $totals]] + $this->quality($filter);
    }

    /**
     * Base, VAT and total per rate of the sales (the sum of the line bases) and of the
     * refunds (each refund line split at its rate), and the net. The approximate amount
     * adds the refund lines whose rate is a guess (no sale line and no catalog prices).
     *
     * @return array{data: array<string, mixed>, approximate_amount: float, missing_lines: int}
     */
    private function vat(ReportFilter $filter): array
    {
        [$lineWhere, $lineParams] = $this->where('l.sold_at', 'l.terminal_id', 'l.user_id', $filter);
        $empty = ['base' => 0.0, 'vat' => 0.0, 'total' => 0.0];
        $rows = [];
        $row = static fn (float $rate): array => ['rate' => $rate, 'sales' => $empty, 'refunds' => $empty, 'net' => $empty];
        $approximate = 0.0;

        foreach ($this->connection->fetchAllAssociative(
            'SELECT l.vat_rate, l.base_amount, l.vat_amount, l.paid_amount FROM sale_line l WHERE ' . $lineWhere . ' AND (l.vat_rate IS NULL OR l.base_amount IS NULL)',
            $lineParams
        ) as $line) {
            // A line without its VAT split: the assumed rate, counted as approximate.
            $rate = $line['vat_rate'] !== null ? (float) $line['vat_rate'] : VatRate::ASSUMED;
            $split = VatRate::split((float) $line['paid_amount'], $rate);
            $rows[(string) $rate] ??= $row($rate);
            $rows[(string) $rate]['sales'] = $this->addSplit($rows[(string) $rate]['sales'], $split['base'], $split['vat'], (float) $line['paid_amount']);
            $approximate += (float) $line['paid_amount'];
        }
        foreach ($this->connection->fetchAllAssociative(
            'SELECT l.vat_rate, SUM(l.base_amount) AS base, SUM(l.vat_amount) AS vat, SUM(l.paid_amount) AS paid
             FROM sale_line l WHERE ' . $lineWhere . ' AND l.vat_rate IS NOT NULL AND l.base_amount IS NOT NULL GROUP BY l.vat_rate',
            $lineParams
        ) as $line) {
            $rate = (float) $line['vat_rate'];
            $rows[(string) $rate] ??= $row($rate);
            $rows[(string) $rate]['sales'] = $this->addSplit($rows[(string) $rate]['sales'], (float) $line['base'], (float) $line['vat'], (float) $line['paid']);
        }
        foreach ($this->refundLines($filter) as $refund) {
            $rate = $refund['vat_rate'];
            $split = VatRate::split($refund['amount'], $rate);
            $rows[(string) $rate] ??= $row($rate);
            $rows[(string) $rate]['refunds'] = $this->addSplit($rows[(string) $rate]['refunds'], $split['base'], $split['vat'], $refund['amount']);
            if ($refund['vat_approximate']) {
                $approximate += $refund['amount'];
            }
        }

        $totals = ['sales' => $empty, 'refunds' => $empty, 'net' => $empty];
        foreach ($rows as &$r) {
            foreach (['base', 'vat', 'total'] as $part) {
                $r['net'][$part] = round($r['sales'][$part] - $r['refunds'][$part], 2) + 0.0;
            }
            foreach (['sales', 'refunds', 'net'] as $side) {
                $totals[$side] = $this->addSplit($totals[$side], $r[$side]['base'], $r[$side]['vat'], $r[$side]['total']);
            }
        }
        unset($r);
        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => $b['rate'] <=> $a['rate']);

        $quality = $this->quality($filter);
        $quality['approximate_amount'] = round($approximate + $quality['approximate_amount'], 2);

        return ['data' => $this->range($filter) + ['rows' => $rows, 'totals' => $totals]] + $quality;
    }

    /**
     * @param array{base: float, vat: float, total: float} $split
     *
     * @return array{base: float, vat: float, total: float}
     */
    private function addSplit(array $split, float $base, float $vat, float $total): array
    {
        return [
            'base' => round($split['base'] + $base, 2) + 0.0,
            'vat' => round($split['vat'] + $vat, 2) + 0.0,
            'total' => round($split['total'] + $total, 2) + 0.0,
        ];
    }

    /**
     * The lines of the done, not online refunds of the period, each with the
     * reference, category and VAT rate of the sale line it returns or, without one
     * (refund without ticket, or a sale without lines), of the current catalog.
     *
     * @return list<array{product_id: ?int, id_product_attribute: int, name: string, quantity: int, amount: float, reference: ?string, category_id: ?int, category_name: string, vat_rate: float, vat_approximate: bool}>
     */
    private function refundLines(ReportFilter $filter): array
    {
        [$refundWhere, $refundParams] = $this->where('r.completed_at', 'r.terminal_id', 'r.user_id', $filter);
        $lines = $this->connection->fetchAllAssociative(
            "SELECT rl.product_id, rl.id_product_attribute, rl.name, rl.quantity, rl.amount,
                    sl.id AS sale_line_id, sl.reference AS sale_reference, sl.category_id AS sale_category_id, sl.category_name AS sale_category_name,
                    sl.vat_rate AS sale_vat_rate, sl.approximate AS sale_approximate
             FROM refund r INNER JOIN refund_line rl ON rl.refund_id = r.id
             LEFT JOIN sale_line sl ON r.order_id IS NOT NULL AND sl.order_id = r.order_id AND sl.line_index = rl.line_index
             WHERE r.status = 'done' AND r.online = 0 AND " . $refundWhere . '
             ORDER BY rl.id',
            $refundParams
        );

        $result = [];
        $catalogs = [];
        foreach ($lines as $line) {
            $productId = (int) $line['product_id'] > 0 ? (int) $line['product_id'] : null;
            $attribute = (int) $line['id_product_attribute'];
            $name = \is_string($line['name']) && $line['name'] !== '' ? $line['name'] : 'Producto';
            if ($line['sale_line_id'] !== null) {
                $rate = $line['sale_vat_rate'] !== null ? (float) $line['sale_vat_rate'] : VatRate::ASSUMED;
                $result[] = [
                    'product_id' => $productId,
                    'id_product_attribute' => $attribute,
                    'name' => $name,
                    'quantity' => (int) $line['quantity'],
                    'amount' => (float) $line['amount'],
                    'reference' => \is_string($line['sale_reference']) && $line['sale_reference'] !== '' ? $line['sale_reference'] : null,
                    'category_id' => $line['sale_category_id'] !== null ? (int) $line['sale_category_id'] : null,
                    'category_name' => $line['sale_category_id'] !== null ? (string) ($line['sale_category_name'] ?? '') : self::UNCATEGORISED,
                    'vat_rate' => $rate,
                    'vat_approximate' => $line['sale_vat_rate'] === null || (int) $line['sale_approximate'] === 1,
                ];
                continue;
            }
            $catalog = $catalogs[$productId . ':' . $attribute] ??= $this->currentCatalog($productId, $attribute);
            $result[] = [
                'product_id' => $productId,
                'id_product_attribute' => $attribute,
                'name' => $name,
                'quantity' => (int) $line['quantity'],
                'amount' => (float) $line['amount'],
            ] + $catalog;
        }

        return $result;
    }

    /**
     * Reference, category and VAT rate of a product (or combination) as the catalog
     * has them now. A product no longer in the catalog: «Sin categoría» at the
     * assumed 21 %, approximate.
     *
     * @return array{reference: ?string, category_id: ?int, category_name: string, vat_rate: float, vat_approximate: bool}
     */
    private function currentCatalog(?int $productId, int $attribute): array
    {
        $none = ['reference' => null, 'category_id' => null, 'category_name' => self::UNCATEGORISED, 'vat_rate' => VatRate::ASSUMED, 'vat_approximate' => true];
        if ($productId === null) {
            return $none;
        }
        $product = $this->connection->fetchAssociative(
            'SELECT p.reference, p.price_ttc, p.price_net, p.id_category_default, c.id AS category_id, c.name AS category_name
             FROM catalog_product p LEFT JOIN catalog_category c ON c.id = p.id_category_default WHERE p.id = ?',
            [$productId]
        );
        if ($product === false) {
            return $none;
        }
        $reference = \is_string($product['reference']) && $product['reference'] !== '' ? $product['reference'] : null;
        [$ttc, $net] = [$product['price_ttc'], $product['price_net']];
        if ($attribute > 0) {
            $combination = $this->connection->fetchAssociative('SELECT reference, price_ttc, price_net FROM catalog_combination WHERE id = ? AND id_product = ?', [$attribute, $productId]);
            if ($combination !== false) {
                $reference = \is_string($combination['reference']) && $combination['reference'] !== '' ? $combination['reference'] : $reference;
                if (is_numeric($combination['price_ttc']) && is_numeric($combination['price_net'])) {
                    [$ttc, $net] = [$combination['price_ttc'], $combination['price_net']];
                }
            }
        }
        $vat = is_numeric($ttc)
            ? VatRate::fromPrices((float) $ttc, is_numeric($net) ? (float) $net : null)
            : ['rate' => VatRate::ASSUMED, 'approximate' => true];
        $categoryId = $product['category_id'] !== null ? (int) $product['category_id'] : null;

        return [
            'reference' => $reference,
            'category_id' => $categoryId,
            'category_name' => $categoryId !== null ? (string) $product['category_name'] : self::UNCATEGORISED,
            'vat_rate' => $vat['rate'],
            'vat_approximate' => $vat['approximate'],
        ];
    }

    /** @return array<string, mixed> */
    private function productRow(?int $productId, ?int $attribute, ?string $reference, string $name): array
    {
        return [
            'product_id' => $productId,
            'id_product_attribute' => $attribute,
            'reference' => $reference,
            'name' => $name,
            'free_item' => $productId === null,
            'units_sold' => 0,
            'units_refunded' => 0,
            'units_net' => 0,
            'sales_amount' => 0.0,
            'refunds_amount' => 0.0,
            'net_amount' => 0.0,
            'percent' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function categoryRow(?int $id, string $name): array
    {
        return [
            'category_id' => $id,
            'name' => $id === null || $name === '' ? self::UNCATEGORISED : $name,
            'units_sold' => 0,
            'units_refunded' => 0,
            'units_net' => 0,
            'sales_amount' => 0.0,
            'refunds_amount' => 0.0,
            'net_amount' => 0.0,
            'percent' => null,
        ];
    }

    /**
     * Grouped by product, a row shows the product's current catalog name and
     * reference (its lines may name a combination); the stored ones otherwise.
     *
     * @param array<string, array<string, mixed>> $rows
     */
    private function useCatalogNames(array &$rows): void
    {
        $ids = array_values(array_filter(array_map(static fn (array $row): ?int => $row['product_id'], $rows)));
        if ($ids === []) {
            return;
        }
        $catalog = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, name, reference FROM catalog_product WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]) as $product) {
            $catalog[(int) $product['id']] = $product;
        }
        foreach ($rows as &$row) {
            $product = $row['product_id'] !== null ? ($catalog[$row['product_id']] ?? null) : null;
            if ($product === null) {
                continue;
            }
            if (\is_string($product['name']) && $product['name'] !== '') {
                $row['name'] = $product['name'];
            }
            if (\is_string($product['reference']) && $product['reference'] !== '') {
                $row['reference'] = $product['reference'];
            }
        }
        unset($row);
    }

    /**
     * @param array<string, int|float> $totals
     *
     * @return array<string, int|float>
     */
    private function roundTotals(array $totals): array
    {
        $totals['units_net'] = $totals['units_sold'] - $totals['units_refunded'];
        $totals['sales_amount'] = round($totals['sales_amount'], 2);
        $totals['refunds_amount'] = round($totals['refunds_amount'], 2);
        $totals['net_amount'] = round($totals['sales_amount'] - $totals['refunds_amount'], 2) + 0.0;

        return $totals;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function withPercent(array $rows, float $total): array
    {
        foreach ($rows as &$row) {
            $row['percent'] = $total == 0.0 ? null : round($row['net_amount'] / $total * 100, 2) + 0.0;
        }
        unset($row);

        return $rows;
    }

    /**
     * The page of rows the cursor (an offset) points at; a CSV export has them all.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array{0: list<array<string, mixed>>, 1: array{cursor: string, size: int, total_rows: int, next_cursor: ?string, previous_cursor: ?string}}
     */
    private function page(array $rows, int $total, ReportFilter $filter): array
    {
        if ($filter->format === 'csv') {
            return [$rows, ['cursor' => '0', 'size' => $total, 'total_rows' => $total, 'next_cursor' => null, 'previous_cursor' => null]];
        }
        $cursor = $filter->cursor;
        $next = $cursor + self::PAGE_SIZE;

        return [
            \array_slice($rows, $cursor, self::PAGE_SIZE),
            [
                'cursor' => (string) $cursor,
                'size' => self::PAGE_SIZE,
                'total_rows' => $total,
                'next_cursor' => $next < $total ? (string) $next : null,
                'previous_cursor' => $cursor > 0 ? (string) max(0, $cursor - self::PAGE_SIZE) : null,
            ],
        ];
    }

    /**
     * Same rules as the X/Z report (§5): by session it reproduces that session's Z.
     * It reads no sale_line, so neither approximate lines nor missing lines affect it.
     *
     * @return array{data: array<string, mixed>, approximate_amount: float, missing_lines: int}
     */
    private function payments(ReportFilter $filter): array
    {
        $session = null;
        if ($filter->sessionId !== null) {
            $row = $this->connection->fetchAssociative(
                'SELECT s.id, s.terminal_id, t.name AS terminal_name, s.opened_at, s.closed_at
                 FROM session s LEFT JOIN terminal t ON t.id = s.terminal_id WHERE s.id = ?',
                [$filter->sessionId]
            );
            if ($row === false) {
                throw new ReportNotFound('Sesión de caja no encontrada');
            }
            $session = [
                'session_id' => (int) $row['id'],
                'terminal_id' => (int) $row['terminal_id'],
                'terminal_name' => (string) ($row['terminal_name'] ?? ''),
                'opened_at' => (string) $row['opened_at'],
                'closed_at' => $row['closed_at'] !== null ? (string) $row['closed_at'] : null,
            ];
            $scope = CashTotalsScope::session($filter->sessionId);
            $saleWhere = 'o.session_id = :session';
            $saleParams = ['session' => $filter->sessionId];
        } else {
            $scope = CashTotalsScope::period($filter->from(), $filter->toExclusive(), $filter->terminalId);
            [$saleWhere, $saleParams] = $this->where('o.created_at', 'o.terminal_id', 'o.user_id', $filter);
        }

        $totals = $this->totals->totals($scope);
        $data = [];
        foreach (['sales_cash', 'sales_card', 'sales_transfer', 'sales_transfer_pending', 'sales_total', 'exchange_credit', 'refunds_cash', 'refunds_card', 'refunds_transfer', 'refunds_voucher', 'refunds_total',
            'exchange_vouchers', 'refunds_web_pending', 'net_total', 'cash_in_total', 'cash_out_total'] as $key) {
            $data[$key] = (float) $totals[$key];
        }

        $methods = array_fill_keys(self::METHODS, 0);
        $rows = $this->connection->fetchAllAssociative(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(o.payment_breakdown, '$.payment_method')) AS method, COUNT(*) AS tickets
             FROM order_meta o WHERE " . $saleWhere . ' GROUP BY method',
            $saleParams
        );
        foreach ($rows as $row) {
            $methods[(string) ($row['method'] ?? 'unknown')] = (int) $row['tickets'];
        }
        $data['tickets_by_method'] = $methods;
        $data['session'] = $session;

        return [
            'data' => $this->range($filter) + $data,
            'approximate_amount' => 0.0,
            'missing_lines' => 0,
        ];
    }

    /**
     * The §3 metrics of a period, in the order the screen and the CSV show them.
     *
     * @return array{gross_before_discounts: float, discounts: float, gross_sales: float, refunds: float, net_sales: float, tickets: int, average_ticket: float, units_sold: int, units_refunded: int, units_net: int, exchange_credit: float, online_refunds: float}
     */
    private function metrics(ReportFilter $filter): array
    {
        [$lineWhere, $lineParams] = $this->where('l.sold_at', 'l.terminal_id', 'l.user_id', $filter);
        [$saleWhere, $saleParams] = $this->where('o.created_at', 'o.terminal_id', 'o.user_id', $filter);
        [$refundWhere, $refundParams] = $this->where('r.completed_at', 'r.terminal_id', 'r.user_id', $filter);

        $lines = $this->connection->fetchAssociative(
            'SELECT COALESCE(SUM(l.gross_amount), 0) AS gross, COALESCE(SUM(l.paid_amount), 0) AS paid, COALESCE(SUM(l.quantity), 0) AS units
             FROM sale_line l WHERE ' . $lineWhere,
            $lineParams
        );
        $sales = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS tickets,
                    COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(o.payment_breakdown, '$.tender.exchange_credit')) AS DECIMAL(10,2))), 0) AS credit
             FROM order_meta o WHERE " . $saleWhere,
            $saleParams
        );
        $refunds = $this->connection->fetchAssociative(
            "SELECT COALESCE(SUM(CASE WHEN r.online = 0 THEN r.total ELSE 0 END), 0) AS refunds,
                    COALESCE(SUM(CASE WHEN r.online = 1 THEN COALESCE(r.web_refund_amount, 0) ELSE 0 END), 0) AS online
             FROM refund r WHERE r.status = 'done' AND " . $refundWhere,
            $refundParams
        );
        $unitsRefunded = (int) $this->connection->fetchOne(
            "SELECT COALESCE(SUM(rl.quantity), 0) FROM refund r INNER JOIN refund_line rl ON rl.refund_id = r.id
             WHERE r.status = 'done' AND r.online = 0 AND " . $refundWhere,
            $refundParams
        );

        $before = round((float) $lines['gross'], 2);
        $gross = round((float) $lines['paid'], 2);
        $refunded = round((float) $refunds['refunds'], 2);
        $tickets = (int) $sales['tickets'];
        $unitsSold = (int) $lines['units'];

        return [
            'gross_before_discounts' => $before,
            'discounts' => round($before - $gross, 2) + 0.0,
            'gross_sales' => $gross,
            'refunds' => $refunded,
            'net_sales' => round($gross - $refunded, 2) + 0.0,
            'tickets' => $tickets,
            'average_ticket' => $tickets > 0 ? round($gross / $tickets, 2) : 0.0,
            'units_sold' => $unitsSold,
            'units_refunded' => $unitsRefunded,
            'units_net' => $unitsSold - $unitsRefunded,
            'exchange_credit' => round((float) $sales['credit'], 2),
            'online_refunds' => round((float) $refunds['online'], 2),
        ];
    }

    /**
     * Sales and refunds of the period still in the outbox (pending, processing or
     * incident): they explain differences with PrestaShop, and still count here.
     *
     * @return array{sales: int, refunds: int}
     */
    private function pendingUpload(ReportFilter $filter): array
    {
        [$saleWhere, $saleParams] = $this->where('o.created_at', 'o.terminal_id', 'o.user_id', $filter);
        [$refundWhere, $refundParams] = $this->where('r.completed_at', 'r.terminal_id', 'r.user_id', $filter);

        return [
            'sales' => (int) $this->connection->fetchOne(
                "SELECT COUNT(DISTINCT o.id) FROM order_meta o INNER JOIN outbox x ON x.order_id = o.id AND x.type = 'sale'
                 WHERE " . $saleWhere . ' AND x.status IN ' . self::PENDING,
                $saleParams
            ),
            // A refund's upload carries the refund's own request_id; one refund may have
            // both a refund and a restock row, and counts once.
            'refunds' => (int) $this->connection->fetchOne(
                "SELECT COUNT(DISTINCT r.id) FROM refund r INNER JOIN outbox x ON x.request_id = r.request_id AND x.type IN ('refund', 'restock')
                 WHERE r.status = 'done' AND " . $refundWhere . ' AND x.status IN ' . self::PENDING,
                $refundParams
            ),
        ];
    }

    /**
     * The amount of the period that comes from approximate lines (old sales backfilled
     * from the current catalog) and the sales that have no lines yet.
     *
     * @return array{approximate_amount: float, missing_lines: int}
     */
    private function quality(ReportFilter $filter): array
    {
        [$lineWhere, $lineParams] = $this->where('l.sold_at', 'l.terminal_id', 'l.user_id', $filter);
        [$saleWhere, $saleParams] = $this->where('o.created_at', 'o.terminal_id', 'o.user_id', $filter);

        return [
            'approximate_amount' => round((float) $this->connection->fetchOne(
                'SELECT COALESCE(SUM(l.paid_amount), 0) FROM sale_line l WHERE ' . $lineWhere . ' AND l.approximate = 1',
                $lineParams
            ), 2),
            'missing_lines' => (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM order_meta o WHERE ' . $saleWhere . ' AND NOT EXISTS (SELECT 1 FROM sale_line l WHERE l.order_id = o.id)',
                $saleParams
            ),
        ];
    }

    /**
     * The half-open range on the date column itself, plus the till and cashier filters.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function where(string $dateColumn, string $terminalColumn, string $userColumn, ReportFilter $filter): array
    {
        $sql = $dateColumn . ' >= :from AND ' . $dateColumn . ' < :to';
        $params = [
            'from' => $filter->from()->format('Y-m-d H:i:s'),
            'to' => $filter->toExclusive()->format('Y-m-d H:i:s'),
        ];
        if ($filter->terminalId !== null) {
            $sql .= ' AND ' . $terminalColumn . ' = :terminal';
            $params['terminal'] = $filter->terminalId;
        }
        if ($filter->userId !== null) {
            $sql .= ' AND ' . $userColumn . ' = :user';
            $params['user'] = $filter->userId;
        }

        return [$sql, $params];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, array<string, mixed>> rows keyed by their Y-m-d day
     */
    private function byDay(string $sql, array $params): array
    {
        $rows = [];
        foreach ($this->connection->fetchAllAssociative($sql, $params) as $row) {
            $rows[(string) $row['day']] = $row;
        }

        return $rows;
    }

    /** @return array{from: ?string, to: ?string} */
    private function range(ReportFilter $filter): array
    {
        return ['from' => $filter->from()?->format('Y-m-d'), 'to' => $filter->to()?->format('Y-m-d')];
    }
}
