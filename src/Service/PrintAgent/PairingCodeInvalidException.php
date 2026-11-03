<?php

declare(strict_types=1);

namespace VenPosTPV\Service\PrintAgent;

/** PRD E3, AC3: the code does not exist, expired, was used or was voided by 5 failures. */
final class PairingCodeInvalidException extends \RuntimeException
{
    public const CODE = 'pairing_code_invalid';

    public function __construct()
    {
        parent::__construct('Código no válido o caducado. Genera otro en Ajustes → Cajas.');
    }
}
