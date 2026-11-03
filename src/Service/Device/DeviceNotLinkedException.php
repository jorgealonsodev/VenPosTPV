<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Device;

/**
 * The browser has no linked till. Deliberately not a RuntimeException: the cash
 * controllers turn RuntimeException into their own 409 without the code the
 * POS needs to show the link screen; DeviceListener answers this one.
 */
final class DeviceNotLinkedException extends \LogicException
{
    public function __construct()
    {
        parent::__construct('Este equipo no tiene caja asignada');
    }
}
