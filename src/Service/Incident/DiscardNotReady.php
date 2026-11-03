<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Incident;

/**
 * The outbox has no columns to record who discarded an incident yet: migration
 * Version00000036OutboxDiscard has not been applied. Nothing was discarded.
 */
final class DiscardNotReady extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Discarding incidents needs migration Version00000036OutboxDiscard');
    }
}
