<?php

declare(strict_types=1);

namespace VenPosTPV\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalReset;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalResetResult;

/**
 * Starts a new fiscal journal from the current database (fiscal-journal A6,
 * FiscalJournalReset; docs/diario-fiscal.md):
 *
 *     php bin/console tpv:fiscal:journal:reset --confirm "<motivo>" [--shop-unavailable] [--accept-shop-ahead] --env=prod
 *
 * It asks the shop for its copy of the journal first; a shop that cannot be asked
 * refuses it unless --shop-unavailable is given.
 *
 * Exit codes (also in --help): 0 done; 1 error (nothing changed); 2 no --confirm or
 * an empty reason; 3 refused: the database is behind the journal or the shop, or
 * the shop cannot be asked (nothing changed).
 */
#[AsCommand(name: 'tpv:fiscal:journal:reset', description: 'Empieza un diario fiscal nuevo desde la base de datos (el anterior se archiva, nunca se borra)')]
final class FiscalJournalResetCommand extends Command
{
    public function __construct(private readonly FiscalJournalReset $reset)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
            Para cuando el diario fiscal está dañado o se ha perdido (por ejemplo, un servidor
            nuevo al que no se copió backups/fiscal-journal). Empieza un diario nuevo y anota
            en él los documentos que tiene la base de datos (marcados como «backfill»; si son
            muchos, la tarea de cada minuto anota el resto).

            Antes de cambiar nada pregunta a la tienda por su copia del diario. Si la tienda
            tiene documentos que la base de datos no tiene, se niega: recupéralos con
            tpv:fiscal:replay --apply o restaura una copia más reciente de la base de datos
            (docs/diario-fiscal.md). Si no se puede preguntar a la tienda (caída, módulo
            TPV Connect anterior a 0.9.0, un error), también se niega, salvo con
            --shop-unavailable.

            Solo si se empieza de cero a propósito (por ejemplo, una reinstalación con la base
            de datos vacía contra una tienda que ya tiene el diario de la instalación anterior),
            --accept-shop-ahead deja empezar aunque la tienda tenga documentos que esta base de
            datos no tiene: sus números se pueden volver a emitir, así que nunca se usa después
            de restaurar una copia de seguridad. Los diarios que la tienda tiene en ese momento
            quedan retirados (no vuelven a bloquear la caja) y se anotan en audit_log.

            Tampoco se ejecuta mientras la base de datos está por detrás del diario fiscal
            (recupera antes los documentos con tpv:fiscal:replay --apply), ni cuando lo que
            aún se puede leer de un diario dañado nombra documentos que la base de datos no
            tiene: un diario nuevo dejaría repetir números. Al negarse no escribe nada.

            Nunca borra nada: todos los archivos del diario anterior se mueven, tal cual, a
            archive/<id del diario>-<fecha UTC>/ dentro de backups/fiscal-journal y de
            var/fiscal-journal. Queda registrado en audit_log (acción fiscal_journal_reset)
            con el motivo, el diario anterior, lo archivado y si la tienda lo confirmó o se
            usó --shop-unavailable.

            Códigos de salida:
              0 hecho
              1 error (otro reinicio o una recuperación en marcha, una carpeta o la base de datos que no se pueden escribir)
              2 falta --confirm "<motivo>"
              3 rechazado: la base de datos está por detrás del diario fiscal o de la copia en la tienda, o no se ha podido preguntar a la tienda
            HELP);
        $this->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'El motivo, que queda en el registro de auditoría (obligatorio)');
        $this->addOption('accept-shop-ahead', null, InputOption::VALUE_NONE, 'Empezar aunque la tienda tenga documentos que esta base de datos no tiene: sus números se pueden volver a emitir. Solo para empezar de cero a propósito (una reinstalación), nunca después de restaurar una copia de seguridad');
        $this->addOption('shop-unavailable', null, InputOption::VALUE_NONE, 'Seguir aunque no se pueda preguntar a la tienda por su copia del diario. Cuidado: si la base de datos se restauró de una copia anterior, se pueden repetir números');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $reason = $input->getOption('confirm');
        if (!\is_string($reason) || trim($reason) === '') {
            $errors->writeln('Falta --confirm "<motivo>": el diario fiscal nuevo solo se empieza con un motivo, que queda en el registro de auditoría. No se ha cambiado nada.');

            return 2;
        }

        $result = $this->reset->run($reason, null, (bool) $input->getOption('shop-unavailable'), (bool) $input->getOption('accept-shop-ahead'));
        ($result->status === FiscalJournalResetResult::DONE ? $output : $errors)->writeln($result->message);
        foreach ($result->archived as $folder) {
            $output->writeln('Diario anterior archivado en ' . $folder);
        }

        return $result->exitCode();
    }
}
