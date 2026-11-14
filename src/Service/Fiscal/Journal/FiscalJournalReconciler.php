<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use VenPosTPV\Service\Fiscal\FiscalRecorder;

/**
 * The every-minute catch-up of the fiscal journal (fiscal-journal D3.5, S2, S3),
 * run by tpv:fiscal:journal from tpv:cron:
 *
 * 1. flush: every committed document missing from the journal is appended, in
 *    order (FiscalJournalWriter::flush, which also merges the secondary back);
 * 2. leftover intents older than 2 minutes are resolved: in the database → the
 *    flush journals it (a journaled one is removed); absent and its number now
 *    holds another hash, or the chain moved past its previous_hash → never issued
 *    (moved to intents/discarded/); absent and its chain slot still free → kept,
 *    never blocking, listed after 10 minutes; a confirmed intent absent from the
 *    database → the database is behind (reported for the guard, D7);
 * 3. the lag (documents in fiscal_record not in the journal, and since when) is
 *    read from the database; the lines appended since the last run are checked
 *    (JournalVerifier), and the whole journal once a day, on demand
 *    (tpv:fiscal:journal --verify-all) and on every run while it is damaged;
 * 4. `state.json` is written atomically in every folder that can be written, and
 *    one alert e-mail goes out when the journal has been unable to catch up for
 *    more than 5 minutes, is damaged, or a document cannot be journaled.
 *
 * A document the writer cannot turn into a line stops the flush at it (order and
 * seq stay gapless): the writer's BLOCKED result names it and it is reported as
 * blocked, never skipped. A damaged journal (FiscalJournalFlushResult::DAMAGED, or
 * what JournalVerifier finds) and a primary that lost its journal.id
 * (JOURNAL_MISSING, for the guard) are never rewritten from the database.
 *
 * An incident (damaged, blocked, no folder writable) is reported as a failure of
 * the job, with its log messages, only on the run that first detects it and once a
 * day while it lasts; the runs in between exit 0 and log it as debug, so the
 * hosting panel, which mails STDERR, does not mail every minute.
 *
 * While no journal exists (the provisioning step creates it) nothing is done.
 */
class FiscalJournalReconciler
{
    public const INTENT_TIMEOUT_SECONDS = 120;
    public const UNCONFIRMED_VISIBLE_AFTER_SECONDS = 600;
    public const LAG_ALERT_AFTER_SECONDS = 300;
    /** A stored state older than this is not trusted by current(): the task may not be running. */
    public const STATE_FRESH_SECONDS = 180;
    public const FULL_CHECK_EVERY_SECONDS = 86400;

