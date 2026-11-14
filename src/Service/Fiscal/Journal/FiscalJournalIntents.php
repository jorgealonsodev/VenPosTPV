<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use VenPosTPV\Service\Fiscal\FiscalRecorder;

/**
 * The intents of the fiscal journal (fiscal-journal D3.1): while a document is
 * issued, inside its transaction, `intents/<hash>.json` records the fiscal record
 * about to be inserted, in the primary location or else the secondary. It is what
 * this process issued, so the flush after the response knows it has documents to
 * journal, and it carries what the database cannot tell afterwards: the id of a
 * refund's cash movement (which has no refund_id) and the rows that later
 * statements change, as they were inside the issuing transaction
 * (FiscalDocumentSnapshot::issueRows, refundedAmountOf).
 *
 * Nothing here ever throws to the caller: a journal that cannot be written never
 * stops a sale (S2). Nothing here takes journal.lock: an intent is written under the
 * fiscal_chain lock and must never wait for a flush. A folder that cannot be written
 * is logged at warning level once per process, then at debug level.
 */
class FiscalJournalIntents
{
    public const STATE_PREPARED = 'prepared';
    public const STATE_CONFIRMED = 'confirmed';

    /** @var array<string, array{number: string, refund_id: ?int, movement_id: ?int, rows: array<string, mixed>, refunded_amount?: ?array<string, mixed>}> hash => what this process issued */
    private array $own = [];

    /** @var array<string, true> conditions this process already logged at warning level */
    private array $warned = [];

    private bool $issued = false;

    private ?JournalLocation $target = null;

    private bool $targetChosen = false;

