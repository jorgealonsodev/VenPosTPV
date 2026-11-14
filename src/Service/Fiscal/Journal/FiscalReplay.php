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
 * 3. Preflight, for every row to insert by its original id: free, it is inserted;
 *    holding the same row, it is kept; holding the same entity in an older state
 *    (same id and the same identity columns, IDENTITY: a refund pending at backup
 *    time and completed after it, an exchange retried after it), it is updated to
 *    the journal's state; holding another row, a movement, an upload or a session
 *    (ids that are not fiscal identifiers, taken by an action the guard does not
 *    refuse, such as a manual cash movement) goes under a fresh id and every
 *    reference to it among the rows replayed follows (`remaps` in the result), while
 *    any other table (fiscal_record, order_meta, sale_line, refund, invoice…) is a
 *    conflict naming table and id. Every terminal and user named must exist.
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
 * them shows; the two normalised are the ones D8 names: sessions come back open (a
 * session already there with the same identity is kept as it is) and
 * uploads are re-queued (the shop deduplicates sales by reference and refunds by
 * request_id), except uploads the administrator discarded. Stock, Z reports, print
 * jobs, customers and the rest of D8.4 are not restored. AUTO_INCREMENT follows the
 * inserted ids by itself (InnoDB), so the next sale gets a new id.
 *
 * It only runs while the guard refuses to issue (D8.1). A database that holds every
 * journal document and more (a flush pending) has nothing to replay (AHEAD), and a
 * torn last line the next flush repairs is refused until then.
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

    /**
     * The columns that never change for one entity, by table: a row already there
     * with the same id and these is the same entity in an older state, updated to
     * the journal's state. (Not created_at: SqlDumper backups lose it.)
     */
    private const IDENTITY = [
        'refund' => ['request_id', 'order_id', 'ps_order_id'],
        'refund_line' => ['refund_id', 'line_index', 'product_id', 'id_product_attribute'],
        'exchange' => ['refund_id', 'order_id', 'ps_order_id', 'user_id'],
        'transfer_payment' => ['order_id'],
        'issued_coupon' => ['ps_cart_rule_id', 'code'],
        'movement' => ['session_id', 'type', 'amount', 'order_id', 'concept'],
        'outbox' => ['type', 'request_id'],
        'session' => ['terminal_id', 'user_id', 'opened_at', 'opening_cash'],
    ];

    /** Tables whose ids are not fiscal identifiers: a row holding the id moves the journaled one to a fresh id. */
    private const REMAPPABLE = ['movement', 'outbox', 'session'];

    /** Where the rows replayed name a remapped id, by remapped table. */
    private const REFERENCES = [
        'session' => ['order_meta' => 'session_id', 'sale_line' => 'session_id', 'movement' => 'session_id', 'refund' => 'session_id', 'exchange' => 'voucher_session_id'],
        'outbox' => ['outbox' => 'depends_on'],
        'movement' => [],
    ];

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
        foreach ([$primary, $secondary] as $location) {
            $torn = $this->tornTail($location);
            if ($torn !== null) {
                return new FiscalReplayResult(FiscalReplayResult::ERROR, [], sprintf(
                    'La última línea del diario fiscal (%s) quedó a medio escribir: no se recupera nada todavía. El siguiente tpv:cron (o tpv:fiscal:journal) la repara; después vuelve a ejecutar tpv:fiscal:replay.',
                    $torn,
                ));
            }
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

        $blocked = $this->guard->check()['state'] !== FiscalJournalGuard::OK;

        $head = (string) ($this->connection->fetchOne('SELECT last_hash FROM fiscal_chain WHERE id = 1') ?: '');
        $scan = $this->scan($head);
        if ($scan['first'] === null) {
            return $this->nothing();
        }
        if (!$scan['found'] && !$scan['fromStart']) {
            $last = $scan['last']['fiscal_record'];
            if ($this->connection->fetchOne('SELECT 1 FROM fiscal_record WHERE id = ? AND hash = ?', [(int) $last['id'], (string) $last['hash']]) !== false) {
                $ahead = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fiscal_record WHERE id > ?', [(int) $last['id']]);

                return new FiscalReplayResult(FiscalReplayResult::AHEAD, [], sprintf(
                    'La base de datos va por delante del diario fiscal (%d documentos aún sin anotar): no hay nada que recuperar y no se ha escrito nada. El siguiente tpv:cron (o tpv:fiscal:journal) los anota.',
                    $ahead,
                ));
            }
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
        if (!$blocked) {
            // D8.1: only a database the guard finds behind is replayed into.
            return new FiscalReplayResult(FiscalReplayResult::ERROR, [], 'Faltan documentos del diario fiscal en la base de datos, pero la caja no está bloqueada: no se recupera nada. Revisa el diario con tpv:fiscal:journal --verify-all.');
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
            return new FiscalReplayResult(FiscalReplayResult::PENDING, $documents, null, $plan['remaps']);
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
            ), $plan['remaps']);
        }

        return new FiscalReplayResult(FiscalReplayResult::DONE, $documents, null, $plan['remaps']);
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
     * @return array{first: ?array<string, mixed>, last: ?array<string, mixed>, found: bool, fromStart: bool, lines: list<array<string, mixed>>}
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

        return ['first' => $first, 'last' => $previous, 'found' => $found, 'fromStart' => $fromStart, 'lines' => $lines];
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
        $plan = ['lines' => $lines, 'rows' => [], 'refs' => [], 'sessions' => [], 'counters' => [], 'refunded' => [], 'present' => [], 'update' => [], 'remaps' => [], 'skip' => []];
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
        $next = [];
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
        // Sessions first: a remapped one moves the rows that name it.
        foreach ($plan['sessions'] as $id => $row) {
            $existing = $this->row('session', $id);
            if ($existing !== null && self::sameIdentity('session', $existing, $row)) {
                // The same session (closed or not since): kept as it is.
                $plan['present']['session'][$id] = true;

                continue;
            }
            if ($existing !== null) {
                $to = $this->remap($plan, $next, 'session', $id);
                $plan['sessions'][$id]['id'] = $to;
            }
            self::references('session', $row, $terminals, $users);
        }
        foreach (array_keys($plan['rows']) as $table) {
            foreach (array_keys($plan['rows'][$table]) as $id) {
                $row = $plan['rows'][$table][$id];
                $existing = $this->row($table, $id);
                if ($existing === null) {
                    self::references($table, $row, $terminals, $users);
                } elseif (self::same($table, $existing, $row)) {
                    $plan['present'][$table][$id] = true;
                } elseif (self::sameIdentity($table, $existing, $row)) {
                    $plan['update'][$table][$id] = true;
                } elseif (\in_array($table, self::REMAPPABLE, true)) {
                    $to = $this->remap($plan, $next, $table, $id);
                    $plan['rows'][$table][$id]['id'] = $to;
                    self::references($table, $row, $terminals, $users);
                } else {
                    $conflicts[] = sprintf('%s id %d ya existe con otro contenido', $table, $id);
                }
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
     * A fresh id for a journaled row whose original id holds another row (above every
     * id of the table and of the rows replayed), and every reference to it among the
     * rows replayed rewritten.
     *
     * @param array<string, mixed> $plan
     * @param array<string, int> $next
     */
    private function remap(array &$plan, array &$next, string $table, int $from): int
    {
        if (!isset($next[$table])) {
            $planned = array_keys($table === 'session' ? $plan['sessions'] : ($plan['rows'][$table] ?? []));
            $next[$table] = max([(int) $this->connection->fetchOne(sprintf('SELECT COALESCE(MAX(id), 0) FROM `%s`', $table)), ...$planned]);
        }
        $to = ++$next[$table];
        foreach (self::REFERENCES[$table] as $referring => $column) {
            foreach ($plan['rows'][$referring] ?? [] as $id => $row) {
                if (is_numeric($row[$column] ?? null) && (int) $row[$column] === $from) {
                    $plan['rows'][$referring][$id][$column] = $to;
                }
            }
        }
        $plan['remaps'][] = ['table' => $table, 'from' => $from, 'to' => $to];

        return $to;
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
                $ids = array_map(static fn (array $row): int => (int) $row['id'], array_values($plan['sessions']));
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
                    if (!isset($written[$table][$id])) {
                        $this->put($plan, $table, $id);
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
                foreach (array_keys($rows) as $id) {
                    $this->put($plan, $table, $id);
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

    /**
     * Writes one journaled row: kept when already there, updated to the journal's
     * state when it is the same entity in an older state, inserted otherwise (under
     * its fresh id when remapped).
     *
     * @param array<string, mixed> $plan
     */
    private function put(array $plan, string $table, int $id): void
    {
        if (isset($plan['present'][$table][$id])) {
            return;
        }
        $row = $plan['rows'][$table][$id];
        if (!isset($plan['update'][$table][$id])) {
            $this->insert($table, $row);

            return;
        }
        unset($row['id']);
        $columns = array_map(static fn (string $column): string => '`' . str_replace('`', '``', $column) . '` = ?', array_keys($row));
        try {
            $this->connection->executeStatement(sprintf('UPDATE `%s` SET %s WHERE id = ?', $table, implode(', ', $columns)), [...array_values($row), $id]);
        } catch (UniqueConstraintViolationException $e) {
            throw new FiscalReplayConflict(sprintf('%s id %d choca con otra fila (%s)', $table, $id, $e->getMessage()), 0, $e);
        }
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
     * Whether a row already there with the same id is the same entity (IDENTITY).
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $row
     */
    private static function sameIdentity(string $table, array $existing, array $row): bool
    {
        if (!isset(self::IDENTITY[$table])) {
            return false;
        }
        foreach (self::IDENTITY[$table] as $column) {
            if (!\array_key_exists($column, $existing) || !\array_key_exists($column, $row)) {
                return false;
            }
            $value = $row[$column];
            $current = $existing[$column];
            if ($value === null || $current === null ? $value !== $current : (string) $value !== (string) $current) {
                return false;
            }
        }

        return true;
    }

    /**
     * The file whose last line is a torn write the next flush cuts off (no newline,
     * or not JSON, and not a line head.json records), null otherwise. A complete line
     * that was cut afterwards is damage, reported by JournalVerifier instead.
     */
    private function tornTail(JournalLocation $location): ?string
    {
        $files = $location->journalFiles();
        if ($files === []) {
            return null;
        }
        $file = $files[\count($files) - 1];
        clearstatcache(true, $file);
        $size = (int) @filesize($file);
        if ($size === 0) {
            return null;
        }
        $length = min($size, 1 << 20);
        $chunk = (string) @file_get_contents($file, false, null, $size - $length, $length);
        if (str_ends_with($chunk, "\n")) {
            $body = substr($chunk, 0, -1);
            $start = strrpos($body, "\n");
            if ($start === false && $length < $size) {
                return null;
            }
            $last = $start === false ? $body : substr($body, $start + 1);
            try {
                json_decode($last, true, 512, \JSON_THROW_ON_ERROR);

                return null;
            } catch (\JsonException) {
            }
        }
        $valid = $location->lastValidLine();
        $head = $location->readHead();
        if ($head !== null && (\is_array($valid) ? (int) $valid['seq'] : 0) < $head['seq']) {
            return null;
        }

        return basename($file);
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
