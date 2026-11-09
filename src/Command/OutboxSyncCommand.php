<?php

declare(strict_types=1);

namespace VenPosTPV\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use VenPosTPV\Service\PrintAgent\PrintRetention;
use VenPosTPV\Service\Provisioning\ProvisioningRunner;
use VenPosTPV\Service\Sync\OutboxSync;

/**
 * The minute task of PRD S1: uploads to PrestaShop what is due in the outbox, with
 * or without the TPV open. Run it from cron:
 *
 *     * * * * * php /path/to/VenPosTPV/bin/console tpv:sync:outbox
 *
 * Overlapping runs are safe: each row is claimed by one process only.
 *
 * The same minute task cleans the print agent history (docs/prd/agente-impresion.md
 * S4) through PrintRetention; that cleanup never changes this command's output or
 * exit code.
 *
 * Before the uploads it also runs the pending installation steps (database
 * migrations, PrestaShop setup; docs/provisioning.md) throttled: a step that
 * failed is tried again at most every 15 minutes. When nothing is pending this
 * costs one check per step and prints nothing; an attempted step prints one
 * «Provisioning: …» line, and so does a blocked step every 15 minutes, when it
 * would have been retried (it is not: see docs/provisioning.md). Provisioning
 * never changes the exit code nor stops the
 * uploads: its failures are shown in Ajustes → Acerca de.
 */
#[AsCommand(name: 'tpv:sync:outbox', description: 'Upload pending sales to PrestaShop')]
final class OutboxSyncCommand extends Command
{
    public function __construct(
        private readonly OutboxSync $sync,
        private readonly ?PrintRetention $printRetention = null,
        private readonly ?ProvisioningRunner $provisioning = null,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of uploads in this run', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1) {
            $output->writeln('<error>--limit must be a positive integer</error>');

            return Command::INVALID;
        }

        $this->provision($output);

        try {
            $this->printRetention?->run();
        } catch (\Throwable) {
            // Never affects the upload (the print agent tables may not exist yet).
        }

        try {
            $counts = $this->sync->run($limit);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Outbox sync failed: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Outbox sync: %d uploaded, %d to retry, %d incidents, %d recovered',
            $counts['uploaded'],
            $counts['retried'],
            $counts['incidents'],
            $counts['recovered']
        ));

        return Command::SUCCESS;
    }

    private function provision(OutputInterface $output): void
    {
        if ($this->provisioning === null) {
            return;
        }
        try {
            $report = $this->provisioning->run(true);
        } catch (\Throwable $e) {
            $output->writeln('Provisioning failed: ' . $e->getMessage(), OutputInterface::OUTPUT_RAW);

            return;
        }
        foreach ($report->steps as $step) {
            if (!$step->attempted && !$step->reported) {
                continue;
            }
            $output->writeln(
                sprintf('Provisioning: %s %s%s', $step->id, $step->blocked ? 'blocked' : $step->status, $step->reason !== null ? ': ' . $step->reason : ''),
                OutputInterface::OUTPUT_RAW
            );
        }
    }
}
