<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use VenPosTPV\Service\Fiscal\FiscalChainVerifier;
use VenPosTPV\Service\Fiscal\FiscalRecorder;

/**
 * Puts back into a restored database the documents the fiscal journal holds and
 * the database lacks (fiscal-journal S6, D8; command tpv:fiscal:replay). Guided,
 * never automatic (A1): without $apply it only lists what it would restore.
 *
 * 1. Under `var/fiscal-replay.lock` and the journal's `journal.lock` (the flush
 *    waits), the journal is verified whole (JournalVerifier, both folders) and so is
 *    the database's chain (FiscalChainVerifier): damage refuses the run.
 * 2. The documents to replay are the journal lines after the one holding the
 *    database's chain head (`fiscal_chain.last_hash`). A head the journal does not
 *    hold is a backup older than the journal (S10, when the record before the first
 *    line is not in the database either) or a conflict.
 * 3. Preflight: every original id to insert is free or holds the same row, every
 *    terminal and user named exists; otherwise a conflict naming table and id.
 * 4. One transaction for the whole run, in the code's lock order (session → counter
 *    → fiscal_chain): sessions missing are inserted open (D8.2: closed again by
 *    hand), counters raised with GREATEST (never lowered, the key taken from the
 *    line's `counter`, S11), then per document its rows with their original ids,
 *    `order_meta.refunded_amount` = GREATEST(current, the credit note's), its
 *    fiscal_record verbatim and the chain head; exchange and outbox rows go last
 *    (an exchange names a sale journaled after its credit note). The chain is
 *    verified before the commit; any failure rolls everything back, so a run killed
 *    or failing in the middle leaves the database as it was and the next run starts
 *    again from the same line. A second run finds nothing to do.
 *
 * The rows of a line come in two kinds (T1b): those captured at issue, inserted as
 * they are, and those read when the line was written (listed in `as_of_flush`:
 * movements, transfer payment, outbox, session, exchange, refund lines). Those are
 * the latest the journal knows, so they are inserted as the latest line that holds
 * them shows; the two normalised are the ones D8 names: sessions come back open and
 * uploads are re-queued (the shop deduplicates sales by reference and refunds by
 * request_id), except uploads the administrator discarded. Stock, Z reports, print
 * jobs, customers and the rest of D8.4 are not restored. AUTO_INCREMENT follows the
 * inserted ids by itself (InnoDB), so the next sale gets a new id.
 */
class FiscalReplay
{
    private const LOCK_WAIT_SECONDS = 10.0;

    /** Document rows in insert order (foreign keys: sale_line → order_meta, refund_line → refund). */
    private const DOCUMENT_TABLES = ['order_meta', 'sale_line', 'movement', 'transfer_payment', 'issued_coupon', 'refund', 'refund_line', 'invoice'];

    /** Rows that may name documents journaled later, written after every document. */
    private const LATER_TABLES = ['exchange', 'outbox'];

    /** Outbox columns reset to re-queue an upload (and ignored when comparing). */
    private const OUTBOX_QUEUE = ['status', 'attempts', 'record_failures', 'next_attempt_at', 'claim_token', 'claimed_at', 'last_error', 'done_at', 'updated_at'];

    /** Session columns cleared: a session closed after the backup comes back open (D8.4). */
    private const SESSION_CLOSE = ['closed_at', 'counted_cash', 'expected_cash', 'difference'];

    /** counter table => its key column besides the year */
    private const COUNTERS = ['ticket_sequence' => 'letter', 'credit_note_sequence' => null, 'invoice_sequence' => 'series'];

    /** Columns naming a terminal or a user, by table (D8.1: they must exist). */
    private const TERMINAL_COLUMNS = ['order_meta' => 'terminal_id', 'sale_line' => 'terminal_id', 'refund' => 'terminal_id', 'invoice' => 'terminal_id', 'session' => 'terminal_id'];
    private const USER_COLUMNS = ['order_meta' => 'user_id', 'sale_line' => 'user_id', 'refund' => 'user_id', 'exchange' => 'user_id', 'invoice' => 'user_id', 'session' => 'user_id'];

