<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Provisioning;

/**
 * A step that may block itself (ProvisioningBlockedException) and can tell when
 * the situation that blocked it has changed. blockKey() must be cheap and
 * side-effect free: the minute task calls it on every run while the step is
 * blocked. A blocked step without this interface stays blocked until tpv:provision.
 */
interface BlockableProvisioningStep extends ProvisioningStep
{
    /** Identifies what the step would work on now; a block only holds while it is the same. */
    public function blockKey(): string;
}
