<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

use Doctrine\DBAL\Connection;

class PrinterService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TicketDataResolver $ticketDataResolver,
        private readonly EscPosTicketBuilder $escPosBuilder,
        private readonly TicketPdfGenerator $pdfGenerator,
        private readonly SystemPrintQueue $systemPrintQueue = new SystemPrintQueue(),
    ) {
    }

    public function printTicket(int $orderId, int $terminalId, bool $giftMode = false): void
    {
        $ticketData = $this->ticketDataResolver->resolve($orderId);
        if ($ticketData === null) {
            $this->logAudit($terminalId, 'ticket_print_failed', (string) $orderId, ['reason' => 'Order not found']);
            throw new \RuntimeException(sprintf('Order %d not found', $orderId));
        }

        // Resolve printer config
        $configRow = $this->connection->fetchOne(
            'SELECT printer_config FROM terminal WHERE id = ?',
            [$terminalId]
        );
        $config = PrinterConfig::fromJson($configRow !== false && \is_string($configRow) ? $configRow : null);

        if ($config->printsDirectly()) {
            try {
                $this->sendEscPos($ticketData, $config, $giftMode);
                $this->markPrinted($orderId);

                return;
            } catch (\Throwable $e) {
                $this->logAudit($terminalId, 'ticket_escpos_failed', (string) $orderId, [
                    'error' => $e->getMessage(),
                    'host' => $config->host,
                    'port' => $config->port,
                ]);
            }
        }

        // Fallback to PDF
        try {
            $pdf = $this->pdfGenerator->generate($ticketData, $giftMode);
            $this->logAudit($terminalId, 'ticket_pdf_fallback', (string) $orderId, [
                'pdf_size' => \strlen($pdf),
                'printer_enabled' => $config->enabled,
                'printer_type' => $config->type,
            ]);
            // PDF fallback does NOT mark as printed (the spec says printed remains 0 on failure)
        } catch (\Throwable $e) {
            $this->logAudit($terminalId, 'ticket_pdf_failed', (string) $orderId, ['error' => $e->getMessage()]);
            throw new \RuntimeException(sprintf('Ticket PDF generation failed for order %d: %s', $orderId, $e->getMessage()), 0, $e);
        }
    }

    public function reprintTicket(int $orderId, int $terminalId, bool $giftMode = false): array
    {
        $ticketData = $this->ticketDataResolver->resolve($orderId);
        if ($ticketData === null) {
            throw new \RuntimeException(sprintf('Order %d not found', $orderId));
        }

        $configRow = $this->connection->fetchOne(
            'SELECT printer_config FROM terminal WHERE id = ?',
            [$terminalId]
        );
        $config = PrinterConfig::fromJson($configRow !== false && \is_string($configRow) ? $configRow : null);

        $this->logAudit($terminalId, 'ticket_reprint', (string) $orderId, [
            'printer_enabled' => $config->enabled,
            'printer_type' => $config->type,
            'gift_mode' => $giftMode,
        ]);

        if ($config->printsDirectly()) {
            try {
                $this->sendEscPos($ticketData, $config, $giftMode);
                $this->markPrinted($orderId);

                return ['printed' => true];
            } catch (\Throwable $e) {
                // Fall through to PDF
            }
        }

        $pdf = $this->pdfGenerator->generate($ticketData, $giftMode);

        return ['printed' => false, 'fallback' => 'pdf', 'pdf' => base64_encode($pdf)];
    }

    /**
     * Prints the credit note of a completed refund on the terminal's network
     * printer. Returns 'printed', or 'pdf' when there is no usable printer and the
     * screen must show the PDF instead (the abono is always handed over).
     */
    public function printRefundTicket(int $refundId, int $terminalId): string
    {
        $ticketData = $this->ticketDataResolver->resolveRefund($refundId);
        if ($ticketData === null) {
            throw new \RuntimeException(sprintf('Refund %d not found', $refundId));
        }

        $configRow = $this->connection->fetchOne('SELECT printer_config FROM terminal WHERE id = ?', [$terminalId]);
        $config = PrinterConfig::fromJson($configRow !== false && \is_string($configRow) ? $configRow : null);

        if ($config->printsDirectly()) {
            try {
                $this->sendEscPos($ticketData, $config);
                $this->logAudit($terminalId, 'credit_note_printed', (string) $refundId, ['number' => $ticketData->reference]);

                return 'printed';
            } catch (\Throwable $e) {
                $this->logAudit($terminalId, 'credit_note_escpos_failed', (string) $refundId, ['error' => $e->getMessage()]);
            }
        }

        return 'pdf';
    }

    public function openDrawer(int $terminalId): void
    {
        $configRow = $this->connection->fetchOne(
            'SELECT printer_config FROM terminal WHERE id = ?',
            [$terminalId]
        );
        $config = PrinterConfig::fromJson($configRow !== false && \is_string($configRow) ? $configRow : null);

        if (!$config->printsDirectly()) {
            $this->logAudit($terminalId, 'drawer_open_skipped', (string) $terminalId, [
                'reason' => 'Printer not configured or disabled',
                'printer_enabled' => $config->enabled,
                'printer_type' => $config->type,
            ]);

            return;
        }

        try {
            $this->sendDrawerKick($config);
            $this->logAudit($terminalId, 'drawer_open_success', (string) $terminalId, [
                'host' => $config->host,
                'port' => $config->port,
            ]);
        } catch (\Throwable $e) {
            $this->logAudit($terminalId, 'drawer_open_failed', (string) $terminalId, [
                'error' => $e->getMessage(),
                'host' => $config->host,
                'port' => $config->port,
            ]);
            // Drawer failure is non-critical — do not throw
        }
    }

    protected function sendDrawerKick(PrinterConfig $config): void
    {
        // Standard ESC/POS drawer kick command: pulse on pin 2
        // \x1B\x70\x00\x19\xFA  →  ESC p 0 25 250
        $this->sendRaw($config, "\x1B\x70\x00\x19\xFA");
    }

    protected function sendEscPos(TicketData $ticketData, PrinterConfig $config, bool $giftMode = false): void
    {
        $this->sendRaw($config, $this->escPosBuilder->build($ticketData, $giftMode));
    }

    /**
     * Delivers ESC/POS bytes to the configured printer: a system queue (USB or any
     * printer installed on this machine) or a network printer on host:port.
     */
    private function sendRaw(PrinterConfig $config, string $bytes): void
    {
        if ($config->type === 'system') {
            $this->systemPrintQueue->send($config->queue, $bytes);

            return;
        }

        $socket = @fsockopen($config->host, $config->port, $errno, $errstr, 5.0);
        if ($socket === false) {
            throw new \RuntimeException(sprintf('Printer connection failed: %s (%d)', $errstr, $errno));
        }

        stream_set_timeout($socket, 5);
        fwrite($socket, $bytes);
        fclose($socket);
    }

    private function markPrinted(int $orderId): void
    {
        $this->connection->executeStatement(
            'UPDATE order_meta SET printed = 1, printed_at = NOW(), print_attempts = print_attempts + 1 WHERE ps_order_id = ?',
            [$orderId]
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function logAudit(int $terminalId, string $action, string $target, array $payload): void
    {
        try {
            $this->connection->insert('audit_log', [
                'user_id' => $_SESSION['user_id'] ?? null,
                'terminal_id' => $terminalId,
                'action' => $action,
                'target' => $target,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Best-effort logging
        }
    }
}
