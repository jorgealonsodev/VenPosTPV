<?php

declare(strict_types=1);

namespace VenPosTPV\Command;

use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use VenPosTPV\Service\Catalog\CatalogSyncService;
use VenPosTPV\Service\Catalog\Exception\CatalogSyncException;
use VenPosTPV\Service\PrestaShop\Exception\PsApiException;

#[AsCommand(name: 'tpv:catalog:sync', description: 'Synchronize catalog from PrestaShop API')]
final class CatalogSyncCommand extends Command
{

    public function __construct(private readonly CatalogSyncService $catalogSyncService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Synchronize catalog from PrestaShop API')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Full sync (default)')
            ->addOption('stock-only', null, InputOption::VALUE_NONE, 'Sync stock only');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $full = $input->getOption('full');
        $stockOnly = $input->getOption('stock-only');

        if ($full && $stockOnly) {
            $output->writeln('<error>Cannot use both --full and --stock-only</error>');

            return Command::FAILURE;
        }

        $mode = $stockOnly ? 'stock-only' : 'full';
        $startTime = microtime(true);

        try {
            if ($stockOnly) {
                $counts = $this->catalogSyncService->syncStock();
            } else {
                $counts = $this->catalogSyncService->syncAll();
            }

            $duration = round(microtime(true) - $startTime, 3);

            $output->writeln(sprintf('<info>Catalog sync completed in %ss</info>', $duration));

            $table = new Table($output);
            $table->setHeaders(['Entity', 'Count']);

            if (isset($counts['categories'])) {
                $table->addRow(['Categories', $counts['categories']]);
            }

            if (isset($counts['products'])) {
                $table->addRow(['Products', $counts['products']]);
            }

            if (isset($counts['combinations'])) {
                $table->addRow(['Combinations', $counts['combinations']]);
            }

            $table->addRow(['Stock', $counts['stock']]);

            $table->render();

            return Command::SUCCESS;
        } catch (PsApiException $e) {
            $output->writeln(sprintf('<error>PrestaShop API error: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        } catch (CatalogSyncException $e) {
            $output->writeln(sprintf('<error>Sync failed: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        } catch (InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::FAILURE;
        }
    }
}
