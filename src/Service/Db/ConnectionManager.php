<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

class ConnectionManager implements ConnectionManagerInterface
{
    private ?Connection $tpvConn = null;

    public function __construct(private readonly array $config)
    {
    }

    public function getTpvConnection(): Connection
    {
        if ($this->tpvConn === null) {
            $params = $this->config;
            $params['schemaManagerFactory'] = new \Doctrine\DBAL\Schema\DefaultSchemaManagerFactory();

            $this->tpvConn = DriverManager::getConnection($params);
        }

        return $this->tpvConn;
    }
}
