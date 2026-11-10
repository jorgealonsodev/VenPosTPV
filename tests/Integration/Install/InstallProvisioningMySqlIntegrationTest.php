<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Install;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use VenPosTPV\Kernel;
use VenPosTPV\Service\Install\MigrationRunner;
use VenPosTPV\Service\Install\WebInstaller;
use VenPosTPV\Service\Provisioning\ProvisioningRunner;
use VenPosTPV\Service\Provisioning\StepStatus;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * A real install on an empty MySQL database with a shop that does not answer yet
 * (the module is not installed): the migrations are applied, the provisioning steps
 * run from the installed settings, and the shop step stays pending with its reason
 * instead of failing the install.
 */
final class InstallProvisioningMySqlIntegrationTest extends TestCase
{
    private ?Connection $server = null;
    private ?Connection $conn = null;
    private string $database = '';
    private string $dir = '';
    private ?Kernel $kernel = null;
    /** @var array<string, string|null> */
    private array $saved = [];

    protected function setUp(): void
    {
        $settings = TestDatabase::settings();
        try {
            $this->server = DriverManager::getConnection([
                'driver' => 'pdo_mysql', 'host' => $settings['host'], 'port' => $settings['port'],
                'user' => $settings['user'], 'password' => $settings['password'], 'charset' => 'utf8mb4',
            ]);
            $this->server->executeQuery('SELECT 1');
        } catch (\Throwable) {
            self::markTestSkipped('MySQL is not available.');
        }
        $this->database = $settings['name'] . '_install_' . bin2hex(random_bytes(3));
        $this->server->executeStatement(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->database));
        $this->dir = sys_get_temp_dir() . '/venpos-install-provisioning-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        // The installer reads migrations/ from its project folder.
        symlink(\dirname(__DIR__, 3) . '/migrations', $this->dir . '/migrations');

        $values = (new WebInstaller($this->dir))->validate([
            'tpv_url' => 'https://tpv.example.com',
            'db_host' => $settings['host'], 'db_port' => (string) $settings['port'], 'db_name' => $this->database,
            'db_user' => $settings['user'], 'db_password' => $settings['password'],
            'admin_user' => 'admin', 'admin_password' => 'a-long-password',
            'store_name' => 'Tienda', 'store_address' => '',
            // Nothing listens there: the module is "not installed yet".
            'ps_url' => 'http://127.0.0.1:1', 'ps_api_key' => 'KEY',
            'sso_secret' => str_repeat('s', 32), 'ps_currency' => '1', 'ps_shop' => '1', 'timezone' => 'Europe/Madrid',
        ])['values'];
        $values['MESSENGER_TRANSPORT_DSN'] = 'sync://';
        $values['APP_ENV'] = 'test';
        foreach ($values as $name => $value) {
            $this->saved[$name] = isset($_ENV[$name]) ? (string) $_ENV[$name] : null;
            $_ENV[$name] = $value;
        }
        $this->conn = (new WebInstaller($this->dir))->connect($values);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        foreach ($this->saved as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
        $this->conn?->close();
        if ($this->server !== null && $this->database !== '') {
            $this->server->executeStatement(sprintf('DROP DATABASE IF EXISTS `%s`', $this->database));
            $this->server->close();
        }
        if ($this->dir !== '') {
            (new Filesystem())->remove($this->dir);
        }
    }

    public function testTheInstallMigratesAndLeavesTheShopStepPendingWithItsReason(): void
    {
        $installer = new WebInstaller($this->dir);
        $installer->assertDatabaseUsable($this->conn);
        $executed = $installer->migrate($this->conn);
        $this->assertGreaterThan(0, $executed);

        $dir = $this->dir;
        $this->kernel = new class ('test', false, $dir) extends Kernel {
            public function __construct(string $environment, bool $debug, private readonly string $cache)
            {
                parent::__construct($environment, $debug);
            }

            public function getCacheDir(): string
            {
                return $this->cache . '/cache';
            }
        };
        $kernel = $this->kernel;
        $report = $installer->provision(static function () use ($kernel): ProvisioningRunner {
            $kernel->boot();
            $runner = $kernel->getContainer()->get(ProvisioningRunner::class);
            \assert($runner instanceof ProvisioningRunner);

            return $runner;
        });

        $this->assertNotNull($report);
        $byId = [];
        foreach ($report->steps as $step) {
            $byId[$step->id] = $step;
        }
        // coupon-checkout joined the steps in a39363f; coupon checkout is on by default
        // (config/services.yaml), so the step is already done and changes nothing.
        $this->assertSame(['migrations', 'free-item-products', 'coupon-checkout'], array_keys($byId), 'the migrations go first');
        $this->assertSame(StepStatus::DONE, $byId['migrations']->status);
        $this->assertFalse($byId['migrations']->attempted, 'the installer already migrated');
        $this->assertSame(StepStatus::FAILED, $byId['free-item-products']->status);
        $this->assertStringStartsWith('PrestaShop no ha preparado los productos del artículo libre: ', (string) $byId['free-item-products']->reason);
        $this->assertSame(StepStatus::DONE, $byId['coupon-checkout']->status);
        $this->assertFalse($byId['coupon-checkout']->attempted, 'coupon checkout is already enabled: .env is not rewritten');

        $this->assertSame(0, (new MigrationRunner(\dirname(__DIR__, 3) . '/migrations'))->pendingCount($this->conn));
        $stored = $this->conn->fetchOne("SELECT value FROM app_setting WHERE name = 'provisioning.free-item-products'");
        $this->assertIsString($stored, 'the reason is kept for Ajustes and the next run');
    }
}