    private readonly FiscalJournalGuard $guard;

    /**
     * @param ?\Closure(array<string, mixed>, int): void $afterDocument test seam: runs inside the
     *        transaction after each document is written, with its line and how many were written
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly FiscalJournalLocations $locations,
        #[Autowire('%kernel.project_dir%/var/fiscal-replay.lock')] private readonly string $lockPath,
        private readonly ?LoggerInterface $logger = null,
        ?FiscalJournalGuard $guard = null,
        private readonly ?\Closure $afterDocument = null,
    ) {
        $this->guard = $guard ?? new FiscalJournalGuard($connection, $locations, $logger);
    }

    public function run(bool $apply): FiscalReplayResult
    {
        try {
            $lock = $this->lockReplay();
        } catch (\Throwable $e) {
            return new FiscalReplayResult(FiscalReplayResult::ERROR, [], 'No se puede preparar la recuperación: ' . $e->getMessage());
        }
        if ($lock === null) {
            return new FiscalReplayResult(FiscalReplayResult::ERROR, [], 'Ya hay una recuperación de documentos en marcha; espera a que termine.');
        }
        try {
            $journalLocks = [];
            foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
                if (!is_dir($location->directory())) {
                    continue;
                }
                try {
                    $handle = $location->lock(microtime(true) + self::LOCK_WAIT_SECONDS);
                } catch (JournalWriteException) {
                    // A folder whose lock cannot be opened cannot be written by the flush either.
                    continue;
                }
                if ($handle === null) {
                    foreach ($journalLocks as $taken) {
                        JournalLocation::unlock($taken);
                    }

                    return new FiscalReplayResult(FiscalReplayResult::ERROR, [], 'El diario fiscal está ocupado; vuelve a intentarlo en un minuto.');
                }
                $journalLocks[] = $handle;
            }
            try {
                return $this->runLocked($apply);
            } finally {
                foreach (array_reverse($journalLocks) as $taken) {
                    JournalLocation::unlock($taken);
                }
            }
        } catch (FiscalReplayConflict $e) {
            return new FiscalReplayResult(FiscalReplayResult::CONFLICT, [], 'Conflicto: ' . $e->getMessage() . '. No se ha escrito nada.');
        } catch (\Throwable $e) {
            $this->logger?->error('Fiscal replay failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return new FiscalReplayResult(FiscalReplayResult::ERROR, [], 'La recuperación ha fallado y no se ha escrito nada: ' . $e->getMessage());
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    private function runLocked(bool $apply): FiscalReplayResult
    {
        $primary = $this->locations->primary();
        $secondary = $this->locations->secondary();
        if ($primary->journalId() === null) {
            return $secondary->journalId() === null
                ? new FiscalReplayResult(FiscalReplayResult::NOTHING, [], 'El diario fiscal no está iniciado: no hay nada que recuperar.')
                : new FiscalReplayResult(FiscalReplayResult::ERROR, [], 'El diario fiscal de backups/fiscal-journal ha perdido su journal.id: no se recupera nada desde él.');
        }
        foreach ([[$primary, true], [$secondary, false]] as [$location, $complete]) {
            $damaged = JournalVerifier::verify($location, null, true, $complete)['damaged'];
            if ($damaged !== null) {
                return new FiscalReplayResult(FiscalReplayResult::ERROR, [], sprintf(
                    'El diario fiscal está dañado (%s, línea %s: %s): no se recupera nada. Revísalo con tpv:fiscal:journal --verify-all.',
                    $damaged['file'] ?? '?',
                    $damaged['line'] ?? '-',
                    $damaged['reason'],
                ));
            }
        }
        $chain = (new FiscalChainVerifier($this->connection))->verify();
        if (!$chain['ok']) {
            return new FiscalReplayResult(FiscalReplayResult::ERROR, [], sprintf('La cadena de registros fiscales de la base de datos no es válida (%s): no se recupera nada.', $chain['reason']));
        }

        $head = (string) ($this->connection->fetchOne('SELECT last_hash FROM fiscal_chain WHERE id = 1') ?: '');
        $scan = $this->scan($head);
        if ($scan['first'] === null) {
            return $this->nothing();
        }
        if (!$scan['found'] && !$scan['fromStart']) {
            $record = $scan['first']['fiscal_record'];
            $before = (string) ($record['previous_hash'] ?? '');
            if ($before !== '' && $this->connection->fetchOne('SELECT 1 FROM fiscal_record WHERE hash = ? LIMIT 1', [$before]) === false) {
                return new FiscalReplayResult(FiscalReplayResult::TOO_OLD, [], sprintf(
                    'La copia restaurada es anterior al diario conservado (desde %s). Restaura una copia más reciente.',
                    (new \DateTimeImmutable((string) $record['generated_at']))->setTimezone(new \DateTimeZone(FiscalRecorder::TIME_ZONE))->format('d/m/Y'),
                ));
            }
            $last = $this->connection->fetchOne('SELECT number FROM fiscal_record ORDER BY id DESC LIMIT 1');

            return new FiscalReplayResult(FiscalReplayResult::CONFLICT, [], sprintf(
                'Conflicto: la base de datos tiene documentos que el diario fiscal no tiene (su último registro, %s, no está en el diario). No se ha escrito nada.',
                \is_string($last) ? $last : '?',
            ));
        }
        $lines = $scan['lines'];
        if ($lines === []) {
            return $this->nothing();
        }

        $plan = $this->plan($lines, $head);
        $documents = array_map(static fn (array $line): array => [
            'seq' => (int) $line['seq'],
            'kind' => (string) $line['kind'],
            'number' => (string) $line['fiscal_record']['number'],
            'generated_at' => (string) $line['fiscal_record']['generated_at'],
        ], $lines);
        $this->preflight($plan);
        if (!$apply) {
            return new FiscalReplayResult(FiscalReplayResult::PENDING, $documents);
        }

        $this->write($plan, $head);
        $this->logger?->info('Fiscal replay: {count} documents restored from the fiscal journal ({first} to {last})', [
            'count' => \count($documents),
            'first' => $documents[0]['number'],
            'last' => $documents[\count($documents) - 1]['number'],
        ]);
        $report = $this->guard->report();
        if ($report['state'] !== FiscalJournalGuard::OK) {
            return new FiscalReplayResult(FiscalReplayResult::STILL_BLOCKED, $documents, sprintf(
                'Recuperados %d documentos, pero la caja sigue bloqueada: falta %s en la base de datos y el diario fiscal no lo tiene para recuperarlo.',
                \count($documents),
                $report['missing']['number'] ?? '?',
            ));
        }

        return new FiscalReplayResult(FiscalReplayResult::DONE, $documents);
    }

    private function nothing(): FiscalReplayResult
    {
        $report = $this->guard->report();
        if ($report['state'] !== FiscalJournalGuard::OK) {
            return new FiscalReplayResult(FiscalReplayResult::STILL_BLOCKED, [], sprintf(
                'No hay documentos del diario que recuperar, pero la caja sigue bloqueada: falta %s en la base de datos.',
                $report['missing']['number'] ?? '?',
            ));
        }

        return new FiscalReplayResult(FiscalReplayResult::NOTHING, [], 'Nada que recuperar: la base de datos tiene todos los documentos del diario fiscal.');
    }

    /**
     * Reads the journal (the primary, then the lines of the secondary that continue
     * it) and keeps the lines after the one holding $head. Only valid lines chained
     * to the previous one are read; the journal was verified just before.
     *
     * @return array{first: ?array<string, mixed>, found: bool, fromStart: bool, lines: list<array<string, mixed>>}
     */
    private function scan(string $head): array
    {
        $first = null;
        $previous = null;
        $found = false;
        $fromStart = false;
        $lines = [];
        foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
            foreach ($location->lines() as $line) {
                if (!JournalLocation::selfValid($line) || !\is_array($line['fiscal_record'] ?? null)) {
                    break;
                }
                if ($previous !== null && $line['seq'] <= $previous['seq']) {
                    // Left in the secondary by a merge: already in the primary.
                    continue;
                }
                $chained = $previous === null ? $line['seq'] === 1 && ($line['prev'] ?? null) === '' : JournalLocation::follows($line, $previous);
                if (!$chained) {
                    break;
                }
                $previous = $line;
                if ($first === null) {
                    $first = $line;
                    $fromStart = (string) ($line['fiscal_record']['previous_hash'] ?? '') === $head;
                }
                if ($head !== '' && ($line['fiscal_record']['hash'] ?? null) === $head) {
                    $found = true;
                    $lines = [];

                    continue;
                }
                if ($found || $fromStart) {
                    $lines[] = $line;
                }
            }
        }

