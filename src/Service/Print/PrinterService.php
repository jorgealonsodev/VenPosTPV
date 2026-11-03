<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\PrintAgent\DrawerAudit;
use VenPosTPV\Service\PrintAgent\PrintJobQueue;
use VenPosTPV\Service\PrintAgent\TerminalNotResolvedException;

/**
 * Prints tickets and opens the drawer of a till: on a network printer, a printer
 * installed on this machine (system queue), or, for a till whose printer is the
 * print agent (type `agent`, docs/prd/agente-impresion.md T0), by creating a
 * `print_job` in the same request with the same ESC/POS bytes. The job ids created
 * in this request are available through queuedJobId() for the API responses.
 */
class PrinterService
{
    /** @var array<string, int> job ids created in this request, by type */
    private array $queued = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly TicketDataResolver $ticketDataResolver,
        private readonly EscPosTicketBuilder $escPosBuilder,
        private readonly TicketPdfGenerator $pdfGenerator,
        private readonly SystemPrintQueue $systemPrintQueue = new SystemPrintQueue(),
        private readonly ?PrintJobQueue $printJobs = null,
        private readonly ?DrawerAudit $drawerAudit = null,
    ) {
    }

    /**
     * True when the till prints through its print agent. A null till is the old
     * fallback to till 1: callers refuse it for an agent till (T9).
     */
    public function usesAgent(?int $terminalId): bool
    {
        return $this->configFor($terminalId ?? 1)->usesAgent();
    }

    /** The print job of this type ('ticket', 'drawer') created by the last call in this request. */
    public function queuedJobId(string $type): ?int
    {
        return $this->queued[$type] ?? null;
    }

    /**
     * A test ticket for the till's print agent (Ajustes → Cajas). Returns the job id.
     */
    public function printTestTicket(int $terminalId, ?int $userId = null): int
    {
        $name = $this->connection->fetchOne('SELECT name FROM terminal WHERE id = ?', [$terminalId]);
        $text = "Prueba de impresión\n" . (\is_string($name) ? $name : '') . "\n" . (new \DateTimeImmutable())->format('d/m/Y H:i') . "\n";
        $bytes = "\x1B@" . "\x1Bt\x10" . "\x1Ba\x01" . self::toWpc1252($text) . "\n\n\n" . "\x1DV\x41\x03";

        return $this->enqueue($terminalId, 'ticket', $bytes, 'test', null, $userId);
    }

    public function printTicket(int $orderId, int $terminalId, bool $giftMode = false): void
    {
        $ticketData = $this->ticketDataResolver->resolve($orderId);
        if ($ticketData === null) {
            $this->logAudit($terminalId, 'ticket_print_failed', (string) $orderId, ['reason' => 'Order not found']);
            throw new \RuntimeException(sprintf('Order %d not found', $orderId));
        }

        // Resolve printer config
        $config = $this->configFor($terminalId);

        if ($config->usesAgent()) {
            $this->enqueue($terminalId, 'ticket', $this->escPosBuilder->build($ticketData, $giftMode), $giftMode ? 'gift' : 'sale', (string) $orderId);

            return;
        }

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

        $config = $this->configFor($terminalId);

        $this->logAudit($terminalId, 'ticket_reprint', (string) $orderId, [
            'printer_enabled' => $config->enabled,
            'printer_type' => $config->type,
            'gift_mode' => $giftMode,
        ]);

        if ($config->usesAgent()) {
            // T10: the reprint goes out on the till that asks for it.
            $jobId = $this->enqueue($terminalId, 'ticket', $this->escPosBuilder->build($ticketData, $giftMode), 'reprint', (string) $orderId);

            return ['printed' => false, 'print_status' => 'queued', 'print_job_id' => $jobId];
        }

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
     * printer. Returns 'printed', 'queued' for the till's print agent (the job id is
     * queuedJobId('ticket')), or 'pdf' when there is no usable printer and the
     * screen must show the PDF instead (the abono is always handed over).
     */
    public function printRefundTicket(int $refundId, int $terminalId): string
    {
        $ticketData = $this->ticketDataResolver->resolveRefund($refundId);
        if ($ticketData === null) {
            throw new \RuntimeException(sprintf('Refund %d not found', $refundId));
        }

        $config = $this->configFor($terminalId);

        if ($config->usesAgent()) {
            $this->enqueue($terminalId, 'ticket', $this->escPosBuilder->build($ticketData), 'credit_note', (string) $refundId);

            return 'queued';
        }

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

    /**
     * Opens the till's drawer. Every opening is recorded with user, till, reason and
     * result (T11); for the print agent a `drawer` job without data is queued (T8).
     *
     * @param string|null $reason 'sale', 'refund', 'exchange', 'button'; from the request path when null
     */
    public function openDrawer(int $terminalId, ?string $reason = null, ?int $userId = null, ?int $orderId = null, ?string $saleReference = null): void
    {
        $config = $this->configFor($terminalId);

        if ($config->usesAgent()) {
            $jobId = $this->enqueue($terminalId, 'drawer', null, 'drawer', $orderId !== null ? (string) $orderId : null, $userId);
            $this->drawerAudit?->record($terminalId, $config->type, DrawerAudit::RESULT_QUEUED, $reason, $userId, $orderId, $saleReference, $jobId, true);

            return;
        }

        if (!$config->printsDirectly()) {
            $this->logAudit($terminalId, 'drawer_open_skipped', (string) $terminalId, [
                'reason' => 'Printer not configured or disabled',
                'printer_enabled' => $config->enabled,
                'printer_type' => $config->type,
            ]);
            $this->drawerAudit?->record($terminalId, $config->type, DrawerAudit::RESULT_SKIPPED, $reason, $userId, $orderId, $saleReference);

            return;
        }

        try {
            $this->sendDrawerKick($config);
            $this->logAudit($terminalId, 'drawer_open_success', (string) $terminalId, [
                'host' => $config->host,
                'port' => $config->port,
            ]);
            $this->drawerAudit?->record($terminalId, $config->type, DrawerAudit::RESULT_SENT, $reason, $userId, $orderId, $saleReference);
        } catch (\Throwable $e) {
            $this->logAudit($terminalId, 'drawer_open_failed', (string) $terminalId, [
                'error' => $e->getMessage(),
                'host' => $config->host,
                'port' => $config->port,
            ]);
            $this->drawerAudit?->record($terminalId, $config->type, DrawerAudit::RESULT_FAILED, $reason, $userId, $orderId, $saleReference);
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

    /**
     * @param int $orderId a PrestaShop order id, or a TicketDataResolver::saleKey() (the
     *                     negative local id) for a sale that may not be uploaded yet
     */
    private function markPrinted(int $orderId): void
    {
        OrderPrintedMarker::mark($this->connection, $orderId);
    }

    private function configFor(int $terminalId): PrinterConfig
    {
        $configRow = $this->connection->fetchOne('SELECT printer_config FROM terminal WHERE id = ?', [$terminalId]);

        return PrinterConfig::fromJson($configRow !== false && \is_string($configRow) ? $configRow : null);
    }

    private function enqueue(int $terminalId, string $type, ?string $payload, string $source, ?string $sourceRef = null, ?int $userId = null): int
    {
        if ($terminalId <= 0) {
            throw new TerminalNotResolvedException();
        }
        if ($this->printJobs === null) {
            throw new \RuntimeException('The print agent queue is not available');
        }
        $jobId = $this->printJobs->enqueue($terminalId, $type, $payload, $source, $sourceRef, $userId ?? self::sessionUserId());
        $this->queued[$type] = $jobId;

        return $jobId;
    }

    private static function sessionUserId(): ?int
    {
        $userId = $_SESSION['_sf2_attributes']['user_id'] ?? $_SESSION['user_id'] ?? null;

        return is_numeric($userId) ? (int) $userId : null;
    }

    private static function toWpc1252(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        return $converted === false ? $text : $converted;
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
