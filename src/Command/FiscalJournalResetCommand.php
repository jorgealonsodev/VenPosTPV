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
 *     php bin/console tpv:fiscal:journal:reset --confirm "<motivo>" --env=prod
 *
 * Exit codes (also in --help): 0 done; 1 error (nothing changed); 2 no --confirm or
 * an empty reason; 3 refused: the database is behind the journal or behind the shop.
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
            nuevo al que no se copió backups/fiscal-journal) y la caja no se desbloquea sola.
            Empieza un diario nuevo y anota en él todos los documentos que tiene la base de
            datos (marcados como «backfill»).

            Nunca borra nada: todos los archivos del diario anterior se mueven, tal cual, a
            archive/<id del diario>-<fecha UTC>/ dentro de backups/fiscal-journal y de
            var/fiscal-journal. Queda registrado en audit_log (acción fiscal_journal_reset)
            con el motivo, el diario anterior y lo archivado.

            No se ejecuta mientras la base de datos está por detrás del diario fiscal (recupera
            antes los documentos con tpv:fiscal:replay --apply) ni mientras la copia en la
            tienda tiene documentos que la base de datos no tiene: un diario nuevo dejaría
            repetir números.

            Códigos de salida:
              0 hecho
              1 error (una recuperación en marcha, una carpeta o la base de datos que no se pueden escribir)
              2 falta --confirm "<motivo>"
              3 rechazado: la base de datos está por detrás del diario fiscal o de la copia en la tienda
            HELP);
        $this->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'El motivo, que queda en el registro de auditoría (obligatorio)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $reason = $input->getOption('confirm');
        if (!\is_string($reason) || trim($reason) === '') {
            $errors->writeln('Falta --confirm "<motivo>": el diario fiscal nuevo solo se empieza con un motivo, que queda en el registro de auditoría. No se ha cambiado nada.');

            return 2;
        }

        $result = $this->reset->run($reason);
        ($result->status === FiscalJournalResetResult::DONE ? $output : $errors)->writeln($result->message);
        foreach ($result->archived as $folder) {
            $output->writeln('Diario anterior archivado en ' . $folder);
        }

        return $result->exitCode();
    }
}
