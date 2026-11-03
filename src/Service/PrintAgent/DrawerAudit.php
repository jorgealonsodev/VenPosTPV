<?php

declare(strict_types=1);

namespace VenPosTPV\Service\PrintAgent;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PRD T11: every drawer opening keeps user, till, reason (sale number or button)
 * and result, for every printer type. `drawer_audit` is never purged with the
 * print history (S4).
 *
 * Callers that know the reason pass it (a sale); the others (refunds, exchanges,
 * the "Abrir cajón" button) are told apart by the API path of the request, and the
 * user comes from the session when the caller does not give it.
 */
class DrawerAudit
{
    public const RESULT_QUEUED = 'queued';
    public const RESULT_SENT = 'sent';
    public const RESULT_FAILED = 'failed';
    public const RESULT_SKIPPED = 'skipped';

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly Connection $connection,
        private readonly ?RequestStack $requestStack = null,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Stores one opening. Best effort for printers that are not agents (the table
     * may not exist before Version00000037); for agent jobs the caller lets errors
     * surface by passing $strict.
     */
    public function record(
        int $terminalId,
        string $printerType,
        string $result,
        ?string $reason = null,
        ?int $userId = null,
        ?int $orderId = null,
        ?string $saleReference = null,
        ?int $jobId = null,
        bool $strict = false,
    ): void {
        try {
            $this->connection->insert('drawer_audit', [
                'terminal_id' => $terminalId,
                'user_id' => $userId ?? $this->sessionUserId(),
                'reason' => mb_substr($reason ?? $this->reasonFromRequest(), 0, 32),
                'order_id' => $orderId,
                'sale_reference' => $saleReference !== null ? mb_substr($saleReference, 0, 64) : null,
                'job_id' => $jobId,
                'printer_type' => mb_substr($printerType, 0, 16),
                'result' => $result,
                'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            if ($strict) {
                throw $e;
            }
        }
    }

    private function sessionUserId(): ?int
    {
        $request = $this->requestStack?->getMainRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }
        $session = $request->getSession();
        if (!$session->isStarted() && !$request->hasPreviousSession()) {
            return null;
        }
        $userId = $session->get('user_id');

        return is_numeric($userId) ? (int) $userId : null;
    }

    private function reasonFromRequest(): string
    {
        $path = $this->requestStack?->getMainRequest()?->getPathInfo() ?? '';

        return match (true) {
            str_starts_with($path, '/api/drawer/') => 'button',
            str_starts_with($path, '/api/refund') => 'refund',
            str_starts_with($path, '/api/exchange') => 'exchange',
            str_starts_with($path, '/api/checkout') => 'sale',
            default => 'other',
        };
    }
}
