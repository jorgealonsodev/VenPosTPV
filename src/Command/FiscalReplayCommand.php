<?php

declare(strict_types=1);

namespace VenPosTPV\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplay;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplayResult;

/**
 * The guided replay of the fiscal journal (fiscal-journal S6, S7, D8): after a
 * restore of an older backup, puts back the documents the journal holds and the
 * database lacks. A dry run by default; --apply writes.
 *
 * Exit codes (also in --help): 0 done, or nothing to recover; 1 error (nothing
 * written), or the till is still blocked afterwards; 2 dry run with documents to
 * recover; 3 the restored backup is older than the journal kept; 4 conflict.
 */
#[AsCommand(name: 'tpv:fiscal:replay', description: 'Recupera en la base de datos restaurada los documentos que guarda el diario fiscal')]
final class FiscalReplayCommand extends Command
{
    public function __construct(private readonly FiscalReplay $replay)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
            Tras restaurar una copia de seguridad anterior a los últimos documentos, la caja
            queda bloqueada (la base de datos está por detrás del diario fiscal). Esta orden
            vuelve a poner en la base de datos, en orden y con sus números, huellas e ids
            originales, cada ticket, factura rectificativa y factura que el diario fiscal
            guarda y la base de datos no tiene. Los contadores siguen después del último
            número recuperado y la caja puede volver a emitir documentos.

            Sin opciones (o con --dry-run) solo lista lo que recuperaría y no escribe nada.
            Con --apply lo recupera todo en una sola transacción: si algo falla, la base de
            datos queda como estaba. Volver a ejecutarla no cambia nada.

            No se recuperan: cierres Z, el cierre de las sesiones de caja posteriores a la
            copia (vuelven abiertas: ciérralas de nuevo), el estado de impresión, el stock,
            los clientes creados después de la copia, los tickets aparcados, los movimientos
            de caja manuales, los ajustes, usuarios, terminales y cupones creados a mano. Las
            subidas a la tienda de lo recuperado vuelven a la cola.

            Códigos de salida:
              0 hecho, o nada que recuperar
              1 error (no se ha escrito nada), o la caja sigue bloqueada después
              2 (sin --apply) hay documentos que recuperar
              3 la copia restaurada es anterior al diario conservado: restaura una copia más reciente
              4 conflicto: una fila que hay que recuperar ya existe con otro contenido, o falta un terminal o usuario
            HELP);
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Recupera los documentos (sin esta opción solo se listan)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Solo lista los documentos que se recuperarían (por defecto)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $apply = (bool) $input->getOption('apply');
        if ($apply && (bool) $input->getOption('dry-run')) {
            $errors->writeln('Usa --apply o --dry-run, no las dos.');

            return FiscalReplayResult::exitCodeOf(FiscalReplayResult::ERROR);
        }

        $result = $this->replay->run($apply);
        $documents = $result->documents;

        switch ($result->status) {
            case FiscalReplayResult::PENDING:
                $output->writeln(sprintf('Documentos del diario fiscal que faltan en la base de datos: %d', \count($documents)));
                foreach ($documents as $document) {
                    $output->writeln(sprintf('  %d  %s  %s  %s', $document['seq'], $document['kind'], $document['number'], $document['generated_at']));
                }
                $output->writeln('No se ha escrito nada. Para recuperarlos: tpv:fiscal:replay --apply');
                break;
            case FiscalReplayResult::DONE:
                $output->writeln(sprintf('Recuperados %d documentos (%s … %s). La cadena fiscal es correcta. La caja puede volver a emitir documentos.', \count($documents), $documents[0]['number'] ?? '?', $documents[\count($documents) - 1]['number'] ?? '?'));
                $output->writeln('No se recuperan los cierres Z ni el cierre de las sesiones de caja posteriores a la copia (vuelven abiertas: ciérralas de nuevo), el estado de impresión, el stock, los clientes nuevos, los tickets aparcados ni los movimientos de caja manuales. Las subidas a la tienda vuelven a la cola.');
                break;
            case FiscalReplayResult::NOTHING:
                $output->writeln($result->message ?? 'Nada que recuperar.');
                break;
            default:
                $errors->writeln($result->message ?? 'La recuperación ha fallado.');
        }

        return $result->exitCode();
    }
}
