<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * The installer's «Empezar de cero» (public/install.php, decision page): a new
 * installation with an empty database against a shop that holds an earlier
 * installation's fiscal journal. It is `tpv:fiscal:journal:reset --confirm
 * "<motivo>" --accept-shop-ahead` (FiscalJournalReset, the same checks, retirement
 * and audit record), recorded with origin `installer`.
 *
 * Never over a journal this installation already started (the database names one,
 * or the folders hold one): a double submit or the installer run again later never
 * retires or restarts it. Public so the installer can fetch it from the container.
 */
#[Autoconfigure(public: true)]
final class FiscalJournalFreshStart
{
    public function __construct(
        private readonly FiscalJournalReset $reset,
        private readonly FiscalJournalIdentity $identity,
        private readonly FiscalJournalLocations $locations,
    ) {
    }

    public function run(string $reason, ?\DateTimeImmutable $now = null): FiscalJournalResetResult
    {
        if ($this->identity->journalId() !== null || $this->locations->primary()->journalId() !== null || $this->locations->secondary()->journalId() !== null) {
            return new FiscalJournalResetResult(
                FiscalJournalResetResult::REFUSED,
                'Esta instalación ya tiene su diario fiscal: no se retira ni se vuelve a empezar. No se ha cambiado nada.',
                $this->identity->journalId(),
            );
        }

        return $this->reset->run($reason, $now, false, true, FiscalJournalReset::ORIGIN_INSTALLER);
    }
}
