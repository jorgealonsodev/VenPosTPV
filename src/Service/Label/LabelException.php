<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Label;

/**
 * A label that cannot be printed; the message is the reason shown to the cashier
 * (docs/prd/agente-impresion.md L4), in Spanish.
 */
final class LabelException extends \RuntimeException
{
}
