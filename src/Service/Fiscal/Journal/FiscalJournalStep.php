<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use VenPosTPV\Service\PrestaShop\PsJournalClient;
use VenPosTPV\Service\Provisioning\ProvisioningException;
use VenPosTPV\Service\Provisioning\ProvisioningStep;

/**
 * The fiscal journal of an install (fiscal-journal S12, D11; docs/provisioning.md,
 * docs/diario-fiscal.md). Runs right after the migrations, on the first install and
 * on every upgrade:
 *
 * - creates `backups/fiscal-journal/` and `var/fiscal-journal/` (0700, files 0600,
 *   `.htaccess` «Require all denied», as the database backups), and tightens them
 *   when they were left too open;
 * - first time (no journal, no app_setting `fiscal_journal.id`): refuses while the
 *   shop holds, under any journal, a document this database lacks (a restore of a
 *   database from before the journal, or a reinstall against a shop that has the
 *   previous install's journal: tpv:fiscal:journal:reset --accept-shop-ahead starts
 *   over on purpose); otherwise starts the journal
 *   (FiscalJournalStarter) and journals the documents issued before it, marked
 *   `backfill`, at most BACKFILL_LINES_PER_RUN in this run so an upgrade of a live
 *   install is never held for long; the every-minute job writes the rest, still
 *   marked `backfill` (journal.id `backfill_until`), never renumbered;
 * - a journal without the setting (a database restored from before this version):
 *   stores the setting and lets FiscalJournalGuard decide;
 * - never creates a journal over a lost one (the setting naming a journal no folder
 *   holds, journal files without their `journal.id`, a database naming another
 *   journal): it fails with the reason; only tpv:fiscal:journal:reset does that (A6).
 *
 * isDone(): both folders exist with those permissions and the primary's journal.id
 * is the journal the database names.
 */
#[AsTaggedItem(priority: 500)]
final class FiscalJournalStep implements ProvisioningStep
{
    /** About 10 ms each: a few seconds in the installer or the upgrade at most. */
    public const BACKFILL_LINES_PER_RUN = 500;
    private const LOCK_WAIT_SECONDS = 5.0;

    public function __construct(
        private readonly FiscalJournalLocations $locations,
        private readonly FiscalJournalIdentity $identity,
        private readonly FiscalJournalWriter $writer,
        private readonly FiscalJournalStarter $starter,
        private readonly int $backfillLinesPerRun = self::BACKFILL_LINES_PER_RUN,
        private readonly ?FiscalJournalShopSync $shop = null,
        private readonly ?FiscalJournalGuard $guard = null,
    ) {
    }

    public function id(): string
    {
        return 'fiscal-journal';
    }

    public function description(): string
    {
        return 'Diario fiscal preparado';
    }

    public function isDone(): bool
    {
        foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
            if (!self::prepared($location)) {
                return false;
            }
        }
        $named = $this->identity->journalId();
        $id = $this->locations->primary()->journalId();

