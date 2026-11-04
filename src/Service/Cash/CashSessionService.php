<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Cash;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Cash\Exception\NoOpenCashSessionException;
use VenPosTPV\Service\Device\DeviceContext;
use VenPosTPV\Service\Device\DeviceNotLinkedException;
use Symfony\Component\HttpFoundation\RequestStack;

class CashSessionService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TerminalService $terminalService,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** @return array<string,mixed> */
    public function openSession(int $userId, float $openingCash): array
    {
        $shopId = $this->requireShopId();
        $terminalId = $this->deviceTerminalId() ?? $this->terminalService->resolveForShop($shopId);

        return $this->connection->transactional(function () use ($userId, $openingCash, $terminalId): array {
            $active = $this->connection->fetchOne(
                'SELECT id FROM session WHERE terminal_id = ? AND closed_at IS NULL LIMIT 1',
                [$terminalId]
            );

            if ($active !== false) {
                throw new \RuntimeException('Cash session already open for terminal');
            }

            $openedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->connection->insert('session', [
                'terminal_id' => $terminalId,
                'user_id' => $userId,
                'opened_at' => $openedAt,
                'opening_cash' => round($openingCash, 2),
            ]);

            $sessionId = (int) $this->connection->lastInsertId();
            $this->cacheSession($terminalId, $sessionId, $openedAt, $openingCash);

            return [
                'session_id' => $sessionId,
                'terminal_id' => $terminalId,
                'opened_at' => $openedAt,
                'opening_cash' => round($openingCash, 2),
            ];
        });
    }

    /** @return array<string,mixed>|null */
    /**
     * Resolves the open session of the terminal the cashier is working on.
     *
     * A drawer is physical: one open session belongs to the terminal, not to the
     * user who opened it. Any active cashier on that terminal operates it, and every
     * movement still records the user who performed it. Looking it up by user left
     * the terminal unusable for everyone else: the screen asked to open a session
     * that the service refused to open because one was already active.
     */
    public function getCurrentSession(int $userId): ?array
    {
        $session = $this->findCurrentSession();
        if ($session === null) {
            return null;
        }

        return $this->buildCurrentState($session);
    }

    /** @return array<string,mixed> */
    public function requireOpenSession(int $userId): array
    {
        $session = $this->findCurrentSession();
        if ($session === null) {
            throw new NoOpenCashSessionException();
        }

        return [
            'session_id' => (int) $session['id'],
            'terminal_id' => (int) $session['terminal_id'],
            'shop_id' => $this->requireShopId(),
            'user_id' => $userId,
            'opened_by_user_id' => isset($session['user_id']) ? (int) $session['user_id'] : null,
            'opened_by' => isset($session['opened_by']) ? (string) $session['opened_by'] : null,
        ];
    }

    /** @return array<string,mixed> */
    public function addMovement(int $userId, string $type, float $amount, string $concept = '', ?int $psOrderId = null): array
    {
        $this->rejectExistingTransaction();
        $this->requireShopId();

        $state = $this->connection->transactional(function () use ($type, $amount, $concept, $psOrderId): array {
            $session = $this->lockCurrentSession();
            if ($session === null) {
                throw new NoOpenCashSessionException();
            }

            $this->connection->insert('movement', [
                'session_id' => (int) $session['id'],
                'type' => $type,
                'amount' => round($amount, 2),
                'concept' => $concept !== '' ? $concept : null,
                'ps_order_id' => $psOrderId,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            return $this->buildCurrentState($session, true);
        });

        $this->cacheSession((int) $state['terminal_id'], (int) $state['session_id'], (string) $state['opened_at'], (float) $state['opening_cash']);

        return $state;
    }

    /**
     * Locks the row of a still open session inside the caller's transaction, so a
     * concurrent close waits until the caller commits and cannot miss what the
     * caller books against it. Lock the session before any order_meta row.
     *
     * @throws \LogicException            outside a transaction
     * @throws NoOpenCashSessionException when the session is unknown or already closed
     */
    public function lockOpenSessionInTransaction(int $sessionId): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('lockOpenSessionInTransaction() must run inside the caller transaction');
        }
        if ($sessionId < 1 || $this->connection->fetchOne('SELECT id FROM session WHERE id = ? AND closed_at IS NULL FOR UPDATE', [$sessionId]) === false) {
            throw new NoOpenCashSessionException();
        }
    }

    /**
     * Books a movement as part of a caller's transaction (a local-first sale stores
     * its sale, cash, stock and upload together). Same invariant as addMovement():
     * the session must still be open, and its row stays locked until the caller
     * commits, so a concurrent close cannot miss the movement.
     *
     * @param int|null $orderId the local sale (order_meta.id) of a sale movement
     *
     * @throws \LogicException            outside a transaction
     * @throws NoOpenCashSessionException when the session closed in the meantime
     */
    public function recordMovementInTransaction(int $sessionId, string $type, float $amount, string $concept = '', ?int $psOrderId = null, ?int $orderId = null): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('recordMovementInTransaction() must run inside the caller transaction');
        }
        $this->lockOpenSessionInTransaction($sessionId);

        $this->connection->insert('movement', [
            'session_id' => $sessionId,
            'type' => $type,
            'amount' => round($amount, 2),
            'concept' => $concept !== '' ? $concept : null,
            'ps_order_id' => $psOrderId,
            'order_id' => $orderId,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,mixed> */
    public function closeSession(int $userId, float $countedCash): array
    {
        $this->rejectExistingTransaction();

        $result = $this->connection->transactional(function () use ($countedCash): array {
            $session = $this->lockCurrentSession();
            if ($session === null) {
                throw new \RuntimeException('No open cash session');
            }

            $state = $this->buildCurrentState($session, true);
            $expected = (float) $state['expected_cash'];
            $difference = round($countedCash - $expected, 2);
            $closedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            $this->connection->update('session', [
                'counted_cash' => round($countedCash, 2),
                'expected_cash' => $expected,
                'difference' => $difference,
                'closed_at' => $closedAt,
            ], ['id' => (int) $session['id']]);

            $this->connection->insert('z_report', [
                'session_id' => (int) $session['id'],
                'totals' => json_encode($state['totals'], JSON_THROW_ON_ERROR),
                'signature_hash' => hash('sha256', json_encode($state['totals'], JSON_THROW_ON_ERROR)),
                'generated_at' => $closedAt,
            ]);

            return [
                'session_id' => (int) $session['id'],
                'closed_at' => $closedAt,
                'expected_cash' => $expected,
                'counted_cash' => round($countedCash, 2),
                'difference' => $difference,
            ];
        });

        $this->clearCacheSession();

        return $result;
    }

    /** @return array<string,mixed> */
    public function getXReport(int $userId): array
    {
        $session = $this->findCurrentSession();
        if ($session === null) {
            throw new \RuntimeException('No open cash session');
        }

        return $this->buildCurrentState($session);
    }

    /** @param array<string,mixed> $session */
    private function buildCurrentState(array $session, bool $lockMovements = false): array
    {
        $sid = (int) $session['id'];
        $totals = $this->aggregateTotals($sid, $lockMovements);

        return [
            'session_id' => $sid,
            'terminal_id' => (int) $session['terminal_id'],
            'opened_at' => (string) $session['opened_at'],
            'opening_cash' => (float) $session['opening_cash'],
            'opened_by_user_id' => isset($session['user_id']) ? (int) $session['user_id'] : null,
            'opened_by' => isset($session['opened_by']) ? (string) $session['opened_by'] : null,
            'sales_cash' => $totals['sales_cash'],
            'refunds_cash' => $totals['refunds_cash'],
            'sales_card' => $totals['sales_card'],
            'refunds_card' => $totals['refunds_card'],
            'refunds_voucher' => $totals['refunds_voucher'],
            'net_total' => $totals['net_total'],
            'cash_in_total' => $totals['cash_in_total'],
            'cash_out_total' => $totals['cash_out_total'],
            'expected_cash' => $totals['expected_cash'],
            'totals' => $totals,
            'movements' => $totals['counts'],
        ];
    }

    /** @return array<string,mixed> */
    private function aggregateTotals(int $sessionId, bool $lockMovements = false): array
    {
        $sql = 'SELECT type, COALESCE(SUM(amount),0) as amount, COUNT(*) as cnt FROM movement WHERE session_id = ? GROUP BY type';
        if ($lockMovements) {
            $sql .= ' FOR UPDATE';
        }
        $rows = $this->connection->fetchAllAssociative($sql, [$sessionId]);

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

        $openingCash = (float) $this->connection->fetchOne('SELECT opening_cash FROM session WHERE id = ?', [$sessionId]);
        $expected = round($openingCash + $cashIn - $cashOut + $sales - $refunds, 2);

        // Card amounts never touch the drawer, so they come from the sales and
        // refunds themselves; expected_cash above stays cash-only.
        $salesCard = (float) $this->connection->fetchOne(
            "SELECT COALESCE(SUM(COALESCE(
                 CAST(JSON_UNQUOTE(JSON_EXTRACT(payment_breakdown, '$.tender.card')) AS DECIMAL(10,2)),
                 CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(payment_breakdown, '$.payment_method')) = 'card'
                      THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(payment_breakdown, '$.total')) AS DECIMAL(10,2)) ELSE 0 END
             )), 0)
             FROM order_meta WHERE session_id = ?",
            [$sessionId]
        );
        $refundsCard = (float) $this->connection->fetchOne(
            "SELECT COALESCE(SUM(card_amount), 0) FROM refund WHERE session_id = ? AND status = 'done'",
            [$sessionId]
        );
        // Vouchers (refunds without ticket) are neither cash nor card.
        $refundsVoucher = (float) $this->connection->fetchOne(
            "SELECT COALESCE(SUM(voucher_amount), 0) FROM refund WHERE session_id = ? AND status = 'done'",
            [$sessionId]
        );
        $counts['refunds'] = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM refund WHERE session_id = ? AND status = 'done'",
            [$sessionId]
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
                 WHERE r.session_id = ? AND r.status = 'done' AND r.exchange_credit IS NOT NULL
                   AND (e.status IS NULL OR e.status <> 'closed_voucher')";
            $exchangeCredit = (float) $this->connection->fetchOne('SELECT COALESCE(SUM(r.exchange_credit), 0) ' . $spent, [$sessionId]);
            $counts['exchanges'] = (int) $this->connection->fetchOne('SELECT COUNT(*) ' . $spent, [$sessionId]);
        } catch (\Throwable) {
            // Database not migrated to exchanges yet: there are none.
        }
        try {
            $refundsVoucher += (float) $this->connection->fetchOne(
                "SELECT COALESCE(SUM(voucher_amount), 0) FROM exchange WHERE voucher_session_id = ? AND status = 'closed_voucher'",
                [$sessionId]
            );
        } catch (\Throwable) {
            // Database not migrated to exchange vouchers yet: there are none.
        }

        $salesTotal = round($sales + $salesCard, 2);
        $refundsTotal = round($refunds + $refundsCard + $refundsVoucher, 2);

        // Online orders returned at the shop (R10): no money moved here, the office
        // refunds them from the web. Informative only; no money key includes it.
        try {
            $refundsWebPending = (float) $this->connection->fetchOne(
                "SELECT COALESCE(SUM(web_refund_amount), 0) FROM refund WHERE session_id = ? AND status = 'done' AND online = 1",
                [$sessionId]
            );
        } catch (\Throwable) {
            // Database not migrated to online refunds yet: there are none.
            $refundsWebPending = 0.0;
        }

        return [
            'opening_cash' => round($openingCash, 2),
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
            'expected_cash' => $expected,
            'counts' => $counts,
        ];
    }

    /** @return array<string,mixed>|null */
    /**
     * Locks the open session of this request's terminal, whoever opened it: the
     * drawer belongs to the terminal, and each movement records who acted. Same
     * resolution order as findCurrentSession(), with FOR UPDATE.
     */
    private function lockCurrentSession(): ?array
    {
        $deviceTerminalId = $this->deviceTerminalId();
        $sessionId = $this->cachedSessionId();
        if ($sessionId > 0) {
            $session = $this->connection->fetchAssociative(
                'SELECT id, terminal_id, opened_at, opening_cash FROM session WHERE id = ? AND closed_at IS NULL FOR UPDATE',
                [$sessionId]
            );
            if ($session !== false && ($deviceTerminalId === null || (int) $session['terminal_id'] === $deviceTerminalId)) {
                return $session;
            }
        }

        $terminalId = $deviceTerminalId ?? $this->fallbackTerminalId();

        if ($terminalId > 0) {
            $session = $this->connection->fetchAssociative(
                'SELECT id, terminal_id, opened_at, opening_cash FROM session
                 WHERE terminal_id = ? AND closed_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE',
                [$terminalId]
            );
            if ($session !== false) {
                return $session;
            }
        }

        if ($deviceTerminalId !== null) {
            // A linked computer only ever works on its own till's drawer.
            return null;
        }

        $session = $this->connection->fetchAssociative(
            'SELECT s.id, s.terminal_id, s.opened_at, s.opening_cash
             FROM session s
             INNER JOIN terminal t ON t.id = s.terminal_id
             WHERE s.closed_at IS NULL AND t.ps_shop_id = ?
             ORDER BY s.id DESC LIMIT 1 FOR UPDATE',
            [$this->requireShopId()]
        );

        return $session === false ? null : $session;
    }

    /** @return array<string,mixed>|null */
    /**
     * Finds the open session of the terminal this request belongs to, whoever opened
     * it. The cached session id wins when it is still open; otherwise the terminal of
     * the current shop decides, and a shop-wide lookup is the last resort for a
     * request without terminal context.
     */
    private function findCurrentSession(): ?array
    {
        $select = 'SELECT s.id, s.terminal_id, s.opened_at, s.opening_cash, s.user_id, u.name AS opened_by
                   FROM session s
                   LEFT JOIN user u ON u.id = s.user_id ';

        $deviceTerminalId = $this->deviceTerminalId();
        $sessionId = $this->cachedSessionId();

        if ($sessionId > 0) {
            $session = $this->connection->fetchAssociative(
                $select . 'WHERE s.id = ? AND s.closed_at IS NULL',
                [$sessionId]
            );

            if ($session !== false && ($deviceTerminalId === null || (int) $session['terminal_id'] === $deviceTerminalId)) {
                return $session;
            }
        }

        $terminalId = $deviceTerminalId ?? $this->fallbackTerminalId();

        if ($terminalId > 0) {
            $session = $this->connection->fetchAssociative(
                $select . 'WHERE s.terminal_id = ? AND s.closed_at IS NULL ORDER BY s.id DESC LIMIT 1',
                [$terminalId]
            );

            if ($session !== false) {
                $this->cacheSession((int) $session['terminal_id'], (int) $session['id'], (string) $session['opened_at'], (float) $session['opening_cash']);

                return $session;
            }
        }

        if ($deviceTerminalId !== null) {
            // A linked computer only ever works on its own till's drawer.
            return null;
        }

        $session = $this->connection->fetchAssociative(
            $select . 'INNER JOIN terminal t ON t.id = s.terminal_id
                       WHERE s.closed_at IS NULL AND t.ps_shop_id = ?
                       ORDER BY s.id DESC LIMIT 1',
            [$this->requireShopId()]
        );

        if ($session === false) {
            return null;
        }

        $this->cacheSession((int) $session['terminal_id'], (int) $session['id'], (string) $session['opened_at'], (float) $session['opening_cash']);

        return $session;
    }

    /**
     * The till of the computer behind this request (S10). Null only when the request
     * did not pass DeviceListener (CLI, tests): then the shop's first till is used.
     * A browser without a linked till is refused, never sent to terminal 1.
     */
    private function deviceTerminalId(): ?int
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->attributes->has(DeviceContext::ATTRIBUTE)) {
            return null;
        }

        $terminalId = $request->attributes->get(DeviceContext::ATTRIBUTE);
        if (!\is_int($terminalId) || $terminalId <= 0) {
            throw new DeviceNotLinkedException();
        }

        return $terminalId;
    }

    private function fallbackTerminalId(): int
    {
        try {
            return $this->terminalService->resolveForShop($this->requireShopId());
        } catch (\Throwable) {
            // No shop or terminal context: fall through to the shop-wide lookup.
            return 0;
        }
    }

    private function cachedSessionId(): int
    {
        $request = $this->requestStack->getCurrentRequest();
        $sessionId = 0;
        if ($request && $request->hasSession()) {
            $sessionId = (int) ($request->getSession()->get('cash_session_id') ?? 0);
        }
        if ($sessionId <= 0) {
            $sessionId = isset($_SESSION['cash_session_id']) ? (int) $_SESSION['cash_session_id'] : 0;
        }

        return $sessionId;
    }

    private function rejectExistingTransaction(): void
    {
        if ($this->connection->isTransactionActive()) {
            throw new \RuntimeException('Cash session mutations cannot run inside an existing transaction');
        }
    }
    private function cacheSession(int $terminalId, int $sessionId, string $openedAt, float $openingCash): void
    {
        $_SESSION['terminal_id'] = $terminalId;
        $_SESSION['cash_session_id'] = $sessionId;
        $_SESSION['cash_opened_at'] = $openedAt;
        $_SESSION['opening_cash'] = round($openingCash, 2);

        // Also persist to Symfony session for cross-request survival
        $request = $this->requestStack->getCurrentRequest();
        if ($request && $request->hasSession()) {
            $request->getSession()->set('cash_session_id', $sessionId);
            $request->getSession()->set('terminal_id', $terminalId);
            $request->getSession()->set('cash_opened_at', $openedAt);
            $request->getSession()->set('opening_cash', round($openingCash, 2));
        }
    }

    private function clearCacheSession(): void
    {
        unset($_SESSION['terminal_id'], $_SESSION['cash_session_id'], $_SESSION['cash_opened_at'], $_SESSION['opening_cash']);

        // Also clear from Symfony session
        $request = $this->requestStack->getCurrentRequest();
        if ($request && $request->hasSession()) {
            $request->getSession()->remove('cash_session_id');
            $request->getSession()->remove('terminal_id');
            $request->getSession()->remove('cash_opened_at');
            $request->getSession()->remove('opening_cash');
        }
    }

    private function requireShopId(): int
    {
        $request = $this->requestStack->getCurrentRequest();
        $shopId = 0;
        if ($request && $request->hasSession()) {
            $shopId = (int) ($request->getSession()->get('shop_id') ?? 0);
        }
        if ($shopId <= 0) {
            $shopId = isset($_SESSION['shop_id']) ? (int) $_SESSION['shop_id'] : 0;
        }
        if ($shopId <= 0) {
            throw new \RuntimeException('Missing shop context');
        }

        return $shopId;
    }
}
