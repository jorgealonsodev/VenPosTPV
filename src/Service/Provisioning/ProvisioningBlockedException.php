<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Provisioning;

/**
 * A failure that retrying cannot fix and that retrying could make worse (a
 * migration that failed half way, a database whose checks found problems). The
 * minute task stops trying the step: only tpv:provision run by hand tries again,
 * or the minute task once the step's blockKey() differs from $blockKey (for the
 * migrations, a new release with another set of pending migrations).
 */
final class ProvisioningBlockedException extends ProvisioningException
{
    public function __construct(string $message, public readonly string $blockKey, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
