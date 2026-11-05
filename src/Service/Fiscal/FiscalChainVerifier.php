<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal;

use Doctrine\DBAL\Connection;

/**
 * Recomputes every fiscal record's fingerprint, in order, and checks that each
 * one points to the record before it and that the chain head points to the last
 * one. An edited record breaks its own hash; a removed record breaks the link of
 * the next one.
 */
final class FiscalChainVerifier
{
    private const BATCH = 500;

    /**
     * @param ?\Closure $afterRecords test seam: runs between reading the records and
     *                               the chain head, where a concurrent sale would land
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?\Closure $afterRecords = null,
    ) {
    }

    /**
     * @return array{ok: bool, records: int, broken_id: ?int, reason: ?string, protected: bool}
     */
    public function verify(): array
    {
        // One consistent snapshot for the records and the head: a sale committed
        // while the check runs must not look like removed records.
        if (!$this->connection->isTransactionActive()) {
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $this->connection->beginTransaction();
        try {
            return $this->verifyInSnapshot();
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return array{ok: bool, records: int, broken_id: ?int, reason: ?string, protected: bool} */
    private function verifyInSnapshot(): array
    {
        $previousHash = '';
        $count = 0;
        $lastId = 0;
        do {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT * FROM fiscal_record WHERE id > ? ORDER BY id LIMIT ' . self::BATCH,
                [$lastId]
            );
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                if ((string) ($row['previous_hash'] ?? '') !== $previousHash) {
                    return $this->broken($count, $id, 'El registro no enlaza con el anterior (falta o se ha alterado un registro)');
                }
                $expected = FiscalHash::alta(
                    (string) $row['issuer_nif'],
                    (string) $row['number'],
                    (string) $row['issue_date'],
                    (string) $row['invoice_type'],
                    (string) $row['tax_total'],
                    (string) $row['total'],
                    $previousHash,
                    (string) $row['generated_at']
                );
                if ($expected !== (string) $row['hash']) {
                    return $this->broken($count, $id, 'La huella no coincide con los datos del registro (registro modificado)');
                }
                $previousHash = $expected;
                $lastId = $id;
                ++$count;
            }
        } while (\count($rows) === self::BATCH);

        if ($this->afterRecords !== null) {
            ($this->afterRecords)();
        }

        $head = $this->connection->fetchOne('SELECT last_hash FROM fiscal_chain WHERE id = 1');
        if ((string) ($head ?: '') !== $previousHash) {
            return $this->broken($count, null, 'El último registro no coincide con la cabeza de la cadena (se han borrado registros finales)');
        }

        return ['ok' => true, 'records' => $count, 'broken_id' => null, 'reason' => null, 'protected' => FiscalRecordTriggers::exist($this->connection)];
    }

    /** @return array{ok: bool, records: int, broken_id: ?int, reason: ?string, protected: bool} */
    private function broken(int $count, ?int $id, string $reason): array
    {
        return ['ok' => false, 'records' => $count, 'broken_id' => $id, 'reason' => $reason, 'protected' => FiscalRecordTriggers::exist($this->connection)];
    }
}
