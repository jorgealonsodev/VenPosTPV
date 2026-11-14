<?php

declare(strict_types=1);

namespace VenPosTPV\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalReconciler;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalState;

/**
 * The every-minute job of the fiscal journal (tpv:cron, job «journal»): appends
 * every document missing from the journal, resolves leftover intents, checks the
 * new lines (the whole journal once a day, or now with --verify-all), writes
 * state.json and sends the alert e-mail (fiscal-journal D3.5, D9).
 *
 * Exit codes (also in --help): 0 up to date or documents pending; 1 neither journal
 * folder can be written (sales go on, the documents wait in the database) or a
 * document cannot be journaled; 2 the database is behind the fiscal journal (a
 * restore, FiscalJournalGuard): the till cannot issue documents until they are
 * recovered; 3 the journal is damaged.
 *
 * A lasting incident fails the job (its code and one line on STDERR) only on the
 * run that first detects it and once every 24 hours while it lasts; the runs in
 * between exit 0 and print nothing on STDERR, while state.json and Ajustes keep
 * showing it. tpv:cron prints one STDERR line per failed job and hosting panels mail
 * STDERR on every run: without this rule they would mail every minute.
 */
#[AsCommand(name: 'tpv:fiscal:journal', description: 'Bring the fiscal journal up to date with the database (run by tpv:cron every minute)')]
final class FiscalJournalCommand extends Command
{
    public function __construct(private readonly FiscalJournalReconciler $reconciler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
            Run by tpv:cron every minute: appends to the fiscal journal every document of the
            database that is not in it yet, resolves leftover intents, checks the new lines
            (the whole journal once a day, or now with --verify-all) and writes state.json.

            Exit codes:
              0 up to date or documents pending
              1 neither journal folder can be written (sales go on, the documents wait in the database) or a document cannot be journaled
              2 the database is behind the fiscal journal (a restore): the till cannot issue documents until they are recovered
              3 the journal is damaged

            A lasting incident gives its code only on the run that first detects it and once
            every 24 hours; the runs in between exit 0.
            HELP);
        $this->addOption('verify-all', null, InputOption::VALUE_NONE, 'Check every line of the journal now, not only the lines added since the last run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $state = $this->reconciler->run(null, (bool) $input->getOption('verify-all'));
        $data = $state->data;

        $message = match ($state->status()) {
            FiscalJournalState::NOT_STARTED => 'Fiscal journal not started yet: nothing to do.',
            FiscalJournalState::OK => 'Fiscal journal up to date.',
            FiscalJournalState::PENDING => sprintf('Fiscal journal: %d documents pending.', $data['pending']['count']),
            FiscalJournalState::LAGGING => sprintf('Fiscal journal: %d documents pending for more than 5 minutes (first %s).', $data['pending']['count'], $data['pending']['oldest_number']),
            FiscalJournalState::DAMAGED => sprintf('Fiscal journal damaged: %s line %s (%s).', $data['damaged']['file'] ?? '?', $data['damaged']['line'] ?? '?', $data['damaged']['reason'] ?? '?'),
            FiscalJournalState::BLOCKED => sprintf('Fiscal journal: document %s cannot be journaled: %s', $data['blocked']['number'], $data['blocked']['reason']),
            FiscalJournalState::HEAD_MISSING => 'Fiscal journal: its last document is not in the database; nothing written.',
            default => 'Fiscal journal: ' . $state->status(),
        };
        if ($state->status() === FiscalJournalState::PENDING || $state->status() === FiscalJournalState::LAGGING) {
            if ($data['writable'] === false) {
                $message .= ' Neither backups/fiscal-journal nor var/fiscal-journal can be written.';
            }
        }

        if ($state->exitCode === FiscalJournalState::EXIT_OK) {
            // Silent unless -v: a run that reports nothing new prints nothing for the cron log.
            $output->writeln($message, OutputInterface::VERBOSITY_VERBOSE);

            return FiscalJournalState::EXIT_OK;
        }

        if ($data['writable'] === false && $state->status() !== FiscalJournalState::DAMAGED) {
            $message = 'Fiscal journal: neither backups/fiscal-journal nor var/fiscal-journal can be written; sales go on and the documents wait in the database.';
        }
        // One line, the last one: what tpv:cron stores as the job's error.
        ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln($message);

        return $state->exitCode;
    }
}