    /** @var array<string, true>|null hashes of the journaled documents, read once per run when needed */
    private ?array $journaledHashes = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly FiscalJournalLocations $locations,
        private readonly FiscalJournalIntents $intents,
        private readonly FiscalJournalAlerts $alerts,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?FiscalDocumentSnapshot $snapshot = null,
    ) {
    }

    public function run(?\DateTimeImmutable $now = null, bool $verifyAll = false): FiscalJournalState
    {
        $now = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone(FiscalRecorder::TIME_ZONE));
        $journalId = $this->journalId();
        if ($journalId === null) {
            return FiscalJournalState::notStarted($now);
        }
        $this->journaledHashes = null;
        $log = new IncidentLogBuffer($this->logger);
        $writer = new FiscalJournalWriter($this->connection, $this->locations, $this->intents, $log, $this->snapshot ?? new FiscalDocumentSnapshot($this->connection, $log));

        try {
            $flush = $writer->flush();
        } catch (\Throwable $e) {
            // The writer reports its own failures in its result; this is the database.
            $log->error('Fiscal journal flush failed: {message}', ['message' => $e->getMessage()]);
            $flush = new FiscalJournalFlushResult(FiscalJournalFlushResult::UNWRITABLE);
        }
        $unwritable = $flush->status === FiscalJournalFlushResult::UNWRITABLE;
        if ($unwritable) {
            // Held by the buffer: it goes out as an error only on the run that reports the incident.
            $log->error('Fiscal journal: no folder can be written; the documents wait in the database');
        }
        $headMissing = $flush->status === FiscalJournalFlushResult::HEAD_MISSING;
        $journalMissing = $flush->status === FiscalJournalFlushResult::JOURNAL_MISSING;

        $previous = $this->storedState();
        $lastFull = self::date($previous['full_verified_at'] ?? null);
        $full = $verifyAll
            || $lastFull === null || $lastFull > $now
            || $now->getTimestamp() - $lastFull->getTimestamp() >= self::FULL_CHECK_EVERY_SECONDS
            // A damaged journal is read whole on every run, so the state clears exactly when it is repaired.
            || ($previous['status'] ?? null) === FiscalJournalState::DAMAGED;
        $verification = $this->verify(\is_array($previous['verified'] ?? null) ? $previous['verified'] : [], $full);
        $damaged = $verification['damaged'];
        if ($damaged === null && ($flush->damaged || $flush->status === FiscalJournalFlushResult::DAMAGED)) {
            $folder = match ($flush->directory) {
                $this->locations->primary()->directory() => 'primary',
                $this->locations->secondary()->directory() => 'secondary',
                default => null,
            };
            $damaged = ['folder' => $folder, 'file' => null, 'line' => null, 'reason' => $flush->reason ?? JournalVerifier::BAD_LINE];
        }

        $head = $this->head();
        $journaledId = (int) ($head['fiscal_record']['id'] ?? 0);
        $intents = $this->resolveIntents($now, $headMissing ? null : $journaledId, $log);

        $pending = $headMissing ? self::noPending() : $this->pending($journaledId);
        $blocked = $flush->status === FiscalJournalFlushResult::BLOCKED && $pending['count'] > 0
            ? ['number' => (string) $flush->number, 'reason' => (string) $flush->reason]
            : null;
        $status = $journalMissing && $damaged === null ? FiscalJournalState::JOURNAL_MISSING : $this->status($damaged, $headMissing, $blocked, $pending, $now);

        $incident = match (true) {
            $damaged !== null => sprintf('damaged:%s:%s:%s:%s', $damaged['folder'] ?? '', $damaged['file'] ?? '', $damaged['line'] ?? '', $damaged['reason']),
            $journalMissing => 'journal_missing',
            $blocked !== null => 'blocked:' . $blocked['number'],
            $unwritable => 'unwritable',
            default => null,
        };
        $reported = $this->alerts->report($incident, $now);

        $data = [
            'v' => 1,
            'status' => $status,
            'checked_at' => $now->format(\DATE_ATOM),
            'journal_id' => $journalId,
            'writable' => !$unwritable,
            'last_document' => self::document($head),
            'pending' => $pending,
            'damaged' => $damaged,
            'blocked' => $blocked,
            'unconfirmed_intents' => $intents['unconfirmed'],
            'confirmed_missing' => $intents['confirmed_missing'],
            'alert' => null,
            'full_verified_at' => $full ? $now->format(\DATE_ATOM) : ($lastFull?->format(\DATE_ATOM)),
            'verified' => $verification['checkpoints'],
        ];
        $data['alert'] = $this->alert($data, $now);
        $this->writeState($data, $log);
        $log->release($reported);

        $exitCode = match (true) {
            !$reported => FiscalJournalState::EXIT_OK,
            $damaged !== null, $journalMissing => FiscalJournalState::EXIT_DAMAGED,
            default => FiscalJournalState::EXIT_UNWRITABLE,
        };

        return new FiscalJournalState($data, $exitCode);
    }

    /**
     * Checks both folders under their lock (a flush of a till cannot append half-way
     * through the check). Each checkpoint moves on only over lines found good.
     *
     * @param array<string, mixed> $checkpoints from the previous state
     *
     * @return array{damaged: ?array{folder: ?string, file: ?string, line: ?int, reason: string}, checkpoints: array<string, mixed>}
     */
    private function verify(array $checkpoints, bool $full): array
    {
        $damaged = null;
        $result = [];
        foreach (['primary' => $this->locations->primary(), 'secondary' => $this->locations->secondary()] as $name => $location) {
            $lock = null;
            try {
                $lock = is_file($location->directory() . '/journal.lock') || $location->isWritable() ? $location->lock() : null;
            } catch (\Throwable) {
                $lock = null;
            }
            try {
                $checkpoint = \is_array($checkpoints[$name] ?? null) ? $checkpoints[$name] : null;
                $verified = JournalVerifier::verify($location, $checkpoint, $full, $name === 'primary');
            } finally {
                if ($lock !== null) {
                    JournalLocation::unlock($lock);
                }
            }
            $result[$name] = $verified['checkpoint'];
            if ($damaged === null && $verified['damaged'] !== null) {
                $damaged = ['folder' => $name] + $verified['damaged'];
            }
        }

        return ['damaged' => $damaged, 'checkpoints' => $result];
    }

    /**
     * What GET /api/fiscal/journal shows: the state written by the last run, or,
     * when it is missing, unreadable or stale (the task is not running, or no folder
     * could be written), the lag read now from the database. Read-only.
     */
    public function current(?\DateTimeImmutable $now = null): FiscalJournalState
    {
        $now = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone(FiscalRecorder::TIME_ZONE));
        $journalId = $this->journalId();
        if ($journalId === null) {
            return FiscalJournalState::notStarted($now);
        }
        $stored = $this->storedState();
        $checkedAt = self::date($stored['checked_at'] ?? null);
        if ($stored !== null && $checkedAt !== null && $checkedAt <= $now
            && $now->getTimestamp() - $checkedAt->getTimestamp() <= self::STATE_FRESH_SECONDS) {
            return new FiscalJournalState($stored);
        }

        $head = $this->head();
        $headRecord = \is_array($head['fiscal_record'] ?? null) ? $head['fiscal_record'] : null;
        $headMissing = $headRecord !== null
            && $this->connection->fetchOne('SELECT 1 FROM fiscal_record WHERE id = ? AND hash = ?', [(int) ($headRecord['id'] ?? 0), (string) ($headRecord['hash'] ?? '')]) === false;
        $pending = $headMissing ? self::noPending() : $this->pending((int) ($headRecord['id'] ?? 0));
        // Damage does not heal by itself, nor a blocked document: the last run's finding stands.
        $damaged = \is_array($stored['damaged'] ?? null) ? $stored['damaged'] : null;
        $blocked = \is_array($stored['blocked'] ?? null) && $pending['count'] > 0 ? $stored['blocked'] : null;

        return new FiscalJournalState([
            'v' => 1,
            'status' => $this->status($damaged, $headMissing, $blocked, $pending, $now),
            'checked_at' => $now->format(\DATE_ATOM),
            'journal_id' => $journalId,
            'writable' => $this->locations->primary()->isWritable() || $this->locations->secondary()->isWritable(),
            'last_document' => self::document($head),
            'pending' => $pending,
            'damaged' => $damaged,
            'blocked' => $blocked,
            'unconfirmed_intents' => \is_array($stored['unconfirmed_intents'] ?? null) ? $stored['unconfirmed_intents'] : [],
            'confirmed_missing' => \is_array($stored['confirmed_missing'] ?? null) ? $stored['confirmed_missing'] : [],
            'alert' => \is_array($stored['alert'] ?? null) ? $stored['alert'] : null,
            'stale' => true,
        ]);
    }

    private function journalId(): ?string
    {
        return $this->locations->primary()->journalId() ?? $this->locations->secondary()->journalId();
    }

    /**
     * The newest valid state.json of the two folders (null when none can be read).
     *
     * @return array<string, mixed>|null
     */
    private function storedState(): ?array
    {
        $best = null;
        foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
            $state = $location->readState();
            $at = self::date($state['checked_at'] ?? null);
            if ($state === null || $at === null || !\is_string($state['status'] ?? null)) {
                continue;
            }
            if ($best === null || $at > $best[0]) {
                $best = [$at, $state];
            }
        }

        return $best[1] ?? null;
    }

    /**
     * The journal's last valid line (the later of the two folders).
     *
     * @return array<string, mixed>|null
     */
    private function head(): ?array
    {
        $head = null;
        foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
            $line = $location->lastValidLine();
            if (\is_array($line) && ($head === null || $line['seq'] > $head['seq'])) {
                $head = $line;
            }
        }

        return $head;
    }

    /**
     * D3.5 on the intents older than 2 minutes, in both folders.
     *
     * @param int|null $journaledId the last fiscal_record id in the journal (null: unknown, the head is not in the database)
     *
     * @return array{unconfirmed: list<array{number: string, created_at: string}>, confirmed_missing: list<string>}
     */
    private function resolveIntents(\DateTimeImmutable $now, ?int $journaledId, LoggerInterface $log): array
    {
        $unconfirmed = [];
        $confirmedMissing = [];
        foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
            foreach ($location->intents() as $hash => $intent) {
                try {
                    $createdAt = self::date($intent['created_at'] ?? null);
                    $age = $createdAt !== null ? $now->getTimestamp() - $createdAt->getTimestamp() : null;
                    if ($age === null || $age < self::INTENT_TIMEOUT_SECONDS) {
                        continue;
                    }
                    $record = \is_array($intent['fiscal_record'] ?? null) ? $intent['fiscal_record'] : [];
                    $number = (string) ($record['number'] ?? '');
                    $id = $this->connection->fetchOne('SELECT id FROM fiscal_record WHERE number = ? AND hash = ?', [$number, $hash]);
                    if ($id !== false) {
                        // Issued: the flush journals it; once journaled the intent is spent
                        // (a process killed between the append and the intent's removal).
                        if ($journaledId !== null ? (int) $id <= $journaledId : $this->isJournaled($hash)) {
                            $location->deleteIntent($hash);
                        }
                        continue;
                    }
                    if ($this->isJournaled($hash)) {
                        // In the journal and not in the database: spent all the same; whether
                        // the database is behind is told by the journal's head (D7), not by it.
                        $location->deleteIntent($hash);
                        continue;
                    }
                    if (($intent['state'] ?? null) === FiscalJournalIntents::STATE_CONFIRMED) {
                        $confirmedMissing[] = $number;
                        continue;
                    }
                    $taken = $this->connection->fetchOne('SELECT 1 FROM fiscal_record WHERE number = ? AND hash <> ?', [$number, $hash]) !== false;
                    $chainHead = (string) ($this->connection->fetchOne('SELECT last_hash FROM fiscal_chain WHERE id = 1') ?: '');
                    if ($taken || $chainHead !== (string) ($record['previous_hash'] ?? '')) {
                        $location->discardIntent($hash);
                        $log->info('Fiscal journal intent {number} was never issued: discarded', ['number' => $number]);
                        continue;
                    }
                    if ($age >= self::UNCONFIRMED_VISIBLE_AFTER_SECONDS) {
                        $unconfirmed[] = ['number' => $number, 'created_at' => (string) $intent['created_at']];
                    }
                } catch (\Throwable $e) {
                    $log->warning('Fiscal journal intent {hash} not resolved: {message}', ['hash' => $hash, 'message' => $e->getMessage()]);
                }
            }
        }

        return ['unconfirmed' => $unconfirmed, 'confirmed_missing' => array_values(array_unique($confirmedMissing))];
    }

    private function isJournaled(string $hash): bool
    {
        if ($this->journaledHashes === null) {
            $this->journaledHashes = [];
            foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
                foreach ($location->lines() as $line) {
                    $journaled = $line['fiscal_record']['hash'] ?? null;
                    if (\is_string($journaled)) {
                        $this->journaledHashes[$journaled] = true;
                    }
                }
            }
        }

        return isset($this->journaledHashes[$hash]);
    }

    /** @return array{count: int, oldest_number: ?string, oldest_at: ?string} */
    private function pending(int $journaledId): array
    {
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fiscal_record WHERE id > ?', [$journaledId]);
        if ($count === 0) {
            return self::noPending();
        }
        $oldest = $this->connection->fetchAssociative('SELECT number, generated_at FROM fiscal_record WHERE id > ? ORDER BY id LIMIT 1', [$journaledId]);

        return ['count' => $count, 'oldest_number' => (string) ($oldest['number'] ?? ''), 'oldest_at' => \is_string($oldest['generated_at'] ?? null) ? $oldest['generated_at'] : null];
    }

    /** @return array{count: int, oldest_number: ?string, oldest_at: ?string} */
    private static function noPending(): array
    {
        return ['count' => 0, 'oldest_number' => null, 'oldest_at' => null];
    }

    /**
     * @param array<string, mixed>|null $damaged
     * @param array<string, mixed>|null $blocked
     * @param array{count: int, oldest_number: ?string, oldest_at: ?string} $pending
     */
    private function status(?array $damaged, bool $headMissing, ?array $blocked, array $pending, \DateTimeImmutable $now): string
    {
        if ($damaged !== null) {
            return FiscalJournalState::DAMAGED;
        }
        if ($headMissing) {
            return FiscalJournalState::HEAD_MISSING;
        }
        if ($blocked !== null) {
            return FiscalJournalState::BLOCKED;
        }
        if ($pending['count'] === 0) {
            return FiscalJournalState::OK;
        }
        $oldest = self::date($pending['oldest_at']);
        if ($oldest !== null && $now->getTimestamp() - $oldest->getTimestamp() > self::LAG_ALERT_AFTER_SECONDS) {
            return FiscalJournalState::LAGGING;
        }

        return FiscalJournalState::PENDING;
    }

    /**
     * Sends (once per incident) the e-mail of a lagging or damaged journal.
     *
     * @param array<string, mixed> $data
     *
     * @return array{incident: string, sent_at: string}|null
     */
    private function alert(array $data, \DateTimeImmutable $now): ?array
    {
        $incident = null;
        $subject = '';
        $text = '';
        if ($data['status'] === FiscalJournalState::DAMAGED) {
            $damaged = $data['damaged'];
            $incident = sprintf('damaged:%s:%s:%s:%s', $damaged['folder'] ?? '', $damaged['file'] ?? '', $damaged['line'] ?? '', $damaged['reason']);
            $subject = 'VenPosTPV: diario fiscal dañado';
            $text = self::damagedText($damaged) . "\n\nNo se ha reescrito nada del diario y las ventas siguen funcionando.\n";
        } elseif ($data['status'] === FiscalJournalState::BLOCKED) {
            $incident = 'blocked:' . $data['blocked']['number'];
            $subject = 'VenPosTPV: un documento no se puede anotar en el diario fiscal';
            $text = sprintf("El diario fiscal no puede anotar el documento %s: %s\n\n", $data['blocked']['number'], $data['blocked']['reason'])
                . sprintf("Los %d documentos pendientes esperan, en orden, hasta que se resuelva. Las ventas siguen funcionando.\n", $data['pending']['count']);
        } elseif ($data['status'] === FiscalJournalState::LAGGING) {
            $incident = 'lagging';
            $subject = 'VenPosTPV: el diario fiscal no se puede anotar';
            $pending = $data['pending'];
            $oldest = self::date($pending['oldest_at']);
            $text = "El diario fiscal lleva más de 5 minutos sin poder anotarse. Revisa los permisos de la carpeta backups/fiscal-journal.\n\n"
                . sprintf("Documentos pendientes de anotar: %d (el primero, %s, del %s).\n\n", $pending['count'], $pending['oldest_number'], $oldest?->setTimezone(new \DateTimeZone(FiscalRecorder::TIME_ZONE))->format('d/m/Y H:i') ?? '')
                . "Las ventas siguen funcionando: los documentos están en la base de datos y se anotan solos en cuanto la carpeta se pueda escribir.\n";
        }

        return $this->alerts->notify($incident, $subject, $text, $now);
    }

    /** @param array{folder: ?string, file: ?string, line: ?int, reason: string} $damaged */
    public static function damagedText(array $damaged): string
    {
        if ($damaged['reason'] === JournalVerifier::REMOVED) {
            return $damaged['file'] !== null && $damaged['line'] !== null
                ? sprintf('Faltan líneas o archivos del diario fiscal (desde %s, línea %d). Ejecuta tpv:fiscal:journal --verify-all.', $damaged['file'], $damaged['line'])
                : 'Faltan líneas o archivos del diario fiscal. Ejecuta tpv:fiscal:journal --verify-all.';
        }
        if ($damaged['file'] !== null && $damaged['line'] !== null) {
            return sprintf('El diario fiscal tiene una línea dañada en %s (línea %d). Ejecuta tpv:fiscal:journal --verify-all.', $damaged['file'], $damaged['line']);
        }
        if ($damaged['file'] !== null) {
            return sprintf('El diario fiscal está dañado (%s). Ejecuta tpv:fiscal:journal --verify-all.', $damaged['file']);
        }

        return 'El diario fiscal tiene una línea dañada. Ejecuta tpv:fiscal:journal --verify-all.';
    }

    /** @param array<string, mixed> $data */
    private function writeState(array $data, LoggerInterface $log): void
    {
        foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
            if (!$location->isWritable()) {
                continue;
            }
            try {
                $location->writeState($data);
            } catch (\Throwable $e) {
                $log->warning('Fiscal journal state not written in {directory}: {message}', ['directory' => $location->directory(), 'message' => $e->getMessage()]);
            }
        }
    }

    /**
     * @param array<string, mixed>|null $line
     *
     * @return array{number: string, invoice_type: string, generated_at: string}|null
     */
    private static function document(?array $line): ?array
    {
        $record = \is_array($line['fiscal_record'] ?? null) ? $line['fiscal_record'] : null;
        if ($record === null) {
            return null;
        }

        return [
            'number' => (string) ($record['number'] ?? ''),
            'invoice_type' => (string) ($record['invoice_type'] ?? ''),
            'generated_at' => (string) ($record['generated_at'] ?? ''),
        ];
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
