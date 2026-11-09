<?php

declare(strict_types=1);

namespace VenPosTPV\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use VenPosTPV\Service\Checkout\FreeItemSetupService;

/**
 * Creates or checks in PrestaShop the hidden generic products free items are
 * uploaded with (docs/prd/articulo-libre.md, A5.2) and stores their ids:
 *
 *     php bin/console tpv:free-item:setup --env=prod
 *
 * Needs module tpvconnect 0.7.0 and, in PrestaShop, an active tax rules group for
 * each VAT rate in free_item.vat_rates (21 % and 10 % by default). Safe to run
 * again: it returns the same ids. Until it has run, sales with a free item wait in
 * the outbox with «Falta configurar el artículo libre en PrestaShop». The
 * installer, the minute task and tpv:provision run the same setup on their own
 * while it is pending (the «free-item-products» step, docs/provisioning.md).
 */
#[AsCommand(name: 'tpv:free-item:setup', description: 'Create or check the PrestaShop products free items are uploaded with')]
final class FreeItemSetupCommand extends Command
{
    public function __construct(private readonly FreeItemSetupService $setup)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $map = $this->setup->run();
        } catch (\Throwable $e) {
            $output->writeln('Free item setup failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        foreach ($map as $rate => $entry) {
            $output->writeln(sprintf(
                'VAT %s %%: PrestaShop product %d, %d slot combinations (%d-%d)',
                $rate,
                $entry['id_product'],
                \count($entry['slots']),
                min($entry['slots']),
                max($entry['slots'])
            ));
        }

        return Command::SUCCESS;
    }
}
