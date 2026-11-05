<?php

declare(strict_types=1);

namespace VenPosTPV\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use VenPosTPV\Service\Sale\SaleLineBackfill;

/**
 * Writes the sale lines (sale_line) of the sales stored before Version00000046, so
 * the sales reports include them. Run it once after that migration:
 *
 *     php bin/console tpv:sales:backfill-lines --env=prod
 *
 * It can be stopped and run again: sales that already have lines are skipped. Their
 * category, reference and VAT rate come from the current catalog, so the reports
 * mark them as approximate. Sales whose stored lines cannot be read are listed by id
 * and the command exits 1.
 */
#[AsCommand(name: 'tpv:sales:backfill-lines', description: 'Write the sale lines of sales stored before the sale_line table')]
final class SalesBackfillLinesCommand extends Command
{
    public function __construct(private readonly SaleLineBackfill $backfill)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (\function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        try {
            $result = $this->backfill->run();
        } catch (\Throwable $e) {
            $output->writeln('Backfill failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Sales processed: %d, lines created: %d, approximate lines: %d',
            $result['sales'],
            $result['lines'],
            $result['approximate']
        ));
        if ($result['unreadable'] !== []) {
            $output->writeln(sprintf(
                'Sales skipped, their lines cannot be read (order_meta.id): %s',
                implode(', ', $result['unreadable'])
            ));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
