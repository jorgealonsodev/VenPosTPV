<?php

declare(strict_types=1);

namespace VenPosTPV\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use VenPosTPV\Service\Customer\CustomerSyncService;

/**
 * Refreshes the local copy of the PrestaShop customers (PRD C1). Run it like the
 * catalog sync (cron); customers created at the till upload through tpv:sync:outbox.
 */
#[AsCommand(name: 'tpv:customers:sync', description: 'Synchronize the local customer copy from the PrestaShop API')]
final class CustomerSyncCommand extends Command
{
    public function __construct(private readonly CustomerSyncService $customerSyncService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $start = microtime(true);
        try {
            $counts = $this->customerSyncService->syncAll();
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Customer sync failed: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Customer sync completed in %ss: %d customers, %d deactivated</info>',
            round(microtime(true) - $start, 3),
            $counts['customers'],
            $counts['deactivated']
        ));

        return Command::SUCCESS;
    }
}
