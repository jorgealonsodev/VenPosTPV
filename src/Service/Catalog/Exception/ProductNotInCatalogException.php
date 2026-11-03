<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Catalog\Exception;

final class ProductNotInCatalogException extends \RuntimeException
{
    public function __construct(public readonly int $productId)
    {
        parent::__construct(sprintf('Product %d is not in the synced catalog; the price cannot be validated', $productId));
    }
}