    public function __construct(
        private readonly FiscalJournalLocations $locations,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Records the intent of a fiscal record about to be inserted.
     *
     * @param array<string, mixed> $record the fiscal_record row as it will be inserted (no id yet)
     * @param Connection|null $connection the issuing transaction's, to capture the document's rows as issued
     */
    public function prepared(array $record, ?Connection $connection = null): void
    {
        $this->issued = true;
        try {
            $hash = (string) $record['hash'];
            $rows = $connection !== null ? FiscalDocumentSnapshot::issueRows($connection, $record) : [];
            $this->own[$hash] = [
                'number' => (string) $record['number'],
                'refund_id' => isset($record['refund_id']) ? (int) $record['refund_id'] : null,
                'movement_id' => null,
                'rows' => $rows,
            ];
            $this->write($hash, [
                'fiscal_record' => $record,
                'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone(FiscalRecorder::TIME_ZONE)))->format(\DATE_ATOM),
                'state' => self::STATE_PREPARED,
                'rows' => $rows,
            ]);
        } catch (\Throwable $e) {
            $this->warn('write', 'Fiscal journal intent not written: {message}', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Notes the cash movement booked for a refund issued by this process, so its
     * journal line stores that very row (fiscal-journal D2, R5).
     */
    public function attachRefundMovement(int $refundId, int $movementId): void
    {
        try {
            foreach ($this->own as $hash => $intent) {
                if ($intent['refund_id'] !== $refundId) {
                    continue;
                }
                $this->own[$hash]['movement_id'] = $movementId;
                $this->update($hash, ['movement_id' => $movementId]);
            }
        } catch (\Throwable $e) {
            $this->warn('update', 'Fiscal journal intent not updated: {message}', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Notes, inside the issuing transaction and right after the sale's refunded
     * amount was updated, that amount as this credit note leaves it (D2, R5): later
     * refunds of the same sale change it again.
     */
    public function captureRefundedAmount(int $refundId, Connection $connection): void
    {
        try {
            foreach ($this->own as $hash => $intent) {
                if ($intent['refund_id'] !== $refundId) {
                    continue;
                }
                $value = FiscalDocumentSnapshot::refundedAmountOf($connection, $refundId);
                $this->own[$hash]['refunded_amount'] = $value;
                $this->update($hash, ['order_meta_refunded_amount' => $value]);
            }
        } catch (\Throwable $e) {
            $this->warn('update', 'Fiscal journal intent not updated: {message}', ['message' => $e->getMessage()]);
        }
    }

    /** Whether this process issued a fiscal document (the flush after the response runs only then). */
    public function issuedHere(): bool
    {
        return $this->issued;
    }

    /**
     * The movement id noted for the document with this hash, by this process or by
     * the one that wrote the intent.
     */
    public function movementFor(string $hash): ?int
    {
        $movementId = $this->capturedFor($hash)['movement_id'] ?? null;

        return \is_int($movementId) ? $movementId : null;
    }

    /**
     * What was captured while the document with this hash was issued, by this process
     * or by the one that wrote the intent (FiscalDocumentSnapshot::document).
     *
     * @return array{rows?: array<string, mixed>, movement_id?: ?int, order_meta_refunded_amount?: ?array<string, mixed>}
     */
    public function capturedFor(string $hash): array
    {
        $own = $this->own[$hash] ?? null;
        $data = null;
        foreach ($this->locations() as $location) {
            try {
                $data = $location->readIntent($hash);
            } catch (\Throwable) {
                $data = null;
            }
            if ($data !== null) {
                break;
            }
        }
        $captured = [];
        $rows = $own['rows'] ?? $data['rows'] ?? null;
        if (\is_array($rows)) {
            $captured['rows'] = $rows;
        }
        $movementId = $own['movement_id'] ?? $data['movement_id'] ?? null;
        if (\is_int($movementId)) {
            $captured['movement_id'] = $movementId;
        }
        if ($own !== null && \array_key_exists('refunded_amount', $own)) {
            $captured['order_meta_refunded_amount'] = $own['refunded_amount'];
        } elseif (\is_array($data) && \array_key_exists('order_meta_refunded_amount', $data)) {
            $captured['order_meta_refunded_amount'] = $data['order_meta_refunded_amount'];
        }

        return $captured;
    }

    /**
     * D3.2: each intent of this process is checked against the database by number
     * and hash. Present: the transaction committed, the intent is confirmed until its
     * line is written. Absent: it rolled back, and the intent is removed.
     */
    public function resolveOwn(Connection $connection): void
    {
        foreach ($this->own as $hash => $intent) {
            try {
                $committed = $connection->fetchOne('SELECT 1 FROM fiscal_record WHERE number = ? AND hash = ?', [$intent['number'], $hash]) !== false;
                foreach ($this->locations() as $location) {
                    $data = $location->readIntent($hash);
                    if ($data === null) {
                        continue;
                    }
                    if ($committed) {
                        $location->writeIntent($hash, ['state' => self::STATE_CONFIRMED] + $data);
                    } else {
                        $location->deleteIntent($hash);
                    }
                }
                if (!$committed) {
                    unset($this->own[$hash]);
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('Fiscal journal intent {hash} not resolved: {message}', ['hash' => $hash, 'message' => $e->getMessage()]);
            }
        }
    }

    /** Removes the intent of a document once its line is written. */
    public function journaled(string $hash): void
    {
        unset($this->own[$hash]);
        foreach ($this->locations() as $location) {
            try {
                $location->deleteIntent($hash);
            } catch (\Throwable) {
            }
        }
    }

    /** Forgets what this process issued (after a flush that wrote everything). */
    public function reset(): void
    {
        $this->own = [];
        $this->issued = false;
    }

    /** @param array<string, mixed> $data */
    private function write(string $hash, array $data): void
    {
        if (!$this->targetChosen) {
            $this->targetChosen = true;
            foreach ($this->locations() as $location) {
                if ($location->prepare()) {
                    $this->target = $location;
                    break;
                }
            }
        }
        if ($this->target === null) {
            $this->warn('unwritable', 'Fiscal journal intent {number} not written: neither journal folder can be written', ['number' => $data['fiscal_record']['number'] ?? '']);

            return;
        }
        try {
            $this->target->writeIntent($hash, $data);
        } catch (\Throwable $e) {
            // The primary stopped accepting writes in this process: try the secondary once.
            $secondary = $this->locations->secondary();
            if ($this->target !== $secondary && $secondary->prepare()) {
                $this->target = $secondary;
                $secondary->writeIntent($hash, $data);

                return;
            }

            throw $e;
        }
    }

    /**
     * Adds $fields to the intent of $hash wherever it was written.
     *
     * @param array<string, mixed> $fields
     */
    private function update(string $hash, array $fields): void
    {
        foreach ($this->locations() as $location) {
            $data = $location->readIntent($hash);
            if ($data !== null) {
                $location->writeIntent($hash, $fields + $data);
            }
        }
    }

    /** @param array<string, mixed> $context */
    private function warn(string $condition, string $message, array $context): void
    {
        $level = isset($this->warned[$condition]) ? LogLevel::DEBUG : LogLevel::WARNING;
        $this->warned[$condition] = true;
        $this->logger?->log($level, $message, $context);
    }

    /** @return list<JournalLocation> */
    private function locations(): array
    {
        return [$this->locations->primary(), $this->locations->secondary()];
    }
}
