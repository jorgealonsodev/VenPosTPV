<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Catalog\Exception;

use RuntimeException;
use Throwable;

final class CatalogSyncException extends RuntimeException
{
    public static function fromPrevious(Throwable $previous): self
    {
        return new self(
            sprintf('Catalog sync failed: %s', $previous->getMessage()),
            0,
            $previous
        );
    }
}
