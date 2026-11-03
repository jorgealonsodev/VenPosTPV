<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Device;

/** The till is already linked to another computer; moving it needs confirmation. */
final class TillHasDeviceException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Esta caja ya está en otro equipo');
    }
}