        return $named !== null && $id !== null && strtolower($id) === $named;
    }

    public function run(): void
    {
        foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
            if (!$location->prepare()) {
                throw new ProvisioningException(sprintf(
                    'No se puede crear o escribir la carpeta %s del diario fiscal. Comprueba que la carpeta del TPV se puede escribir con el usuario que ejecuta PHP.',
                    self::shortName($location),
                ));
            }
        }

        $primary = $this->locations->primary();
        $secondary = $this->locations->secondary();
        $named = $this->identity->journalId();
        $id = $primary->journalId();

        if ($id !== null) {
            $id = strtolower($id);
            if ($named === null) {
                // A database restored from before this version: the guard compares it with the journal.
                $this->identity->store($id);

                return;
            }
            if ($named !== $id) {
                throw new ProvisioningException(sprintf(
                    'La base de datos pertenece al diario fiscal %s, pero backups/fiscal-journal guarda el diario %s. No se cambia nada: revisa si la base de datos viene de otra instalación (docs/diario-fiscal.md).',
                    $named,
                    $id,
                ));
            }

            return;
        }

        if ($secondary->journalId() !== null || $primary->journalFiles() !== [] || $primary->readHead() !== null) {
            throw new ProvisioningException(self::lost('backups/fiscal-journal tiene documentos anotados pero ha perdido su archivo journal.id'));
        }
        if ($named !== null) {
            throw new ProvisioningException(self::lost(sprintf('la base de datos pertenece al diario fiscal %s, que no está en backups/fiscal-journal ni en var/fiscal-journal', $named)));
        }

        $this->refuseWhileTheShopIsAhead();
        $this->starter->start();
        // A bounded first part of the backfill; the every-minute job writes the rest. The
        // journal's backfill_until marks them, not this flush: a sale issued meanwhile is not.
        $this->writer->flush($this->backfillLinesPerRun, self::LOCK_WAIT_SECONDS);
    }

    /**
     * Before a first journal: the shop is asked for every journal it holds. When it
     * holds, under any journal but the retired ones, a document this database lacks,
     * no journal is started (the next one would repeat its number): the answer is
     * stored so FiscalJournalGuard blocks the till, and the step fails naming the
     * restore guide and the deliberate fresh start. A fresh install with an empty
     * database against a shop that holds an earlier install's journal cannot be told
     * from a restore, so it is refused too, by design. A shop that cannot be asked
     * (not configured, down, an older module, an error) never stops the install.
     *
     * @throws ProvisioningException
     */
    private function refuseWhileTheShopIsAhead(): void
    {
        if ($this->shop === null || $this->guard === null) {
            return;
        }
        $answer = $this->shop->askHeads();
        if ($answer['status'] !== PsJournalClient::OK || !\is_array($answer['journals'] ?? null) || $answer['journals'] === []) {
            return;
        }
        $retired = $this->identity->retired();
        $heads = array_filter($answer['journals'], static fn ($id): bool => !\in_array(strtolower((string) $id), $retired, true), \ARRAY_FILTER_USE_KEY);
        $missing = $this->guard->missingFromShop($heads);
        if ($missing === null) {
            return;
        }
        $this->shop->remember($answer, new \DateTimeImmutable(), true);

        throw new ProvisioningException(sprintf(
            'La tienda tiene documentos fiscales que esta base de datos no tiene (falta %s): no se empieza un diario fiscal, porque se repetirían números, y la caja queda bloqueada. '
            . 'Si se ha restaurado una copia de la base de datos, restaura una más reciente (docs/diario-fiscal.md, «Restaurar una copia de seguridad»). '
            . 'Si es una instalación nueva con la base de datos vacía contra una tienda que ya tiene el diario de una instalación anterior, y se quiere empezar de cero a propósito: '
            . 'php bin/console tpv:fiscal:journal:reset --confirm "<motivo>" --accept-shop-ahead --env=prod (sus números se pueden volver a emitir).',
            $missing['number'],
        ));
    }

    private static function lost(string $what): string
    {
        return 'El diario fiscal se ha perdido: ' . $what . '. No se crea un diario nuevo encima. '
            . 'Si la tienda tiene la copia, la caja vuelve a vender en cuanto la tienda confirma que la base de datos no va por detrás, '
            . 'pero el diario no se anota hasta que se empiece uno nuevo con '
            . 'php bin/console tpv:fiscal:journal:reset --confirm "<motivo>" --env=prod (docs/diario-fiscal.md).';
    }

    private static function prepared(JournalLocation $location): bool
    {
        $directory = $location->directory();
        clearstatcache(true, $directory);
        if (!is_dir($directory)) {
            return false;
        }
        $mode = @fileperms($directory);

        return $mode !== false && ($mode & 0077) === 0 && is_file($directory . '/.htaccess');
    }

    private static function shortName(JournalLocation $location): string
    {
        $parts = explode('/', rtrim(str_replace('\\', '/', $location->directory()), '/'));

        return implode('/', \array_slice($parts, -2));
    }
}
