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

        $userRow = $this->connection->fetchAssociative(
            'SELECT name FROM user WHERE id = ?',
            [(int) $row['user_id']]
        );
        $cashierName = $userRow !== false && isset($userRow['name']) && \is_string($userRow['name']) ? $userRow['name'] : 'Cajero';

        $terminalRow = $this->connection->fetchAssociative(
            'SELECT store_address, ticket_header, ticket_footer, ticket_logo, ticket_width_px FROM terminal WHERE id = ?',
            [(int) $row['terminal_id']]
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
        $ticketWidthPx = $terminalRow !== false && isset($terminalRow['ticket_width_px']) && $terminalRow['ticket_width_px'] !== null
            ? (int) $terminalRow['ticket_width_px']
            : null;

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
}
