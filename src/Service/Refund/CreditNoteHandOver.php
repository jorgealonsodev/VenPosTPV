<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Refund;

use VenPosTPV\Service\Print\PrinterService;

/**
 * What the till does with a completed refund, shared by refunds and exchanges: the
 * credit note is always handed over, and the drawer opens only when cash goes back.
 */
class CreditNoteHandOver
{
    public function __construct(private readonly PrinterService $printerService)
    {
    }

    /**
     * 'printed' on the network printer, or 'pdf' so the screen opens it; 'none' when
     * no credit note exists yet. A print failure never undoes the refund.
     *
     * @param array<string, mixed> $result
     */
    public function printCreditNote(array $result, int $terminalId): string
    {
        if (!isset($result['refund_id'])) {
            return 'none';
        }
        if (!empty($result['saga_error'])) {
            // The shop refunded it but the local completion failed: no credit note
            // exists yet, so there is nothing to print until the refund is retried.
            return 'none';
        }
        if (!empty($result['already_completed'])) {
            return 'pdf';
        }

        try {
            return $this->printerService->printRefundTicket((int) $result['refund_id'], $terminalId);
        } catch (\Throwable) {
            return 'pdf';
        }
    }

    /**
     * Cash goes back through the drawer wired to the printer. Never for card or
     * voucher refunds, replays or incomplete refunds; a drawer failure never fails
     * the refund.
     *
     * @param array<string, mixed> $result
     */
    public function openDrawerForCash(array $result, int $terminalId): void
    {
        if ((float) ($result['cash_amount'] ?? 0) <= 0 || !empty($result['already_completed']) || !empty($result['saga_error'])) {
            return;
        }

        try {
            $this->printerService->openDrawer($terminalId);
        } catch (\Throwable) {
            // The cashier can still open the drawer manually.
        }
    }
}
