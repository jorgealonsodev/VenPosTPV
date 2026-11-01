<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Cash\Exception;

final class NoOpenCashSessionException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No open cash session');
    }
}
