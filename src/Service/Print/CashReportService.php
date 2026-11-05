<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Cash\CashSessionService;

/**
 * Builds the X report (the open till of this request) and the Z report of a close
 * (from what the close stored, never recomputed), and prints them on a till or
 * renders their PDF.
 */
class CashReportService
{
    public const PRINT_SOURCE = 'cash_report';

    public function __construct(
        private readonly Connection $connection,
        private readonly CashSessionService $cashSessionService,
        private readonly PrinterService $printerService,
        private readonly CashReportPdf $pdf,
        private readonly string $storeName = 'VenPosTPV',
    ) {
    }

    /** @throws \RuntimeException when this till has no open session */
    public function currentX(int $userId): CashReport
    {
        $state = $this->cashSessionService->getXReport($userId);
        $totals = \is_array($state['totals'] ?? null) ? $state['totals'] : [];

        return $this->report(
            CashReport::X,
            (int) $state['session_id'],
            (int) $state['terminal_id'],
            (string) ($state['opened_by'] ?? ''),
            (string) $state['opened_at'],
            null,
            $totals,
        );
    }

    /** The Z report of a closed session of this shop; null when there is none. */
    public function closedZ(int $sessionId): ?CashReport
    {
        $row = $this->cashSessionService->findClosedSession($sessionId);
        if ($row === null) {
            return null;
        }

        return $this->report(
            CashReport::Z,
            (int) $row['id'],
            (int) $row['terminal_id'],
            (string) ($row['opened_by'] ?? ''),
            (string) $row['opened_at'],
            (string) $row['closed_at'],
            $row['totals'],
            round((float) $row['counted_cash'], 2),
            round((float) $row['difference'], 2),
            (string) $row['signature_hash'],
        );
    }

    /**
     * Prints the report on a till: a print agent job, the direct printer, or
     * ['fallback' => 'pdf'] when the till has no printer.
     *
     * @return array<string, mixed>
     */
    public function print(CashReport $report, int $terminalId, ?int $userId = null): array
    {
        $width = $this->printerService->paperWidth($terminalId) >= 80 ? 48 : 32;

        return $this->printerService->printRaw($terminalId, $report->escPos($width), self::PRINT_SOURCE, $report->kind . ':' . $report->sessionId, $userId);
    }

    public function pdf(CashReport $report): string
    {
        return $this->pdf->generate($report);
    }

    /** @param array<string, mixed> $totals */
    private function report(string $kind, int $sessionId, int $terminalId, string $cashier, string $openedAt, ?string $closedAt, array $totals, ?float $counted = null, ?float $difference = null, ?string $hash = null): CashReport
    {
        $terminal = $this->connection->fetchAssociative('SELECT name, ticket_letter, store_name, legal_name, tax_id FROM terminal WHERE id = ?', [$terminalId]);
        $text = static fn (string $key): ?string => $terminal !== false && \is_string($terminal[$key] ?? null) && trim($terminal[$key]) !== ''
            ? trim($terminal[$key])
            : null;

        return new CashReport(
            kind: $kind,
            sessionId: $sessionId,
            terminalId: $terminalId,
            terminalName: $text('name') ?? ('Caja ' . $terminalId),
            terminalLetter: $text('ticket_letter'),
            // Same order as the tickets: the settings name, the legal name, the .env default.
            storeName: $text('store_name') ?? $text('legal_name') ?? $this->storeName,
            legalName: $text('legal_name'),
            taxId: $text('tax_id'),
            cashierName: $cashier,
            openedAt: new \DateTimeImmutable($openedAt),
            closedAt: $closedAt !== null ? new \DateTimeImmutable($closedAt) : null,
            issuedAt: $closedAt !== null ? new \DateTimeImmutable($closedAt) : new \DateTimeImmutable(),
            totals: $totals,
            countedCash: $counted,
            difference: $difference,
            signatureHash: $hash,
        );
    }
}
