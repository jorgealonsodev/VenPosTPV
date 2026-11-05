<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Writes the fiscal record of every issued ticket (F2, simplified invoice) and
 * credit note (R5, its rectifying invoice), chained to the previous record by
 * the AEAT fingerprint (FiscalHash).
 *
 * It must run inside the transaction that issues the number: the chain head is
 * locked until commit, so concurrent sales are chained one after another, and a
 * rollback removes the sale together with its record. Records are only ever
 * inserted; nothing here updates or deletes them.
 */
class FiscalRecorder
{
    public const SALE = 'F2';
    public const CREDIT_NOTE = 'R5';

    /** Used when the original sale's VAT is unknown (refunds without a ticket, online orders). */
    private const ASSUMED_TAX_RATE = 0.21;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function recordSale(int $orderId, int $terminalId, string $reference, float $total, float $tax, \DateTimeImmutable $issuedAt): void
    {
        $this->append([
            'invoice_type' => self::SALE,
            'number' => $reference,
            'tax_total' => FiscalHash::amount($tax),
            'total' => FiscalHash::amount($total),
            'rectified_number' => null,
            'order_id' => $orderId,
            'refund_id' => null,
            'terminal_id' => $terminalId,
        ], $issuedAt);
    }

    /** Reads the refund (already numbered) and records its credit note as negative amounts. */
    public function recordCreditNote(int $refundId, \DateTimeImmutable $issuedAt): void
    {
        $refund = $this->connection->fetchAssociative(
            'SELECT credit_note_number, total, terminal_id, order_id, source_reference FROM refund WHERE id = ?',
            [$refundId]
        );
        if ($refund === false || $refund['credit_note_number'] === null) {
            throw new \LogicException(sprintf('Refund %d has no credit-note number to record', $refundId));
        }

        $total = round((float) $refund['total'], 2);
        $rectified = $refund['source_reference'] !== null ? (string) $refund['source_reference'] : null;
        $taxRate = null;
        if ($refund['order_id'] !== null) {
            $sale = $this->connection->fetchAssociative('SELECT reference, payment_breakdown FROM order_meta WHERE id = ?', [(int) $refund['order_id']]);
            if ($sale !== false) {
                $breakdown = json_decode((string) $sale['payment_breakdown'], true);
                $rectified = $sale['reference'] !== null ? (string) $sale['reference'] : (\is_array($breakdown) ? (string) ($breakdown['reference'] ?? '') : null);
                $saleTotal = \is_array($breakdown) ? (float) ($breakdown['total'] ?? 0) : 0.0;
                $saleTax = \is_array($breakdown) ? (float) ($breakdown['tax'] ?? 0) : 0.0;
                if ($saleTotal > 0 && $saleTax >= 0) {
                    $taxRate = $saleTax / $saleTotal;
                }
            }
        }
        $tax = $taxRate !== null ? $total * $taxRate : $total * self::ASSUMED_TAX_RATE / (1 + self::ASSUMED_TAX_RATE);

        $this->append([
            'invoice_type' => self::CREDIT_NOTE,
            'number' => (string) $refund['credit_note_number'],
            'tax_total' => FiscalHash::amount(-$tax),
            'total' => FiscalHash::amount(-$total),
            'rectified_number' => $rectified !== '' ? $rectified : null,
            'order_id' => null,
            'refund_id' => $refundId,
            'terminal_id' => (int) $refund['terminal_id'],
        ], $issuedAt);
    }

    /** @param array<string, mixed> $record */
    private function append(array $record, \DateTimeImmutable $issuedAt): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Fiscal records must be written inside the transaction that issues the number');
        }

        try {
            $head = $this->connection->fetchAssociative('SELECT last_hash FROM fiscal_chain WHERE id = 1 FOR UPDATE');
        } catch (TableNotFoundException $e) {
            // Version00000042 not applied yet: selling must go on; the gap shows in the logs.
            $this->logger->error('fiscal_record missing: {number} was issued without a fiscal record', ['number' => $record['number'], 'exception' => $e]);

            return;
        }
        if ($head === false) {
            $this->connection->executeStatement('INSERT IGNORE INTO fiscal_chain (id, last_record_id, last_hash) VALUES (1, NULL, NULL)');
            $head = $this->connection->fetchAssociative('SELECT last_hash FROM fiscal_chain WHERE id = 1 FOR UPDATE');
        }
        $previousHash = \is_array($head) && $head['last_hash'] !== null ? (string) $head['last_hash'] : '';

        $issuerNif = strtoupper(trim((string) $this->connection->fetchOne('SELECT tax_id FROM terminal WHERE id = ?', [$record['terminal_id']])));
        $issueDate = FiscalHash::date($issuedAt);
        $generatedAt = FiscalHash::timestamp(new \DateTimeImmutable('now', $issuedAt->getTimezone()));
        $hash = FiscalHash::alta($issuerNif, $record['number'], $issueDate, $record['invoice_type'], $record['tax_total'], $record['total'], $previousHash, $generatedAt);

        $this->connection->insert('fiscal_record', $record + [
            'record_type' => 'alta',
            'issuer_nif' => $issuerNif,
            'issue_date' => $issueDate,
            'previous_hash' => $previousHash !== '' ? $previousHash : null,
            'hash' => $hash,
            'generated_at' => $generatedAt,
            'created_at' => $issuedAt->format('Y-m-d H:i:s'),
        ]);
        $this->connection->update('fiscal_chain', [
            'last_record_id' => (int) $this->connection->lastInsertId(),
            'last_hash' => $hash,
        ], ['id' => 1]);
    }
}
