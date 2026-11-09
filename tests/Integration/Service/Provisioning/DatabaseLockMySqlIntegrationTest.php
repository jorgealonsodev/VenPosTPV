<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Provisioning;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Provisioning\DatabaseLock;
use VenPosTPV\Tests\Support\TestDatabase;

/** Two minute tasks at once: only one of them may migrate. */
final class DatabaseLockMySqlIntegrationTest extends TestCase
{
    /** @var list<Connection> */
    private array $connections = [];

    private function connect(): Connection
    {
        $settings = TestDatabase::settings();
        $conn = DriverManager::getConnection([
            'driver' => 'pdo_mysql', 'host' => $settings['host'], 'port' => $settings['port'],
            'user' => $settings['user'], 'password' => $settings['password'], 'charset' => 'utf8mb4',
        ]);
        try {
            $conn->executeQuery('SELECT 1');
        } catch (\Throwable) {
            self::markTestSkipped('MySQL is not available.');
        }

        return $this->connections[] = $conn;
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $conn) {
            $conn->close();
        }
    }

    public function testOnlyOneConnectionHoldsTheLockUntilItIsReleased(): void
    {
        $name = 'venpostpv_test_' . bin2hex(random_bytes(4));
        $first = new DatabaseLock($this->connect());
        $second = new DatabaseLock($this->connect());

        $this->assertTrue($first->acquire($name));
        $this->assertFalse($second->acquire($name), 'never waits: the other run is busy');

        $first->release($name);
        $this->assertTrue($second->acquire($name));
        $second->release($name);
    }

    public function testAClosedConnectionFreesItsLock(): void
    {
        $name = 'venpostpv_test_' . bin2hex(random_bytes(4));
        $crashed = $this->connect();
        $this->assertTrue((new DatabaseLock($crashed))->acquire($name));
        $crashed->close();

        $other = new DatabaseLock($this->connect());
        $this->assertTrue($other->acquire($name), 'a run that died does not block the next one');
        $other->release($name);
    }
}
