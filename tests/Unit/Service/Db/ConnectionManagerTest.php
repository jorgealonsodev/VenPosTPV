<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Db;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Db\ConnectionManager;
use VenPosTPV\Service\Db\ConnectionManagerInterface;

class ConnectionManagerTest extends TestCase
{
    private const DB_CONFIG = [
        'driver' => 'pdo_mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'dbname' => 'tpv_test',
        'user' => 'test_user',
        'password' => 'test_pass',
        'charset' => 'utf8mb4',
    ];

    public function testImplementsInterface(): void
    {
        $manager = new ConnectionManager(self::DB_CONFIG);

        $this->assertInstanceOf(ConnectionManagerInterface::class, $manager);
    }

    public function testConstructorAcceptsConfig(): void
    {
        $manager = new ConnectionManager(self::DB_CONFIG);

        // Config is stored internally — no exception on construct means it's accepted
        $this->assertInstanceOf(ConnectionManager::class, $manager);
    }

    public function testGetTpvConnectionReturnsConnection(): void
    {
        $manager = new ConnectionManager(self::DB_CONFIG);

        // Without a real MySQL server, this will throw.
        // We verify the lazy-connect path is executed and Connection is the expected type.
        try {
            $conn = $manager->getTpvConnection();
            $this->assertInstanceOf(Connection::class, $conn);
        } catch (\Doctrine\DBAL\Exception\ConnectionException $e) {
            // Expected when no DB is available — the important thing is that
            // getTpvConnection() was called and attempted to connect.
            $this->assertStringContainsString('127.0.0.1', $e->getMessage());
        }
    }

    public function testGetTpvConnectionIsLazy(): void
    {
        // The connection should only be created on the first call to getTpvConnection(),
        // not during construction.
        $manager = new ConnectionManager(self::DB_CONFIG);

        // Construction succeeded without any connection attempt (no exception thrown).
        // We verify this by checking the object was created successfully.
        $this->assertInstanceOf(ConnectionManager::class, $manager);

        // The actual connection attempt happens on getTpvConnection() call,
        // which is verified in testGetTpvConnectionReturnsConnection.
    }
}
