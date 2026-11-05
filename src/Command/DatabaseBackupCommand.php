<?php

declare(strict_types=1);

namespace VenPosTPV\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use VenPosTPV\Service\Backup\BackupResult;
use VenPosTPV\Service\Backup\DatabaseBackup;
use VenPosTPV\Service\Mail\BackupAlertMailer;

/**
 * The daily backup task: a gzip-compressed SQL dump of the TPV database in the
 * backups/ folder of the project, keeping the newest 7 (--keep). In Plesk it runs as
 * «Ejecutar un script PHP» once a day (docs/despliegue-plesk.md, Tarea 5):
 *
 *     php bin/console tpv:backup:database --env=prod
 *
 * The database is checked first (CHECK TABLE and the fiscal chain). A healthy run
 * prints the checks line and the backup line and exits 0. Problems found by the
 * checks are printed, the backup is still written (…-REVISAR.sql.gz, older copies
 * kept) and the exit code is 1; a failed backup prints its error and exits 1. So
 * Plesk «Notificar: Solo errores» mails both.
 *
 * Both failures also send one email to the address of Ajustes → Email «Avisar de
 * copias de seguridad fallidas a:», from the shop's mail account. An alert that
 * cannot be sent is one more error line; it never changes the backup or its result.
 */
#[AsCommand(name: 'tpv:backup:database', description: 'Back up the TPV database to backups/')]
final class DatabaseBackupCommand extends Command
{
    public function __construct(
        private readonly DatabaseBackup $backup,
        private readonly ?BackupAlertMailer $alerts = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('keep', null, InputOption::VALUE_REQUIRED, 'Number of backups to keep', (string) DatabaseBackup::DEFAULT_KEEP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keep = filter_var($input->getOption('keep'), FILTER_VALIDATE_INT);
        if ($keep === false || $keep < 1) {
            $output->writeln('<error>--keep must be a positive integer</error>');

            return Command::FAILURE;
        }

        if (\function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $startedAt = new \DateTimeImmutable();
        try {
            $result = $this->backup->run($keep);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Backup failed: %s</error>', $e->getMessage()));
            $this->alert($output, 'VenPosTPV: copia de seguridad fallida', $startedAt, null, [$e->getMessage()]);

            return Command::FAILURE;
        }

        $health = $result->health;
        if ($health->ok()) {
            $output->writeln(sprintf(
                'Database checks passed: %d tables, %s',
                $health->tables,
                $health->fiscalRecords === null ? 'no fiscal records table' : sprintf('fiscal chain OK (%d records)', $health->fiscalRecords)
            ));
        } else {
            $output->writeln('<error>Database checks found problems (old backups were not deleted):</error>');
            foreach ($health->problems as $problem) {
                $output->writeln('<error>- ' . $problem . '</error>');
            }
        }
        $output->writeln(sprintf('Backup %s: %s, %d tables', $result->fileName(), self::size($result->bytes), $result->tables));
        if ($result->retentionError !== null) {
            $output->writeln(sprintf('<error>Old backups were not removed: %s</error>', $result->retentionError));
        }
        if (!$health->ok()) {
            $this->alert($output, 'VenPosTPV: revisar copia de seguridad', $startedAt, $result, $health->problems);
        }

        return $health->ok() ? Command::SUCCESS : Command::FAILURE;
    }

    /** @param list<string> $problems */
    private function alert(OutputInterface $output, string $subject, \DateTimeImmutable $at, ?BackupResult $result, array $problems): void
    {
        if ($this->alerts === null) {
            return;
        }

        $text = 'Fecha: ' . $at->format('Y-m-d H:i:s') . "\n";
        if ($result === null) {
            $text .= "Archivo: no se ha escrito ninguna copia.\n\nError:\n";
        } else {
            $text .= sprintf("Archivo: %s (%s, %d tablas)\n", $result->fileName(), self::size($result->bytes), $result->tables)
                . "La copia se ha guardado, pero la comprobación de la base de datos ha encontrado problemas. "
                . "No se han borrado las copias anteriores.\n\nProblemas:\n";
        }
        foreach ($problems as $problem) {
            $text .= '- ' . $problem . "\n";
        }

        try {
            $this->alerts->send($subject, $text);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Backup alert email not sent: %s</error>', $e->getMessage()));
        }
    }

    private static function size(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return sprintf('%.1f MB', $bytes / (1024 * 1024));
        }

        return sprintf('%.1f KB', $bytes / 1024);
    }
}
