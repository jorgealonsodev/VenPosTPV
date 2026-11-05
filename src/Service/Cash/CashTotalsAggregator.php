<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Cash;

use Doctrine\DBAL\Connection;

/**
 * The money totals of the till: cash, card, voucher and exchange credit of the sales
 * and refunds. One implementation for the X/Z report of a cash session and for the
 * sales report «Por forma de pago» of a date range (docs/prd/informes-ventas.md §5),
 * so the report filtered by session always matches the Z of that session.
 *
 * A session is selected by its id. A period selects movements by their date within
 * the sessions open in it, sales by order_meta.created_at, refunds by
 * refund.completed_at and exchange vouchers by exchange.completed_at, always with a
 * half-open range on the column itself so its index can be used.
 */
class CashTotalsAggregator
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The keys the Z stores in z_report.totals, plus exchange_vouchers. A period has no
     * drawer, so it has no opening_cash nor expected_cash.
     *
     * @param bool $lockMovements lock the session's movements (only for a session, inside the close)
     *
     * @return array<string,mixed>
     */
    public function totals(CashTotalsScope $scope, bool $lockMovements = false): array
    {
        [$movementWhere, $movementParams] = $this->movementWhere($scope);
        $sql = 'SELECT m.type, COALESCE(SUM(m.amount),0) as amount, COUNT(*) as cnt FROM movement m WHERE ' . $movementWhere . ' GROUP BY m.type';
        if ($lockMovements && $scope->isSession()) {
            $sql .= ' FOR UPDATE';
        }
        $rows = $this->connection->fetchAllAssociative($sql, $movementParams);

        $sales = 0.0;
        $refunds = 0.0;
        $cashIn = 0.0;
        $cashOut = 0.0;
        $counts = ['sale' => 0, 'refund' => 0, 'cash_in' => 0, 'cash_out' => 0];

        foreach ($rows as $row) {
            $type = (string) $row['type'];
            $amount = (float) $row['amount'];
            $counts[$type] = (int) $row['cnt'];
            if ($type === 'sale') {
                $sales = $amount;
            } elseif ($type === 'refund') {
                $refunds = $amount;
            } elseif ($type === 'cash_in') {
                $cashIn = $amount;
            } elseif ($type === 'cash_out') {
                $cashOut = $amount;
            }
        }

        [$saleWhere, $saleParams] = $this->saleWhere($scope);
        [$refundWhere, $refundParams] = $this->refundWhere($scope);

        // Card amounts never touch the drawer, so they come from the sales and
        // refunds themselves; expected_cash below stays cash-only.
        $salesCard = (float) $this->connection->fetchOne(
            "SELECT COALESCE(SUM(COALESCE(
                 CAST(JSON_UNQUOTE(JSON_EXTRACT(o.payment_breakdown, '$.tender.card')) AS DECIMAL(10,2)),
                 CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(o.payment_breakdown, '$.payment_method')) = 'card'
                      THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(o.payment_breakdown, '$.total')) AS DECIMAL(10,2)) ELSE 0 END
             )), 0)
             FROM order_meta o WHERE " . $saleWhere,
            $saleParams
        );
        $refundsCard = (float) $this->connection->fetchOne(
            "SELECT COALESCE(SUM(r.card_amount), 0) FROM refund r WHERE r.status = 'done' AND " . $refundWhere,
            $refundParams
        );
        // Vouchers (refunds without ticket) are neither cash nor card.
        $refundsVoucher = (float) $this->connection->fetchOne(
            "SELECT COALESCE(SUM(r.voucher_amount), 0) FROM refund r WHERE r.status = 'done' AND " . $refundWhere,
            $refundParams
        );
        $counts['refunds'] = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM refund r WHERE r.status = 'done' AND " . $refundWhere,
            $refundParams
        );

        // Exchanges: the part of the returned goods that paid new items. It is money
        // neither in nor out (sales and refunds above only count what was collected
        // or paid back), so it is shown apart and never touches the drawer. An
        // exchange closed with a voucher spent no credit on new items: its credit is
        // counted once, as the voucher, in the session that issued it.
        $exchangeCredit = 0.0;
        $counts['exchanges'] = 0;
        try {
            $spent = "FROM refund r LEFT JOIN exchange e ON e.refund_id = r.id
                 WHERE r.status = 'done' AND " . $refundWhere . ' AND r.exchange_credit IS NOT NULL
                   AND (e.status IS NULL OR e.status <> \'closed_voucher\')';
            $exchangeCredit = (float) $this->connection->fetchOne('SELECT COALESCE(SUM(r.exchange_credit), 0) ' . $spent, $refundParams);
            $counts['exchanges'] = (int) $this->connection->fetchOne('SELECT COUNT(*) ' . $spent, $refundParams);
        } catch (\Throwable) {
            // Database not migrated to exchanges yet: there are none.
        }
        $exchangeVouchers = 0.0;
        try {
            [$voucherWhere, $voucherParams] = $this->exchangeVoucherWhere($scope);
            $exchangeVouchers = (float) $this->connection->fetchOne(
                "SELECT COALESCE(SUM(e.voucher_amount), 0) FROM exchange e WHERE e.status = 'closed_voucher' AND " . $voucherWhere,
                $voucherParams
            );
        } catch (\Throwable) {
            // Database not migrated to exchange vouchers yet: there are none.
        }
        $refundsVoucher += $exchangeVouchers;

        $salesTotal = round($sales + $salesCard, 2);
        $refundsTotal = round($refunds + $refundsCard + $refundsVoucher, 2);

        // Online orders returned at the shop (R10): no money moved here, the office
        // refunds them from the web. Informative only; no money key includes it.
        try {
            $refundsWebPending = (float) $this->connection->fetchOne(
                "SELECT COALESCE(SUM(r.web_refund_amount), 0) FROM refund r WHERE r.status = 'done' AND r.online = 1 AND " . $refundWhere,
                $refundParams
            );
        } catch (\Throwable) {
            // Database not migrated to online refunds yet: there are none.
            $refundsWebPending = 0.0;
        }

        $totals = [];
        if ($scope->isSession()) {
            $openingCash = (float) $this->connection->fetchOne('SELECT opening_cash FROM session WHERE id = ?', [$scope->sessionId]);
            $totals['opening_cash'] = round($openingCash, 2);
        }
        $totals += [
            'sales_cash' => round($sales, 2),
            'sales_card' => round($salesCard, 2),
            'sales_total' => $salesTotal,
            'refunds_cash' => round($refunds, 2),
            'refunds_card' => round($refundsCard, 2),
            'refunds_voucher' => round($refundsVoucher, 2),
            'refunds_total' => $refundsTotal,
            'refunds_web_pending' => round($refundsWebPending, 2),
            'net_total' => round($salesTotal - $refundsTotal, 2),
            'exchange_credit' => round($exchangeCredit, 2),
            'cash_in_total' => round($cashIn, 2),
            'cash_out_total' => round($cashOut, 2),
        ];
        if ($scope->isSession()) {
            $totals['expected_cash'] = round($openingCash + $cashIn - $cashOut + $sales - $refunds, 2);
        }
        // Part of refunds_voucher: the vouchers of exchanges closed with a voucher in
        // the scope. The Z does not store it apart (CashSessionService drops it).
        $totals['exchange_vouchers'] = round($exchangeVouchers, 2);
        $totals['counts'] = $counts;

        return $totals;
    }

    /**
     * Movements have no date index: a period takes the movements of the sessions open
     * in it (session_id is indexed) whose own date falls in the range.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function movementWhere(CashTotalsScope $scope): array
    {
        if ($scope->isSession()) {
            return ['m.session_id = :session', ['session' => $scope->sessionId]];
        }
        $params = ['from' => $scope->from, 'to' => $scope->to];
        $terminal = '';
        if ($scope->terminalId !== null) {
            $terminal = ' AND s.terminal_id = :terminal';
            $params['terminal'] = $scope->terminalId;
        }

        return [
            'm.session_id IN (SELECT s.id FROM session s WHERE s.opened_at < :to AND (s.closed_at IS NULL OR s.closed_at >= :from)' . $terminal . ')
             AND m.created_at >= :from AND m.created_at < :to',
            $params,
        ];
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function saleWhere(CashTotalsScope $scope): array
    {
        if ($scope->isSession()) {
            return ['o.session_id = :session', ['session' => $scope->sessionId]];
        }

        return $this->periodWhere('o.created_at', 'o.terminal_id', $scope);
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function refundWhere(CashTotalsScope $scope): array
    {
        if ($scope->isSession()) {
            return ['r.session_id = :session', ['session' => $scope->sessionId]];
        }

        return $this->periodWhere('r.completed_at', 'r.terminal_id', $scope);
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function exchangeVoucherWhere(CashTotalsScope $scope): array
    {
        if ($scope->isSession()) {
            return ['e.voucher_session_id = :session', ['session' => $scope->sessionId]];
        }
        $params = ['from' => $scope->from, 'to' => $scope->to];
        $sql = 'e.completed_at >= :from AND e.completed_at < :to';
        if ($scope->terminalId !== null) {
            $sql .= ' AND e.voucher_session_id IN (SELECT s.id FROM session s WHERE s.terminal_id = :terminal)';
            $params['terminal'] = $scope->terminalId;
        }

        return [$sql, $params];
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function periodWhere(string $dateColumn, string $terminalColumn, CashTotalsScope $scope): array
    {
        $params = ['from' => $scope->from, 'to' => $scope->to];
        $sql = $dateColumn . ' >= :from AND ' . $dateColumn . ' < :to';
        if ($scope->terminalId !== null) {
            $sql .= ' AND ' . $terminalColumn . ' = :terminal';
            $params['terminal'] = $scope->terminalId;
        }

        return [$sql, $params];
    }
}
