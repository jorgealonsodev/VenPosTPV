<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Db;

use Doctrine\DBAL\Connection;

interface ConnectionManagerInterface
{
    public function getTpvConnection(): Connection;
}
