<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

use Doctrine\DBAL\Connection;

class TicketDataResolver
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TicketVerificationService $verificationService,
        private readonly string $storeName,
        private readonly string $storeAddress,
    ) {
    }

    public function resolve(int $orderId): ?TicketData
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ps_order_id, terminal_id, user_id, payment_breakdown, created_at FROM order_meta WHERE ps_order_id = ?',
            [$orderId]
        );

        if ($row === false) {
            return null;
        }

        $breakdown = json_decode((string) $row['payment_breakdown'], true);
        if (!\is_array($breakdown)) {
            $breakdown = [];
        }

        $lines = [];
        foreach ($breakdown['lines'] ?? [] as $line) {
            $lines[] = new TicketLine(
                name: isset($line['name']) && \is_string($line['name']) ? $line['name'] : 'Producto',
                quantity: isset($line['qty']) && \is_int($line['qty']) ? $line['qty'] : (isset($line['quantity']) ? (int) $line['quantity'] : 1),
                unitPrice: isset($line['price']) ? (float) $line['price'] : 0.0,
                lineTotal: isset($line['line_total']) ? (float) $line['line_total'] : 0.0,
            );
        }

        $reference = isset($breakdown['reference']) && \is_string($breakdown['reference']) ? $breakdown['reference'] : '';
        $total = isset($breakdown['total']) ? (float) $breakdown['total'] : 0.0;
        $amountTendered = isset($breakdown['amount_tendered']) ? (float) $breakdown['amount_tendered'] : 0.0;
        $changeDue = isset($breakdown['change_due']) ? (float) $breakdown['change_due'] : 0.0;
        $subtotal = isset($breakdown['subtotal']) ? (float) $breakdown['subtotal'] : $total;
        $tax = isset($breakdown['tax']) ? (float) $breakdown['tax'] : 0.0;

        $token = $this->verificationService->generateToken([
            'order_id' => $orderId,
            'reference' => $reference,
            'total' => $total,
        ]);

        $cashierName = $this->cashierName((int) $row['user_id']);
        [$storeAddress, $ticketHeader, $ticketFooter, $ticketLogo, $ticketWidthPx] = $this->terminalPresentation((int) $row['terminal_id']);

        return new TicketData(
            orderId: $orderId,
            reference: $reference,
            storeName: $this->storeName,
            storeAddress: $storeAddress,
            ticketHeader: $ticketHeader,
            ticketFooter: $ticketFooter,
            ticketLogo: $ticketLogo,
            ticketWidthPx: $ticketWidthPx,
            date: new \DateTimeImmutable((string) $row['created_at']),
            cashierName: $cashierName,
            lines: $lines,
            subtotal: $subtotal,
            tax: $tax,
            total: $total,
            paymentMethod: isset($breakdown['payment_method']) && \is_string($breakdown['payment_method']) ? $breakdown['payment_method'] : '',
            amountTendered: $amountTendered,
            changeDue: $changeDue,
            verificationUrl: $this->verificationService->buildVerificationUrl($token),
        );
    }

    private const REASON_LABELS = [
        'size' => 'Talla o color',
        'defective' => 'Defectuoso',
        'dislike' => 'No le gusta',
        'billing_error' => 'Error de cobro',
        'not_as_described' => 'No coincide con la web',
        'gift_duplicate' => 'Regalo / duplicado',
        'late' => 'Llegó tarde',
        'other' => 'Otro',
    ];

    /**
     * Credit note (abono) of a completed refund: the refunded lines and amounts,
     * the ticket it rectifies and how the money went back. VAT is the sale's VAT
     * share applied to the refunded total.
     */
    public function resolveRefund(int $refundId): ?TicketData
    {
        $refund = $this->connection->fetchAssociative(
            "SELECT id, ps_order_id, terminal_id, user_id, credit_note_number, reason_code, reason_text, total, cash_amount, card_amount, created_at, completed_at
             FROM refund WHERE id = ? AND status = 'done'",
            [$refundId]
        );
        if ($refund === false) {
            return null;
        }

        $order = $this->connection->fetchAssociative(
            'SELECT payment_breakdown, created_at FROM order_meta WHERE ps_order_id = ?',
            [(int) $refund['ps_order_id']]
        );
        $breakdown = $order !== false ? json_decode((string) $order['payment_breakdown'], true) : null;
        $breakdown = \is_array($breakdown) ? $breakdown : [];
        $saleLines = \is_array($breakdown['lines'] ?? null) ? array_values($breakdown['lines']) : [];

        $lines = [];
        foreach ($this->connection->fetchAllAssociative('SELECT line_index, quantity, amount FROM refund_line WHERE refund_id = ? ORDER BY id', [$refundId]) as $line) {
            $quantity = max(1, (int) $line['quantity']);
            $amount = round((float) $line['amount'], 2);
            $saleLine = $line['line_index'] !== null ? ($saleLines[(int) $line['line_index']] ?? []) : [];
            $lines[] = new TicketLine(
                name: isset($saleLine['name']) && \is_string($saleLine['name']) ? $saleLine['name'] : 'Producto',
                quantity: $quantity,
                unitPrice: round($amount / $quantity, 2),
                lineTotal: $amount,
            );
        }

        $total = round((float) $refund['total'], 2);
        $saleTotal = (float) ($breakdown['total'] ?? 0);
        $saleTax = (float) ($breakdown['tax'] ?? 0);
        $tax = $saleTotal > 0 ? round($total * $saleTax / $saleTotal, 2) : 0.0;
        $reference = isset($breakdown['reference']) && \is_string($breakdown['reference']) ? $breakdown['reference'] : '';
        $number = (string) $refund['credit_note_number'];

        $reasonCode = (string) $refund['reason_code'];
        $reason = self::REASON_LABELS[$reasonCode] ?? $reasonCode;
        $reasonText = trim((string) ($refund['reason_text'] ?? ''));
        if ($reasonCode === 'other' && $reasonText !== '') {
            $reason .= ': ' . $reasonText;
        }

        $token = $this->verificationService->generateToken([
            'order_id' => (int) $refund['ps_order_id'],
            'reference' => $reference,
            'total' => $saleTotal,
        ]);
        [$storeAddress, $ticketHeader, $ticketFooter, $ticketLogo, $ticketWidthPx] = $this->terminalPresentation((int) $refund['terminal_id']);

        return new TicketData(
            orderId: (int) $refund['ps_order_id'],
            reference: $number,
            storeName: $this->storeName,
            storeAddress: $storeAddress,
            ticketHeader: $ticketHeader,
            ticketFooter: $ticketFooter,
            ticketLogo: $ticketLogo,
            ticketWidthPx: $ticketWidthPx,
            date: new \DateTimeImmutable((string) ($refund['completed_at'] ?? $refund['created_at'])),
            cashierName: $this->cashierName((int) $refund['user_id']),
            lines: $lines,
            subtotal: round($total - $tax, 2),
            tax: $tax,
            total: $total,
            paymentMethod: (string) ($breakdown['payment_method'] ?? ''),
            amountTendered: 0.0,
            changeDue: 0.0,
            verificationUrl: $this->verificationService->buildVerificationUrl($token),
            creditNote: new CreditNoteInfo(
                number: $number,
                rectifiesReference: $reference,
                rectifiesDate: $order !== false ? new \DateTimeImmutable((string) $order['created_at']) : null,
                reason: $reason,
                cashAmount: round((float) $refund['cash_amount'], 2),
                cardAmount: round((float) $refund['card_amount'], 2),
            ),
        );
    }

    private function cashierName(int $userId): string
    {
        $userRow = $this->connection->fetchAssociative('SELECT name FROM user WHERE id = ?', [$userId]);

        return $userRow !== false && isset($userRow['name']) && \is_string($userRow['name']) ? $userRow['name'] : 'Cajero';
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: ?string, 4: ?int} address, header, footer, logo, width
     */
    private function terminalPresentation(int $terminalId): array
    {
        $terminalRow = $this->connection->fetchAssociative(
            'SELECT store_address, ticket_header, ticket_footer, ticket_logo, ticket_width_px FROM terminal WHERE id = ?',
            [$terminalId]
        );

        $storeAddress = $this->storeAddress;
        if ($terminalRow !== false && isset($terminalRow['store_address']) && \is_string($terminalRow['store_address'])) {
            $candidate = trim($terminalRow['store_address']);
            if ($candidate !== '') {
                $storeAddress = $candidate;
            }
        }

        $ticketHeader = $terminalRow !== false && isset($terminalRow['ticket_header']) && \is_string($terminalRow['ticket_header'])
            ? trim($terminalRow['ticket_header'])
            : '';
        $ticketFooter = $terminalRow !== false && isset($terminalRow['ticket_footer']) && \is_string($terminalRow['ticket_footer'])
            ? trim($terminalRow['ticket_footer'])
            : '';
        $ticketLogo = $terminalRow !== false && isset($terminalRow['ticket_logo']) && \is_string($terminalRow['ticket_logo'])
            ? trim($terminalRow['ticket_logo'])
            : null;
        $ticketWidthPx = $terminalRow !== false && isset($terminalRow['ticket_width_px'])
            ? (int) $terminalRow['ticket_width_px']
            : null;

        return [$storeAddress, $ticketHeader, $ticketFooter, $ticketLogo, $ticketWidthPx];
    }
}
