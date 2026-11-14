<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use VenPosTPV\Service\PrestaShop\PsJournalClient;

/**
 * Starts a new fiscal journal from the current database (fiscal-journal A6;
 * tpv:fiscal:journal:reset; decisions in the feature Log), for a journal that is
 * damaged, lost (`journal_missing`) or that the administrator wants to start again
 * while it is up to date. Never run by itself.
 *
 * Before anything else the shop is asked for every journal it holds, whatever
 * journal id this database knows (FiscalJournalShopSync::askHeads), the retired ones
 * aside. A shop that cannot be asked (down, a module that cannot answer for every
 * journal, an error) refuses the reset, unless the administrator says so
 * (`--shop-unavailable`): without the shop, a database restored from an older copy
 * would issue numbers already issued again. The path taken is recorded in audit_log
 * (`shop`).
 *
 * The shop holding, under any journal, a document the database lacks refuses the
 * reset, unless the administrator starts over on purpose (`--accept-shop-ahead`,
 * never after restoring a backup): then every journal the shop holds is retired
 * (FiscalJournalIdentity::retire, in the same transaction as the new journal) and
 * audit_log records them (`accept_shop_ahead`). A normal reset retires nothing: the
 * archived journal's documents are all in the database, so they never block.
 *
 * Refused, with nothing written (not even a torn tail moved to quarantine/), when
 * any evidence still readable names a document the database lacks: the shop head
 * just asked for and the stored one (`behind_shop`), the journal's guard
 * (`behind_journal`, while the journal is healthy), and, damaged or not, every
 * line that still passes its own checks, `head.json` and the confirmed intents of
 * both folders. Only when none of it does is a damaged journal a reason to allow
 * a reset the guard would otherwise refuse.
 *
 * Nothing is ever deleted: every file of each journal folder (lines, head, identity,
 * intents, quarantine, state, shop head) is moved to
 * `archive/<journal_id>-<UTC timestamp>/` inside that folder, byte for byte; only
 * `.htaccess`, `journal.lock` and `archive/` stay. The archive, the new journal.id,
 * the app_setting `fiscal_journal.id` and the audit_log record are written together
 * under the chain lock (FiscalJournalStarter), with the reset's own lock, the replay
 * lock and both `journal.lock` held. Then the documents of the database are
 * journaled again, at most $backfillLinesPerRun in this run (the every-minute job
 * writes the rest); the journal's `backfill_until` marks them `backfill`, never a
 * document issued after the new journal started.
 */
class FiscalJournalReset
{
    public const AUDIT_ACTION = 'fiscal_journal_reset';
    /** audit_log `shop.path`: the shop answered for every journal and holds nothing the database lacks. */
    public const SHOP_CONFIRMED = 'confirmed';
    /** audit_log `shop.path`: the shop could not be asked and the administrator passed --shop-unavailable. */
    public const SHOP_UNAVAILABLE = 'shop_unavailable';
    private const LOCK_WAIT_SECONDS = 10.0;
    private const BACKFILL_LOCK_WAIT_SECONDS = 5.0;
    private const STAYS = ['.', '..', '.htaccess', 'journal.lock', 'archive'];
    private const NUMBERS_PER_QUERY = 500;

    private readonly FiscalJournalGuard $guard;

    public function __construct(
        private readonly Connection $connection,
        private readonly FiscalJournalLocations $locations,
        private readonly FiscalJournalWriter $writer,
        private readonly FiscalJournalStarter $starter,
        private readonly FiscalJournalIdentity $identity,
        #[Autowire('%kernel.project_dir%/var/fiscal-replay.lock')] private readonly string $replayLockPath,
        ?FiscalJournalGuard $guard = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?FiscalJournalShopSync $shop = null,
        private readonly int $backfillLinesPerRun = FiscalJournalStep::BACKFILL_LINES_PER_RUN,
    ) {
        $this->guard = $guard ?? new FiscalJournalGuard($connection, $locations, $logger, $identity);
    }

