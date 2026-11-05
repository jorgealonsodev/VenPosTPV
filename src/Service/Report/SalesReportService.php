<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Report;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Cash\CashTotalsAggregator;
use VenPosTPV\Service\Cash\CashTotalsScope;

/**
 * The sales reports of the till (docs/prd/informes-ventas.md I2, metrics in §3):
 * Resumen, Por día and Por forma de pago, read from the local database only.
 *
 * Sales come from sale_line by sold_at and tickets from order_meta by created_at;
 * refunds count on the day they were made (refund.completed_at), only when done and
 * not online. Every query filters with a half-open range on the indexed column
 * itself; DATE() appears only in GROUP BY (I6).
 */
class SalesReportService
{
    public const TYPES = ['summary', 'daily', 'payments'];

    /** The payment methods of a sale, always listed even when no ticket used them. */
    private const METHODS = ['cash', 'card', 'mixed', 'exchange'];

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
            'payments' => $this->payments($filter),
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
        foreach (['sales_cash', 'sales_card', 'sales_total', 'exchange_credit', 'refunds_cash', 'refunds_card', 'refunds_voucher', 'refunds_total',
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
