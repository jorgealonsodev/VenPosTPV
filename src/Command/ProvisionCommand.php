<?php

declare(strict_types=1);

namespace VenPosTPV\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use VenPosTPV\Service\Provisioning\ProvisioningReport;
use VenPosTPV\Service\Provisioning\ProvisioningRunner;

/**
 * Runs the pending installation steps now, without the 15-minute wait of the
 * minute task (docs/provisioning.md):
 *
 *     php bin/console tpv:provision --env=prod
 *     php bin/console tpv:provision --status --env=prod   # only report
 *
 * Every step is printed with its state and, when it is not done, its reason. The
 * exit code is 0 when every step is done and 1 when one is still pending. A
 * blocked step (the minute task no longer retries it) is printed as «blocked»;
 * running this command without --status tries it again.
 */
#[AsCommand(name: 'tpv:provision', description: 'Run the pending installation steps (migrations, PrestaShop setup)')]
final class ProvisionCommand extends Command
{
    public function __construct(private readonly ProvisioningRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('status', null, InputOption::VALUE_NONE, 'Only report the state of every step; run nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (\function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $report = $input->getOption('status') ? $this->runner->status() : $this->runner->run(false);
        self::print($report, $output);

        return $report->isComplete() ? Command::SUCCESS : Command::FAILURE;
    }

    private static function print(ProvisioningReport $report, OutputInterface $output): void
    {
        foreach ($report->steps as $step) {
            $output->writeln(sprintf('%-8s %-19s %s', $step->blocked ? 'blocked' : $step->status, $step->id, $step->description), OutputInterface::OUTPUT_RAW);
            if (!$step->isDone() && $step->reason !== null) {
                $output->writeln(str_repeat(' ', 9) . $step->reason, OutputInterface::OUTPUT_RAW);
            }
        }
        $pending = \count($report->pending());
        $output->writeln($pending === 0
            ? 'All provisioning steps are done.'
            : sprintf('%d provisioning step%s pending.', $pending, $pending === 1 ? '' : 's'));
    }
}
