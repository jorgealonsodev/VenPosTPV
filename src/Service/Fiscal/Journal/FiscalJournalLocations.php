<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The two folders of the fiscal journal (fiscal-journal D1): the primary beside the
 * database backups, and the secondary under var/ that takes the lines while the
 * primary cannot be written (S2). Neither is ever under public/.
 */
class FiscalJournalLocations
{
    private readonly JournalLocation $primary;
    private readonly JournalLocation $secondary;

    public function __construct(
        #[Autowire('%kernel.project_dir%/backups/fiscal-journal')] string $primary,
        #[Autowire('%kernel.project_dir%/var/fiscal-journal')] string $secondary,
    ) {
        $this->primary = new JournalLocation($primary);
        $this->secondary = new JournalLocation($secondary);
    }

    public function primary(): JournalLocation
    {
        return $this->primary;
    }

    public function secondary(): JournalLocation
    {
        return $this->secondary;
    }
}
