<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use VenPosTPV\Service\Fiscal\FiscalRecorder;

/**
 * The intents of the fiscal journal (fiscal-journal D3.1): while a document is
 * issued, inside its transaction, `intents/<hash>.json` records the fiscal record
 * about to be inserted, in the primary location or else the secondary. It is what
 * this process issued, so the flush after the response knows it has documents to
 * journal, and it carries what the database cannot tell afterwards (the id of a
 * refund's cash movement, which has no refund_id).
 *
 * Nothing here ever throws to the caller: a journal that cannot be written never
 * stops a sale (S2).
 */
class FiscalJournalIntents
{
    public const STATE_PREPARED = 'prepared';
    public const STATE_CONFIRMED = 'confirmed';

    /** @var array<string, array{number: string, refund_id: ?int, movement_id: ?int}> hash => what this process issued */
    private array $own = [];

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
     */
    public function prepared(array $record): void
    {
        $this->issued = true;
        try {
            $hash = (string) $record['hash'];
            $this->own[$hash] = [
                'number' => (string) $record['number'],
                'refund_id' => isset($record['refund_id']) ? (int) $record['refund_id'] : null,
                'movement_id' => null,
            ];
            $this->write($hash, [
                'fiscal_record' => $record,
                'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone(FiscalRecorder::TIME_ZONE)))->format(\DATE_ATOM),
                'state' => self::STATE_PREPARED,
            ]);
        } catch (\Throwable $e) {
            $this->logger?->error('Fiscal journal intent not written: {message}', ['message' => $e->getMessage()]);
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
                foreach ($this->locations() as $location) {
                    $data = $location->readIntent($hash);
                    if ($data !== null) {
                        $location->writeIntent($hash, ['movement_id' => $movementId] + $data);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Fiscal journal intent not updated: {message}', ['message' => $e->getMessage()]);
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
        if (isset($this->own[$hash]) && $this->own[$hash]['movement_id'] !== null) {
            return $this->own[$hash]['movement_id'];
        }
        foreach ($this->locations() as $location) {
            try {
                $data = $location->readIntent($hash);
            } catch (\Throwable) {
                $data = null;
            }
            if (\is_int($data['movement_id'] ?? null)) {
                return $data['movement_id'];
            }
        }

        return null;
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
            $this->logger?->error('Fiscal journal intent {number} not written: neither journal folder can be written', ['number' => $data['fiscal_record']['number'] ?? '']);

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

    /** @return list<JournalLocation> */
    private function locations(): array
    {
        return [$this->locations->primary(), $this->locations->secondary()];
    }
}
