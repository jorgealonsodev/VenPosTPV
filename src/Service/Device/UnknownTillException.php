<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Device;

/** No active till with that id. */
final class UnknownTillException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Caja no encontrada');
    }
}
