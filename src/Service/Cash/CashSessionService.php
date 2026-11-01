<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Cash;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Cash\Exception\NoOpenCashSessionException;
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
        $terminalId = $this->terminalService->resolveForShop($shopId);

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
    public function getCurrentSession(int $userId): ?array
    {
        $session = $this->findCurrentSessionByUser($userId);
        if ($session === null) {
            return null;
        }

        return $this->buildCurrentState($session);
    }

    /** @return array<string,mixed> */
    public function requireOpenSession(int $userId): array
    {
        $session = $this->findCurrentSessionByUser($userId);
        if ($session === null) {
            throw new NoOpenCashSessionException();
        }

        return [
            'session_id' => (int) $session['id'],
            'terminal_id' => (int) $session['terminal_id'],
            'shop_id' => $this->requireShopId(),
            'user_id' => $userId,
        ];
    }

    /** @return array<string,mixed> */
    public function addMovement(int $userId, string $type, float $amount, string $concept = '', ?int $psOrderId = null): array
    {
        $this->rejectExistingTransaction();
        $this->requireShopId();

        $state = $this->connection->transactional(function () use ($userId, $type, $amount, $concept, $psOrderId): array {
            $session = $this->lockCurrentSessionByUser($userId);
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

    /** @return array<string,mixed> */
    public function closeSession(int $userId, float $countedCash): array
    {
        $this->rejectExistingTransaction();

        $result = $this->connection->transactional(function () use ($userId, $countedCash): array {
            $session = $this->lockCurrentSessionByUser($userId);
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
        $session = $this->findCurrentSessionByUser($userId);
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
            'sales_cash' => $totals['sales_cash'],
            'refunds_cash' => $totals['refunds_cash'],
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

        return [
            'opening_cash' => round($openingCash, 2),
            'sales_cash' => round($sales, 2),
            'refunds_cash' => round($refunds, 2),
            'cash_in_total' => round($cashIn, 2),
            'cash_out_total' => round($cashOut, 2),
            'expected_cash' => $expected,
            'counts' => $counts,
        ];
    }

    /** @return array<string,mixed>|null */
    private function lockCurrentSessionByUser(int $userId): ?array
    {
        $request = $this->requestStack->getCurrentRequest();
        $sessionId = 0;
        if ($request && $request->hasSession()) {
            $sessionId = (int) ($request->getSession()->get('cash_session_id') ?? 0);
        }
        if ($sessionId <= 0) {
            $sessionId = isset($_SESSION['cash_session_id']) ? (int) $_SESSION['cash_session_id'] : 0;
        }
        if ($sessionId > 0) {
            $session = $this->connection->fetchAssociative(
                'SELECT id, terminal_id, opened_at, opening_cash FROM session WHERE id = ? AND user_id = ? AND closed_at IS NULL FOR UPDATE',
                [$sessionId, $userId]
            );
            if ($session !== false) {
                return $session;
            }
        }

        $session = $this->connection->fetchAssociative(
            'SELECT s.id, s.terminal_id, s.opened_at, s.opening_cash
             FROM session s
             INNER JOIN terminal t ON t.id = s.terminal_id
             WHERE s.user_id = ? AND s.closed_at IS NULL AND t.ps_shop_id = ?
             ORDER BY s.id DESC LIMIT 1 FOR UPDATE',
            [$userId, $this->requireShopId()]
        );
        if ($session !== false) {
            return $session;
        }

        $session = $this->connection->fetchAssociative(
            'SELECT id, terminal_id, opened_at, opening_cash
             FROM session
             WHERE user_id = ? AND closed_at IS NULL
             ORDER BY id DESC LIMIT 1 FOR UPDATE',
            [$userId]
        );

        return $session === false ? null : $session;
    }

    /** @return array<string,mixed>|null */
    private function findCurrentSessionByUser(int $userId): ?array
    {
        // Check both Symfony session and native $_SESSION
        $request = $this->requestStack->getCurrentRequest();
        $sessionId = 0;
        if ($request && $request->hasSession()) {
            $sessionId = (int) ($request->getSession()->get('cash_session_id') ?? 0);
        }
        if ($sessionId <= 0) {
            $sessionId = isset($_SESSION['cash_session_id']) ? (int) $_SESSION['cash_session_id'] : 0;
        }
        if ($sessionId > 0) {
            $session = $this->connection->fetchAssociative(
                'SELECT id, terminal_id, opened_at, opening_cash FROM session WHERE id = ? AND user_id = ? AND closed_at IS NULL',
                [$sessionId, $userId]
            );

            if ($session !== false) {
                return $session;
            }
        }

        // Fallback 1: DB lookup via terminal → shop (requires terminal.ps_shop_id to be set)
        $session = $this->connection->fetchAssociative(
            'SELECT s.id, s.terminal_id, s.opened_at, s.opening_cash
             FROM session s
             INNER JOIN terminal t ON t.id = s.terminal_id
             WHERE s.user_id = ? AND s.closed_at IS NULL AND t.ps_shop_id = ?
             ORDER BY s.id DESC LIMIT 1',
            [$userId, $this->requireShopId()]
        );

        if ($session !== false) {
            $this->cacheSession((int) $session['terminal_id'], (int) $session['id'], (string) $session['opened_at'], (float) $session['opening_cash']);

            return $session;
        }

        // Fallback 2: DB lookup by user only — survives missing terminal/shop context
        $session = $this->connection->fetchAssociative(
            'SELECT id, terminal_id, opened_at, opening_cash
             FROM session
             WHERE user_id = ? AND closed_at IS NULL
             ORDER BY id DESC LIMIT 1',
            [$userId]
        );

        if ($session === false) {
            return null;
        }

        $this->cacheSession((int) $session['terminal_id'], (int) $session['id'], (string) $session['opened_at'], (float) $session['opening_cash']);

        return $session;
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