    /**
     * @param bool $shopUnavailable go on when the shop cannot be asked (--shop-unavailable)
     * @param bool $acceptShopAhead go on although the shop holds documents the database lacks, retiring the
     *                              journals the shop holds (--accept-shop-ahead: a deliberate fresh start only)
     */
    public function run(string $reason, ?\DateTimeImmutable $now = null, bool $shopUnavailable = false, bool $acceptShopAhead = false): FiscalJournalResetResult
    {
        $reason = trim($reason);
        if ($reason === '') {
            return new FiscalJournalResetResult(FiscalJournalResetResult::INVALID, 'Falta el motivo: tpv:fiscal:journal:reset --confirm "<motivo>". No se ha cambiado nada.');
        }
        $now = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));

        $folder = \dirname($this->replayLockPath);
        if (!is_dir($folder)) {
            @mkdir($folder, 0775, true);
        }
        $resetLockPath = $folder . '/fiscal-journal-reset.lock';
        $resetLock = @fopen($resetLockPath, 'c');
        if ($resetLock === false) {
            return self::error('no se puede abrir ' . $resetLockPath);
        }
        if (!flock($resetLock, \LOCK_EX | \LOCK_NB)) {
            fclose($resetLock);

            return new FiscalJournalResetResult(FiscalJournalResetResult::ERROR, 'Ya hay otro tpv:fiscal:journal:reset en marcha; espera a que termine. No se ha cambiado nada.');
        }
        $replayLock = @fopen($this->replayLockPath, 'c');
        if ($replayLock === false) {
            JournalLocation::unlock($resetLock);

            return self::error('no se puede abrir ' . $this->replayLockPath);
        }
        if (!flock($replayLock, \LOCK_EX | \LOCK_NB)) {
            fclose($replayLock);
            JournalLocation::unlock($resetLock);

            return new FiscalJournalResetResult(FiscalJournalResetResult::ERROR, 'Hay una recuperación de documentos en marcha (tpv:fiscal:replay o el botón «Recuperar documentos» de Ajustes); espera a que termine. No se ha cambiado nada.');
        }
        try {
            $shop = $this->askShop($shopUnavailable);
            if ($shop instanceof FiscalJournalResetResult) {
                return $shop;
            }
            // Decided before anything is written (folders, locks, a torn tail): a refusal changes nothing.
            $refusal = $this->assess($shop['heads'], $acceptShopAhead)['refusal'];
            if ($refusal !== null) {
                return $refusal;
            }
            $started = $this->resetLocked($reason, $now, $shop, $acceptShopAhead);
            if (!\is_array($started)) {
                return $started;
            }
            // journal.lock is free again: the backfill takes it like any flush, bounded as the step's.
            $flush = $this->writer->flush($this->backfillLinesPerRun, self::BACKFILL_LOCK_WAIT_SECONDS);

            return $this->done($started, $flush);
        } catch (\Throwable $e) {
            $this->logger?->error('Fiscal journal reset failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return self::error($e->getMessage());
        } finally {
            JournalLocation::unlock($replayLock);
            JournalLocation::unlock($resetLock);
        }
    }

    /**
     * Asks the shop for every journal it holds (nothing is stored). The retired
     * journals are left out of the evidence.
     *
     * @return array{path: string, asked: list<string>, heads: list<array<string, mixed>>, error: ?string}|FiscalJournalResetResult the refusal when it cannot be asked and the administrator did not say to go on
     */
    private function askShop(bool $shopUnavailable): array|FiscalJournalResetResult
    {
        $journals = [];
        $error = null;
        if ($this->shop === null) {
            $error = 'la conexión con la tienda no está configurada';
        } else {
            $answer = $this->shop->askHeads();
            if ($answer['status'] === PsJournalClient::OK && \is_array($answer['journals'] ?? null)) {
                $journals = $answer['journals'];
            } else {
                $error = ($answer['status'] ?? null) === PsJournalClient::UNAVAILABLE
                    ? 'el módulo TPV Connect de la tienda no puede contestar por todos sus diarios (hace falta la versión ' . PsJournalClient::MIN_MODULE_VERSION . ' o posterior)'
                    : sprintf('la tienda no ha contestado (%s)', (string) ($answer['error'] ?? $answer['status'] ?? '?'));
            }
        }
        $retired = $this->identity->retired();
        $heads = [];
        foreach ($journals as $id => $head) {
            if (!\in_array(strtolower((string) $id), $retired, true)) {
                $heads[] = $head;
            }
        }
        $result = ['path' => self::SHOP_CONFIRMED, 'asked' => array_map(static fn ($id): string => strtolower((string) $id), array_keys($journals)), 'heads' => $heads, 'error' => $error];
        if ($error === null) {
            return $result;
        }
        if (!$shopUnavailable) {
            return new FiscalJournalResetResult(FiscalJournalResetResult::REFUSED, sprintf(
                'No se ha podido preguntar a la tienda por su copia del diario fiscal: %s. Sin ella no se sabe si la base de datos va por detrás de los documentos ya emitidos. Vuelve a intentarlo cuando la tienda conteste o, si la tienda no puede tener la copia, repite la orden con --shop-unavailable (si la base de datos se restauró de una copia anterior, se pueden repetir números). No se ha cambiado nada.',
                rtrim($error, '. '),
            ));
        }

        return ['path' => self::SHOP_UNAVAILABLE] + $result;
    }

    /**
     * Whether the reset may go on, read only (nothing is written, not even a torn
     * tail repaired): the refusal, and what the audit record keeps.
     *
     * @param list<array<string, mixed>> $shopHeads the heads the shop just answered
     *
     * @param bool $acceptShopAhead --accept-shop-ahead: the shop holding documents the database lacks does not refuse
     *
     * @return array{refusal: ?FiscalJournalResetResult, damaged: ?string, guard: array{state: string, missing: ?array{number: string, invoice_type: string}}, shop_ahead: ?array{number: string, invoice_type: string}}
     */
    private function assess(array $shopHeads, bool $acceptShopAhead = false): array
    {
        $damaged = $this->damage();
        $guard = $this->guard->check();
        $refuse = static fn (string $message): array => ['refusal' => new FiscalJournalResetResult(FiscalJournalResetResult::REFUSED, $message), 'damaged' => $damaged, 'guard' => $guard, 'shop_ahead' => null];

        $shopMissing = $guard['state'] === FiscalJournalGuard::BEHIND_SHOP ? $guard['missing'] : null;
        $shopMissing ??= $this->guard->missingFromShop($shopHeads);
        if ($shopMissing !== null && !$acceptShopAhead) {
            return $refuse(sprintf(
                'La copia en la tienda tiene documentos que la base de datos no tiene (falta %s): con un diario nuevo se repetirían números. Si el diario fiscal los tiene, recupéralos con tpv:fiscal:replay --apply; si no, restaura una copia más reciente de la base de datos (docs/diario-fiscal.md). Solo para empezar de cero a propósito, nunca tras restaurar una copia: --accept-shop-ahead. No se ha cambiado nada.',
                $shopMissing['number'] ?? '?',
            ));
        }
        if ($guard['state'] === FiscalJournalGuard::BEHIND_JOURNAL && $damaged === null) {
            return $refuse(sprintf(
                'La base de datos está por detrás del diario fiscal (falta %s): recupera primero los documentos con tpv:fiscal:replay --apply o con el botón «Recuperar documentos» de Ajustes. No se ha cambiado nada.',
                $guard['missing']['number'] ?? '?',
            ));
        }
        $missing = $this->missingEvidence();
        if ($missing !== null) {
            return $refuse(sprintf(
                $damaged !== null
                    ? 'El diario fiscal está dañado, pero lo que aún se puede leer de él nombra documentos que la base de datos no tiene (falta %s): con un diario nuevo se repetirían números. Restaura una copia más reciente de la base de datos (docs/diario-fiscal.md). No se ha cambiado nada.'
                    : 'La base de datos está por detrás del diario fiscal (falta %s): recupera primero los documentos con tpv:fiscal:replay --apply o con el botón «Recuperar documentos» de Ajustes. No se ha cambiado nada.',
                $missing['number'],
            ));
        }

        return ['refusal' => null, 'damaged' => $damaged, 'guard' => $guard, 'shop_ahead' => $shopMissing];
    }

    /**
     * The first document named by what is still readable in either folder (every
     * line that passes its own checks and whose fiscal hash recomputes, damaged
     * journal or not; `head.json`; the confirmed intents) that the database lacks.
     *
     * @return array{number: string, invoice_type: string}|null
     */
    private function missingEvidence(): ?array
    {
        /** @var array<string, array{number: string, invoice_type: string, hash: string}> $documents by hash, oldest first */
        $documents = [];
        $add = static function (mixed $record) use (&$documents): void {
            $number = \is_array($record) ? ($record['number'] ?? null) : null;
            $hash = \is_array($record) ? ($record['hash'] ?? null) : null;
            if (\is_string($number) && $number !== '' && \is_string($hash) && $hash !== '' && !isset($documents[$hash])) {
                $documents[$hash] = ['number' => $number, 'invoice_type' => \is_string($record['invoice_type'] ?? null) ? $record['invoice_type'] : '', 'hash' => $hash];
            }
        };
        foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
            foreach ($location->lines() as $line) {
                if (JournalLocation::selfValid($line) && JournalVerifier::fiscalHashMatches($line['fiscal_record'] ?? null)) {
                    $add($line['fiscal_record']);
                }
            }
            $add($location->readHead());
            foreach ($location->intents() as $intent) {
                if (($intent['state'] ?? null) === FiscalJournalIntents::STATE_CONFIRMED) {
                    $add($intent['fiscal_record'] ?? null);
                }
            }
        }

        $numbers = array_values(array_unique(array_column($documents, 'number')));
        $held = [];
        foreach (array_chunk($numbers, self::NUMBERS_PER_QUERY) as $chunk) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT number, hash, invoice_type FROM fiscal_record WHERE number IN (?)',
                [$chunk],
                [ArrayParameterType::STRING],
            );
            foreach ($rows as $row) {
                $held[(string) $row['number'] . "\0" . (string) $row['hash']][] = (string) $row['invoice_type'];
            }
        }
        foreach ($documents as $document) {
            $types = $held[$document['number'] . "\0" . $document['hash']] ?? null;
            if ($types === null || ($document['invoice_type'] !== '' && !\in_array($document['invoice_type'], $types, true))) {
                return ['number' => $document['number'], 'invoice_type' => $document['invoice_type']];
            }
        }

        return null;
    }

    /**
     * @param array{path: string, asked: list<string>, heads: list<array<string, mixed>>, error: ?string} $shop
     *
     * @return array{journal_id: string, backfill_until: int, archived: list<string>}|FiscalJournalResetResult
     */
    private function resetLocked(string $reason, \DateTimeImmutable $now, array $shop, bool $acceptShopAhead): array|FiscalJournalResetResult
    {
        $locations = [$this->locations->primary(), $this->locations->secondary()];
        $locks = [];
        try {
            foreach ($locations as $location) {
                if (!$location->prepare()) {
                    return self::error(sprintf('la carpeta %s no se puede escribir', self::shortName($location->directory())));
                }
                $lock = $location->lock(microtime(true) + self::LOCK_WAIT_SECONDS);
                if ($lock === null) {
                    return new FiscalJournalResetResult(FiscalJournalResetResult::ERROR, 'El diario fiscal está ocupado; vuelve a intentarlo en un minuto. No se ha cambiado nada.');
                }
                $locks[] = $lock;
            }

            // Again under the locks: a flush may have written a line since.
            $assessment = $this->assess($shop['heads'], $acceptShopAhead);
            if ($assessment['refusal'] !== null) {
                return $assessment['refusal'];
            }
            $damaged = $assessment['damaged'];
            $guard = $assessment['guard'];
            // --accept-shop-ahead with the shop ahead: every journal it holds now is retired, so
            // what it holds under them is ignored from now on. Otherwise nothing is retired.
            $retire = $acceptShopAhead && $assessment['shop_ahead'] !== null ? $shop['asked'] : [];

            $previous = $this->previousJournalId();
            $archived = [];
            $stamp = $now->format('Ymd\THis\Z');
            $started = $this->starter->start(
                function () use ($locations, $stamp, &$archived): void {
                    foreach ($locations as $location) {
                        $folder = $this->archive($location, $stamp);
                        if ($folder !== null) {
                            $archived[] = self::shortName($location->directory()) . '/archive/' . basename($folder);
                        }
                    }
                },
                function (string $journalId, int $until) use ($reason, $previous, $guard, $damaged, $shop, $retire, $assessment, &$archived, $now): void {
                    if ($retire !== []) {
                        $this->identity->retire($retire);
                    }
                    $this->connection->insert('audit_log', [
                        'user_id' => null,
                        'terminal_id' => null,
                        'action' => self::AUDIT_ACTION,
                        'target' => $journalId,
                        'payload' => json_encode([
                            'reason' => $reason,
                            'previous_journal_id' => $previous,
                            'guard' => $guard['state'],
                            'damaged' => $damaged,
                            'shop' => ['path' => $shop['path'], 'asked' => $shop['asked'], 'error' => $shop['error']],
                            'accept_shop_ahead' => $retire !== [] ? ['missing' => $assessment['shop_ahead']['number'] ?? null, 'retired_journals' => $retire] : null,
                            'archived' => $archived,
                            'backfill_until' => $until,
                        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
                        'ip' => null,
                        'created_at' => $now->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'),
                    ]);
                },
            );

            return $started + ['archived' => $archived];
        } finally {
            foreach (array_reverse($locks) as $lock) {
                JournalLocation::unlock($lock);
            }
        }
    }

    /**
     * Why the journal cannot be trusted (D4), null when it is healthy: lost identity,
     * a damaged line, a torn last line, removed lines, head.json disagreeing. Read
     * only: a torn last line is reported, never cut here (the archive keeps it).
     */
    private function damage(): ?string
    {
        $primary = $this->locations->primary();
        $secondary = $this->locations->secondary();
        if ($primary->journalId() === null) {
            return $primary->journalFiles() !== [] || $primary->readHead() !== null || $secondary->journalId() !== null
                ? 'journal.id perdido'
                : ($this->identity->journalId() !== null ? 'diario perdido' : null);
        }
        foreach ([[$primary, true], [$secondary, false]] as [$location, $complete]) {
            if ($location->journalFiles() !== []) {
                $tail = $location->tailFinding();
                if ($tail === JournalLocation::TAIL_TORN) {
                    return self::shortName($location->directory()) . ': última línea a medio escribir';
                }
                if (\in_array($tail, [JournalLocation::TAIL_DAMAGED, JournalLocation::TAIL_BAD_LAST, JournalLocation::TAIL_BROKEN_LAST], true)) {
                    return self::shortName($location->directory()) . ': ' . $tail;
                }
            }
            $found = JournalVerifier::verify($location, null, true, $complete)['damaged'];
            if ($found !== null) {
                return sprintf('%s: %s línea %s (%s)', self::shortName($location->directory()), basename((string) ($found['file'] ?? '?')), $found['line'] ?? '-', $found['reason']);
            }
        }

        return null;
    }

    private function previousJournalId(): ?string
    {
        return $this->identity->journalId() ?? $this->locations->primary()->journalId() ?? $this->locations->secondary()->journalId();
    }

    /**
     * Moves every file of the folder but the ones that stay to
     * archive/<journal_id>-<stamp>/ (a free name when it exists).
     *
     * @return string|null the archive folder, null when there was nothing to move
     */
    private function archive(JournalLocation $location, string $stamp): ?string
    {
        $directory = $location->directory();
        // Empty sub-folders (intents/, quarantine/ as prepare() makes them) stay where they are.
        $entries = array_values(array_filter(
            array_diff(scandir($directory) ?: [], self::STAYS),
            static fn (string $entry): bool => self::holdsFiles($directory . '/' . $entry),
        ));
        if ($entries === []) {
            return null;
        }
        $id = $location->journalId();
        $name = (\is_string($id) && preg_match('/\A[0-9A-Za-z-]{1,64}\z/D', $id) === 1 ? strtolower($id) : 'sin-id') . '-' . $stamp;
        $base = $directory . '/archive';
        if (!is_dir($base) && !@mkdir($base, 0700) && !is_dir($base)) {
            throw new \RuntimeException(sprintf('No se puede crear %s', $base));
        }
        $target = $base . '/' . $name;
        for ($n = 2; file_exists($target); ++$n) {
            $target = $base . '/' . $name . '-' . $n;
        }
        if (!@mkdir($target, 0700)) {
            throw new \RuntimeException(sprintf('No se puede crear %s', $target));
        }
        foreach ($entries as $entry) {
            if (!@rename($directory . '/' . $entry, $target . '/' . $entry)) {
                throw new \RuntimeException(sprintf('No se puede archivar %s en %s (lo ya movido está en esa carpeta; nada se ha borrado)', $entry, $target));
            }
        }

        return $target;
    }

    private static function holdsFiles(string $path): bool
    {
        if (!is_dir($path) || is_link($path)) {
            return true;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            if (self::holdsFiles($path . '/' . $entry)) {
                return true;
            }
        }

        return false;
    }

    /** @param array{journal_id: string, backfill_until: int, archived: list<string>} $started */
    private function done(array $started, FiscalJournalFlushResult $flush): FiscalJournalResetResult
    {
        $message = sprintf('Diario fiscal nuevo %s: %d documentos de la base de datos anotados.', $started['journal_id'], $flush->written);
        if ($flush->status !== FiscalJournalFlushResult::OK) {
            $message .= sprintf(' El resto lo anota la tarea de cada minuto (%s%s).', $flush->status, $flush->reason !== null ? ': ' . $flush->reason : '');
        } elseif ($flush->written >= $this->backfillLinesPerRun) {
            $message .= ' El resto lo anota la tarea de cada minuto.';
        }
        if ($started['archived'] === []) {
            $message .= ' No había archivos que archivar.';
        }

        return new FiscalJournalResetResult(FiscalJournalResetResult::DONE, $message, $started['journal_id'], $flush->written, $started['archived']);
    }

    private static function error(string $why): FiscalJournalResetResult
    {
        return new FiscalJournalResetResult(FiscalJournalResetResult::ERROR, 'No se ha podido empezar un diario nuevo: ' . rtrim($why, '. ') . '.');
    }

    private static function shortName(string $directory): string
    {
        $parts = explode('/', rtrim(str_replace('\\', '/', $directory), '/'));

        return implode('/', \array_slice($parts, -2));
    }
}