        return ['first' => $first, 'found' => $found, 'fromStart' => $fromStart, 'lines' => $lines];
    }

    /**
     * What the lines would write: the rows by table and id (the latest line that
     * holds a row wins), the rows of each document in insert order, the sessions,
     * the counters and the refunded amounts.
     *
     * @param list<array<string, mixed>> $lines
     *
     * @return array{lines: list<array<string, mixed>>, rows: array<string, array<int, array<string, mixed>>>, refs: array<int, list<array{0: string, 1: int}>>, sessions: array<int, array<string, mixed>>, counters: array<string, array{table: string, column: ?string, key: ?string, year: int, number: int}>, refunded: array<int, list<array{id: int, value: string}>>, present: array<string, array<int, true>>, skip: array<int, true>}
     */
    private function plan(array $lines, string $head): array
    {
        $now = (string) $this->connection->fetchOne('SELECT NOW()');
        $plan = ['lines' => $lines, 'rows' => [], 'refs' => [], 'sessions' => [], 'counters' => [], 'refunded' => [], 'present' => [], 'skip' => []];
        $previousHash = $head;
        foreach ($lines as $index => $line) {
            $seq = (int) $line['seq'];
            $record = $line['fiscal_record'];
            if (!\in_array($line['kind'] ?? null, [FiscalRecorder::SALE, FiscalRecorder::CREDIT_NOTE, FiscalRecorder::INVOICE_REPLACING_TICKET], true)
                || ($record['invoice_type'] ?? null) !== $line['kind'] || !\is_string($record['hash'] ?? null) || !\is_string($record['number'] ?? null)) {
                throw new \UnexpectedValueException(sprintf('la línea %d del diario no es un documento que se pueda recuperar', $seq));
            }
            self::rowId($record, 'fiscal_record', $seq);
            if ((string) ($record['previous_hash'] ?? '') !== $previousHash) {
                throw new \UnexpectedValueException(sprintf('el registro %s de la línea %d no enlaza con el anterior', $record['number'], $seq));
            }
            $previousHash = $record['hash'];

            $refs = [];
            foreach (\is_array($line['rows'] ?? null) ? $line['rows'] : [] as $table => $value) {
                if ($value === null || $value === []) {
                    continue;
                }
                if ($table === 'order_meta_refunded_amount') {
                    if (!\is_array($value) || !is_numeric($value['order_meta_id'] ?? null) || !is_numeric($value['value'] ?? null)) {
                        throw new \UnexpectedValueException(sprintf('la línea %d tiene un importe devuelto no válido', $seq));
                    }
                    $plan['refunded'][$index][] = ['id' => (int) $value['order_meta_id'], 'value' => (string) $value['value']];

                    continue;
                }
                if ($table === 'session') {
                    $plan['sessions'][self::rowId($value, $table, $seq)] = self::normalized($table, $value, $now);

                    continue;
                }
                if (!\in_array($table, self::DOCUMENT_TABLES, true) && !\in_array($table, self::LATER_TABLES, true)) {
                    throw new \UnexpectedValueException(sprintf('la línea %d tiene filas «%s» que la recuperación no conoce', $seq, $table));
                }
                foreach (array_is_list($value) ? $value : [$value] as $row) {
                    $id = self::rowId($row, $table, $seq);
                    $plan['rows'][$table][$id] = self::normalized($table, $row, $now);
                    if (\in_array($table, self::DOCUMENT_TABLES, true)) {
                        $refs[] = [$table, $id];
                    }
                }
            }
            usort($refs, static fn (array $a, array $b): int => array_search($a[0], self::DOCUMENT_TABLES, true) <=> array_search($b[0], self::DOCUMENT_TABLES, true));
            $plan['refs'][$index] = $refs;

            $counter = $line['counter'] ?? null;
            if ($counter !== null) {
                $counter = self::counter($counter, $seq);
                $name = implode('|', [$counter['table'], $counter['key'] ?? '', $counter['year']]);
                if (!isset($plan['counters'][$name]) || $plan['counters'][$name]['number'] < $counter['number']) {
                    $plan['counters'][$name] = $counter;
                }
            }
        }

        return $plan;
    }

    /**
     * Every row to insert must be free or hold the same row; every terminal and user
     * named must exist (D8.1). Fills in what is already there.
     *
     * @param array<string, mixed> $plan
     *
     * @throws FiscalReplayConflict
     */
    private function preflight(array &$plan): void
    {
        $conflicts = [];
        $terminals = [];
        $users = [];
        foreach ($plan['lines'] as $index => $line) {
            $record = $line['fiscal_record'];
            $terminals[(int) $record['terminal_id']] = true;
            $existing = $this->row('fiscal_record', (int) $record['id']);
            if ($existing !== null) {
                if (self::same('fiscal_record', $existing, $record)) {
                    $plan['skip'][$index] = true;
                } else {
                    $conflicts[] = sprintf('fiscal_record id %d ya existe con otro contenido', $record['id']);
                }
            }
        }
        foreach ($plan['rows'] as $table => $rows) {
            foreach ($rows as $id => $row) {
                $existing = $this->row($table, $id);
                if ($existing === null) {
                    self::references($table, $row, $terminals, $users);
                } elseif (self::same($table, $existing, $row)) {
                    $plan['present'][$table][$id] = true;
                } else {
                    $conflicts[] = sprintf('%s id %d ya existe con otro contenido', $table, $id);
                }
            }
        }
        foreach ($plan['sessions'] as $id => $row) {
            if ($this->row('session', $id) !== null) {
                $plan['present']['session'][$id] = true;
            } else {
                self::references('session', $row, $terminals, $users);
            }
        }
        foreach (['terminal' => $terminals, 'user' => $users] as $table => $ids) {
            foreach (array_keys($ids) as $id) {
                if ($this->connection->fetchOne(sprintf('SELECT 1 FROM `%s` WHERE id = ?', $table), [$id]) === false) {
                    $conflicts[] = sprintf('falta %s id %d', $table === 'terminal' ? 'el terminal' : 'el usuario', $id);
                }
            }
        }
        if ($conflicts !== []) {
            throw new FiscalReplayConflict(implode('; ', \array_slice($conflicts, 0, 10)) . (\count($conflicts) > 10 ? sprintf(' (y %d más)', \count($conflicts) - 10) : ''));
        }
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function write(array $plan, string $head): void
    {
        $connection = $this->connection;
        // FiscalChainVerifier opens its own transaction: nested, it must be a savepoint.
        $savepoints = $connection->getNestTransactionsWithSavepoints();
        if (!$savepoints && !$connection->isTransactionActive()) {
            $connection->setNestTransactionsWithSavepoints(true);
        }
        $connection->beginTransaction();
        try {
            // Lock order of the issuing code: session → counter → fiscal_chain.
            ksort($plan['sessions']);
            foreach ($plan['sessions'] as $id => $row) {
                if (!isset($plan['present']['session'][$id])) {
                    $this->insert('session', $row);
                }
            }
            if ($plan['sessions'] !== []) {
                $ids = array_keys($plan['sessions']);
                $connection->fetchFirstColumn(sprintf('SELECT id FROM session WHERE id IN (%s) FOR UPDATE', implode(',', array_fill(0, \count($ids), '?'))), $ids);
            }
            foreach ($plan['counters'] as $counter) {
                $this->raiseCounter($counter);
            }
            $chain = $connection->fetchAssociative('SELECT last_hash FROM fiscal_chain WHERE id = 1 FOR UPDATE');
            if ($chain === false) {
                $connection->executeStatement('INSERT IGNORE INTO fiscal_chain (id, last_record_id, last_hash) VALUES (1, NULL, NULL)');
                $chain = $connection->fetchAssociative('SELECT last_hash FROM fiscal_chain WHERE id = 1 FOR UPDATE');
            }
            $current = (string) (($chain['last_hash'] ?? null) ?: '');
            if ($current !== $head) {
                throw new FiscalReplayConflict('la cabeza de la cadena fiscal ha cambiado mientras se preparaba la recuperación');
            }

            $written = [];
            $done = 0;
            foreach ($plan['lines'] as $index => $line) {
                if (isset($plan['skip'][$index])) {
                    continue;
                }
                foreach ($plan['refs'][$index] as [$table, $id]) {
                    if (!isset($plan['present'][$table][$id]) && !isset($written[$table][$id])) {
                        $this->insert($table, $plan['rows'][$table][$id]);
                        $written[$table][$id] = true;
                    }
                }
                foreach ($plan['refunded'][$index] ?? [] as $refunded) {
                    $connection->executeStatement('UPDATE order_meta SET refunded_amount = GREATEST(refunded_amount, ?) WHERE id = ?', [$refunded['value'], $refunded['id']]);
                }
                $record = $line['fiscal_record'];
                if ((string) ($record['previous_hash'] ?? '') !== $current) {
                    throw new FiscalReplayConflict(sprintf('el registro %s no enlaza con la cabeza de la cadena', $record['number']));
                }
                $this->insert('fiscal_record', $record);
                $connection->update('fiscal_chain', ['last_record_id' => (int) $record['id'], 'last_hash' => $record['hash']], ['id' => 1]);
                $current = (string) $record['hash'];
                ++$done;
                if ($this->afterDocument !== null) {
                    ($this->afterDocument)($line, $done);
                }
            }
            foreach (self::LATER_TABLES as $table) {
                $rows = $plan['rows'][$table] ?? [];
                ksort($rows);
                foreach ($rows as $id => $row) {
                    if (!isset($plan['present'][$table][$id])) {
                        $this->insert($table, $row);
                    }
                }
            }

            $verified = (new FiscalChainVerifier($connection))->verify();
            if (!$verified['ok']) {
                throw new \RuntimeException(sprintf('la cadena fiscal no sería válida después (%s)', $verified['reason']));
            }
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        } finally {
            if (!$savepoints && !$connection->isTransactionActive() && $connection->getNestTransactionsWithSavepoints()) {
                $connection->setNestTransactionsWithSavepoints(false);
            }
        }
    }

    /**
     * Inserts the year row first (as prepareYear) and raises the counter to the
     * replayed number, never lowering it.
     *
     * @param array{table: string, column: ?string, key: ?string, year: int, number: int} $counter
     */
    private function raiseCounter(array $counter): void
    {
        $where = 'year = ?';
        $parameters = [$counter['year']];
        $columns = ['year', 'last_number'];
        $values = [$counter['year'], 0];
        if ($counter['column'] !== null) {
            $where = $counter['column'] . ' = ? AND ' . $where;
            array_unshift($parameters, $counter['key']);
            array_unshift($columns, $counter['column']);
            array_unshift($values, $counter['key']);
        }
        $this->connection->executeStatement(sprintf('INSERT IGNORE INTO %s (%s) VALUES (%s)', $counter['table'], implode(', ', $columns), implode(', ', array_fill(0, \count($values), '?'))), $values);
        $this->connection->executeStatement(sprintf('UPDATE %s SET last_number = GREATEST(last_number, ?) WHERE %s', $counter['table'], $where), [$counter['number'], ...$parameters]);
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): void
    {
        $columns = array_map(static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`', array_keys($row));
        try {
            $this->connection->executeStatement(
                sprintf('INSERT INTO `%s` (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', array_fill(0, \count($row), '?'))),
                array_values($row),
            );
        } catch (UniqueConstraintViolationException $e) {
            throw new FiscalReplayConflict(sprintf('%s id %s choca con otra fila (%s)', $table, $row['id'] ?? '?', $e->getMessage()), 0, $e);
        }
    }

    /** @return array<string, mixed>|null */
    private function row(string $table, int $id): ?array
    {
        $row = $this->connection->fetchAssociative(sprintf('SELECT * FROM `%s` WHERE id = ?', $table), [$id]);

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function normalized(string $table, array $row, string $now): array
    {
        if ($table === 'session') {
            foreach (self::SESSION_CLOSE as $column) {
                if (\array_key_exists($column, $row)) {
                    $row[$column] = null;
                }
            }
        }
        if ($table === 'outbox' && ($row['status'] ?? null) !== 'discarded') {
            $queued = ['status' => 'pending', 'attempts' => 0, 'record_failures' => 0, 'next_attempt_at' => $now, 'claim_token' => null, 'claimed_at' => null, 'last_error' => null, 'done_at' => null, 'updated_at' => $now];
            foreach ($queued as $column => $value) {
                if (\array_key_exists($column, $row)) {
                    $row[$column] = $value;
                }
            }
        }

        return $row;
    }

    /**
     * Whether a row already in the database is the one the journal would insert (the
     * re-queue columns of an upload are not compared).
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $row
     */
    private static function same(string $table, array $existing, array $row): bool
    {
        foreach ($row as $column => $value) {
            if ($table === 'outbox' && \in_array($column, self::OUTBOX_QUEUE, true)) {
                continue;
            }
            if (!\array_key_exists($column, $existing)) {
                return false;
            }
            $current = $existing[$column];
            if ($value === null || $current === null ? $value !== $current : (string) $value !== (string) $current) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, true> $terminals
     * @param array<int, true> $users
     */
    private static function references(string $table, array $row, array &$terminals, array &$users): void
    {
        $terminal = self::TERMINAL_COLUMNS[$table] ?? null;
        if ($terminal !== null && is_numeric($row[$terminal] ?? null)) {
            $terminals[(int) $row[$terminal]] = true;
        }
        $user = self::USER_COLUMNS[$table] ?? null;
        if ($user !== null && is_numeric($row[$user] ?? null)) {
            $users[(int) $row[$user]] = true;
        }
    }

    /** @param mixed $row */
    private static function rowId(mixed $row, string $table, int $seq): int
    {
        if (!\is_array($row) || array_is_list($row) || !is_numeric($row['id'] ?? null) || (int) $row['id'] < 1) {
            throw new \UnexpectedValueException(sprintf('la línea %d tiene una fila de %s sin id', $seq, $table));
        }

        return (int) $row['id'];
    }

    /**
     * The counter key of a line (S11: stored explicitly, never derived from dates).
     *
     * @return array{table: string, column: ?string, key: ?string, year: int, number: int}
     */
    private static function counter(mixed $counter, int $seq): array
    {
        $table = \is_array($counter) ? ($counter['table'] ?? null) : null;
        if (!\is_string($table) || !\array_key_exists($table, self::COUNTERS) || !\is_int($counter['year'] ?? null) || !\is_int($counter['number'] ?? null)
            || $counter['year'] < 2000 || $counter['year'] > 9999 || $counter['number'] < 1) {
            throw new \UnexpectedValueException(sprintf('la línea %d tiene un contador no válido', $seq));
        }
        $column = self::COUNTERS[$table];
        $key = null;
        if ($column !== null) {
            $key = $counter[$column] ?? null;
            if (!\is_string($key) || preg_match('/\A[A-Z]\z/D', $key) !== 1) {
                throw new \UnexpectedValueException(sprintf('la línea %d tiene un contador no válido', $seq));
            }
        }

        return ['table' => $table, 'column' => $column, 'key' => $key, 'year' => $counter['year'], 'number' => $counter['number']];
    }

    /** @return resource|null null when another replay holds the lock */
    private function lockReplay()
    {
        $folder = \dirname($this->lockPath);
        if (!is_dir($folder)) {
            @mkdir($folder, 0700, true);
        }
        $handle = @fopen($this->lockPath, 'c');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Cannot open %s', $this->lockPath));
        }
        if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }
}
