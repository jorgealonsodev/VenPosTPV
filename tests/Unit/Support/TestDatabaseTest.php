<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Tests\Support\TestDatabase;

final class TestDatabaseTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        $this->setDatabaseEnvironment('venpostpv', 'venpostpv_test');
    }

    protected function tearDown(): void
    {
        $_ENV = $this->environment;
    }

    public function testSettingsRejectUnsafeTestDatabaseSelectorsWithoutConnecting(): void
    {
        $_ENV['TPV_DB_TEST_NAME'] = 'venpostpv_test`; DROP DATABASE venpostpv; --';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('safe SQL identifier');

        TestDatabase::settings();
    }

    public function testSettingsRequireTheExplicitTestSuffix(): void
    {
        $_ENV['TPV_DB_TEST_NAME'] = 'venpostpv_sandbox';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('end in "_test"');

        TestDatabase::settings();
    }

    public function testSettingsRejectTestDatabaseSelectorMatchingTheBaseDatabase(): void
    {
        $this->setDatabaseEnvironment('venpostpv_test', 'venpostpv_test');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must differ from the base database');

        TestDatabase::settings();
    }

    public function testSettingsRejectBaseDatabaseAliasIgnoringCaseBeforeConnecting(): void
    {
        $this->setDatabaseEnvironment('VENPOSTPV_test', 'venpostpv_test');
        $_ENV['TPV_DB_HOST'] = 'invalid-host-must-not-be-contacted';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must differ from the base database');

        TestDatabase::connection();
    }

    public function testExplicitEnvironmentValuesTakePrecedenceWithoutMutation(): void
    {
        $_ENV['TPV_DB_HOST'] = 'mysql-test';
        $_ENV['TPV_DB_PORT'] = '3307';
        $_ENV['TPV_DB_NAME'] = 'catalog_development';
        $_ENV['TPV_DB_USER'] = 'test_user';
        $_ENV['TPV_DB_PASSWORD'] = 'test_password';
        $_ENV['TPV_DB_TEST_NAME'] = 'catalog_isolated_test';
        $before = $_ENV;

        self::assertSame([
            'host' => 'mysql-test',
            'port' => 3307,
            'name' => 'catalog_isolated_test',
            'user' => 'test_user',
            'password' => 'test_password',
        ], TestDatabase::settings());
        self::assertSame($before, $_ENV);
    }

    private function setDatabaseEnvironment(string $baseName, string $testName): void
    {
        foreach ([
            'TPV_DB_HOST',
            'TPV_DB_PORT',
            'TPV_DB_NAME',
            'TPV_DB_USER',
            'TPV_DB_PASSWORD',
            'TPV_DB_TEST_NAME',
        ] as $key) {
            unset($_ENV[$key]);
        }

        $_ENV['TPV_DB_NAME'] = $baseName;
        $_ENV['TPV_DB_TEST_NAME'] = $testName;
    }
}
