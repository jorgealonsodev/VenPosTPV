<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Provisioning;

/**
 * Another run is doing this step right now. It is not a failure: nothing is
 * remembered, so the next run checks it again without waiting.
 */
final class ProvisioningBusyException extends ProvisioningException
{
}
