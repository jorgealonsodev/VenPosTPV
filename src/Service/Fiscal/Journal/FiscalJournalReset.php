<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Starts a new fiscal journal from the current database (fiscal-journal A6;
 * tpv:fiscal:journal:reset; decisions in the feature Log), for a journal that is
 * damaged, lost (`journal_missing`) or that the administrator wants to start again
 * while it is up to date. Never run by itself.
 *
 * Refused, with nothing changed, while the database is behind a healthy journal
 * (`behind_journal`: recover the documents with tpv:fiscal:replay first) or behind
 * the shop copy (`behind_shop`): a new journal would let numbers already issued be
 * issued again.
 *
 * Nothing is ever deleted: every file of each journal folder (lines, head, identity,
 * intents, quarantine, state, shop head) is moved to
 * `archive/<journal_id>-<UTC timestamp>/` inside that folder, byte for byte; only
 * `.htaccess`, `journal.lock` and `archive/` stay. The archive, the new journal.id,
 * the app_setting `fiscal_journal.id` and the audit_log record are written together
 * under the chain lock (FiscalJournalStarter), with the replay lock and both
 * `journal.lock` held. Then every document of the database is journaled again,
 * marked `backfill`.
 */
class FiscalJournalReset
{
    public const AUDIT_ACTION = 'fiscal_journal_reset';
    private const LOCK_WAIT_SECONDS = 10.0;
    private const STAYS = ['.', '..', '.htaccess', 'journal.lock', 'archive'];

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
    ) {
        $this->guard = $guard ?? new FiscalJournalGuard($connection, $locations, $logger, $identity);
    }

    public function run(string $reason, ?\DateTimeImmutable $now = null): FiscalJournalResetResult
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
        $replayLock = @fopen($this->replayLockPath, 'c');
        if ($replayLock === false) {
            return self::error('no se puede abrir ' . $this->replayLockPath);
        }
        if (!flock($replayLock, \LOCK_EX | \LOCK_NB)) {
            fclose($replayLock);

            return new FiscalJournalResetResult(FiscalJournalResetResult::ERROR, 'Hay una recuperación de documentos en marcha; espera a que termine. No se ha cambiado nada.');
        }
        try {
            $started = $this->resetLocked($reason, $now);
            if (!\is_array($started)) {
                return $started;
            }
            // journal.lock is free again: the backfill takes it like any flush.
            $flush = $this->writer->flush(true);

            return $this->done($started, $flush);
        } catch (\Throwable $e) {
            $this->logger?->error('Fiscal journal reset failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return self::error($e->getMessage());
        } finally {
            flock($replayLock, \LOCK_UN);
            fclose($replayLock);
        }
    }

    /**
     * @return array{journal_id: string, backfill_until: int, archived: list<string>}|FiscalJournalResetResult
     */
    private function resetLocked(string $reason, \DateTimeImmutable $now): array|FiscalJournalResetResult
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

            $damaged = $this->damage();
            $guard = $this->guard->check();
            $missing = $guard['missing']['number'] ?? null;
            if ($guard['state'] === FiscalJournalGuard::BEHIND_SHOP) {
                return new FiscalJournalResetResult(FiscalJournalResetResult::REFUSED, sprintf(
                    'La copia en la tienda tiene documentos que la base de datos no tiene (falta %s): con un diario nuevo se repetirían números. Restaura una copia más reciente de la base de datos (docs/diario-fiscal.md). No se ha cambiado nada.',
                    $missing ?? '?',
                ));
            }
            if ($guard['state'] === FiscalJournalGuard::BEHIND_JOURNAL && $damaged === null) {
                return new FiscalJournalResetResult(FiscalJournalResetResult::REFUSED, sprintf(
                    'La base de datos está por detrás del diario fiscal (falta %s): recupera primero los documentos con tpv:fiscal:replay --apply o con el botón «Recuperar documentos» de Ajustes. No se ha cambiado nada.',
                    $missing ?? '?',
                ));
            }

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
                function (string $journalId, int $until) use ($reason, $previous, $guard, $damaged, &$archived, $now): void {
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
     * a damaged line, removed lines, head.json disagreeing. A torn last line is
     * repaired first, as the flush would.
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
                try {
                    $tail = $location->repairTail();
                } catch (JournalWriteException $e) {
                    return self::shortName($location->directory()) . ': ' . $e->getMessage();
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
