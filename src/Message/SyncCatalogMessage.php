<?php

declare(strict_types=1);

namespace VenPosTPV\Message;

/**
 * @final
 */
final class SyncCatalogMessage
{
    public function __construct(public readonly bool $stockOnly = false)
    {
    }
}
