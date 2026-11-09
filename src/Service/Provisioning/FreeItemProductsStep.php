<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Provisioning;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use VenPosTPV\Service\Checkout\FreeItemSettingsStore;
use VenPosTPV\Service\Checkout\FreeItemSetupService;

/**
 * The hidden generic PrestaShop products free items are uploaded with
 * (docs/prd/articulo-libre.md, A5.2; the same work as tpv:free-item:setup). It
 * needs the shop and module tpvconnect 0.7.0, so at install time it usually stays
 * pending until the module is installed and configured.
 *
 * With the free item disabled (free_item.enabled = 0) there is nothing to
 * provision: the step counts as done and never calls PrestaShop.
 */
#[AsTaggedItem(priority: 100)]
final class FreeItemProductsStep implements ProvisioningStep
{
    public function __construct(
        private readonly FreeItemSetupService $setup,
        private readonly FreeItemSettingsStore $settings,
    ) {
    }

    public function id(): string
    {
        return 'free-item-products';
    }

    public function description(): string
    {
        return 'Productos genéricos del artículo libre en PrestaShop';
    }

    public function isDone(): bool
    {
        return !$this->settings->load()->enabled || $this->setup->isComplete();
    }

    public function run(): void
    {
        if (!$this->settings->load()->enabled) {
            return;
        }
        try {
            $this->setup->run();
        } catch (\Throwable $e) {
            throw new ProvisioningException(
                'PrestaShop no ha preparado los productos del artículo libre: ' . rtrim($e->getMessage(), '. ')
                . '. Comprueba que el módulo TPV Connect 0.7.0 o posterior está instalado y configurado, y que hay un grupo de impuestos activo para cada IVA del artículo libre.',
                0,
                $e
            );
        }
    }
}
