<?php

declare(strict_types=1);

namespace VenPosTPV\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use VenPosTPV\Service\Cron\CronJob;
use VenPosTPV\Service\Cron\CronJobOutcome;
use VenPosTPV\Service\Cron\CronRunner;

/**
 * The single scheduled task: the hosting panel runs it every minute and it
 * launches the existing commands that are due (uploads and stock every run, the
 * full catalog, customers and the backup once a night).
 */
#[AsCommand(name: 'tpv:cron', description: 'Run the scheduled jobs that are due (schedule it every minute)')]
final class CronCommand extends Command
{
    public function __construct(private readonly CronRunner $runner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (\function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $report = $this->runner->run(fn (CronJob $job): CronJobOutcome => $this->launch($job, $output));

        if ($output->isVerbose()) {
            foreach ($report->skipped() as $id) {
                $output->writeln("Skipped {$id}: still running from an earlier run (or another nightly job is).");
            }
        }

        // Panels set to mail only errors send what goes to STDERR: one line per failed job.
        if ($report->failed()) {
            $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $jobs = [];
            foreach (CronRunner::jobs() as $job) {
                $jobs[$job->id] = $job;
            }
            foreach ($report->failures() as $id) {
                $commandLine = isset($jobs[$id]) ? $jobs[$id]->commandLine() : $id;
                $errorOutput->writeln("tpv:cron: {$id} ({$commandLine}) failed: " . ($report->errors()[$id] ?? ''), OutputInterface::OUTPUT_RAW);
            }
        }

        return $report->failed() ? Command::FAILURE : Command::SUCCESS;
    }

    private function launch(CronJob $job, OutputInterface $output): CronJobOutcome
    {
        $jobInput = new ArrayInput($job->arguments);
        $jobInput->setInteractive(false);
        $tee = new class($output) extends Output {
            public string $buffer = '';

            public function __construct(private readonly OutputInterface $inner)
            {
                parent::__construct($inner->getVerbosity(), false);
            }

            protected function doWrite(string $message, bool $newline): void
            {
                $this->buffer .= $message . ($newline ? \PHP_EOL : '');
                $this->inner->write($message, $newline, OutputInterface::OUTPUT_RAW);
            }
        };

        try {
            $command = $this->getApplication()?->find($job->command)
                ?? throw new \LogicException("The command {$job->command} is not available.");
            $exitCode = $command->run($jobInput, $tee);
        } catch (\Throwable $e) {
            // The runner records it as the job's error; the panel's log shows it too.
            $output->writeln("<error>{$job->command}: {$e->getMessage()}</error>");

            throw $e;
        }

        return new CronJobOutcome($exitCode, $tee->buffer);
    }
}
