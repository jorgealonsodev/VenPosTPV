<?php

declare(strict_types=1);

namespace VenPosTPV\Service\PrintAgent;

/**
 * PRD T9: a job for a print agent needs the till of the request; terminal 1 is
 * never assumed. Answered as 400 {"code": "terminal_not_resolved"}.
 */
final class TerminalNotResolvedException extends \RuntimeException
{
    public const CODE = 'terminal_not_resolved';

    public function __construct()
    {
        parent::__construct('No se ha podido saber la caja de esta petición');
    }
}
