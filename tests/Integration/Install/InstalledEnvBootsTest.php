<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Install;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use VenPosTPV\Kernel;
use VenPosTPV\Service\Install\WebInstaller;

/**
 * A Plesk install has only the settings the web installer writes. Every API
 * controller must build with them: a missing one (APP_URL) made checkout answer
 * 500 "Environment variable not found" in production. Requests carry no session,
 * so a built controller answers 401 and never reaches the database.
 */
final class InstalledEnvBootsTest extends TestCase
{
    private string $dir = '';
    private ?Kernel $kernel = null;
    /** @var array<string, string|null> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/venpos-installed-env-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $values = (new WebInstaller($this->dir))->validate([
            'tpv_url' => 'https://tpv.example.com',
            'db_host' => '127.0.0.1', 'db_port' => '1', 'db_name' => 'none', 'db_user' => 'none', 'db_password' => 'none',
            'admin_user' => 'admin', 'admin_password' => 'a-long-password',
            'store_name' => 'Tienda', 'store_address' => '',
            'ps_url' => 'https://shop.example.com', 'ps_api_key' => 'KEY',
            'sso_secret' => '', 'ps_currency' => '1', 'ps_shop' => '1', 'timezone' => 'Europe/Madrid',
        ])['values'];

        // Exactly the installer's .env: anything else this process has is removed.
        $names = array_unique(array_merge(array_keys($values), ['APP_URL', 'TPV_AGENT_MIN_VERSION', 'TPV_AGENT_LATEST_VERSION', 'TPV_AGENT_DOWNLOAD_URL']));
        foreach ($names as $name) {
            $this->saved[$name] = isset($_ENV[$name]) ? (string) $_ENV[$name] : null;
            unset($_ENV[$name]);
        }
        foreach ($values as $name => $value) {
            $_ENV[$name] = $value;
        }
        $_ENV['MESSENGER_TRANSPORT_DSN'] = 'sync://';
        $_ENV['APP_ENV'] = 'test';
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
        (new Filesystem())->remove($this->dir);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function endpoints(): iterable
    {
        yield 'checkout' => ['POST', '/api/checkout'];
        yield 'catalog search' => ['GET', '/api/catalog/search'];
        yield 'sync now' => ['POST', '/api/sync/now'];
        yield 'ticket verify' => ['GET', '/api/ticket/verify'];
        yield 'ticket email enabled' => ['GET', '/api/ticket/email-enabled'];
        yield 'email settings' => ['GET', '/api/settings/email'];
    }

    #[DataProvider('endpoints')]
    public function testTheInstallersSettingsBuildTheController(string $method, string $path): void
    {
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
        $request = Request::create('https://tpv.example.com' . $path, $method, [], [], [], ['HTTP_ORIGIN' => 'https://tpv.example.com', 'CONTENT_TYPE' => 'application/json'], '{}');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $response = $this->kernel->handle($request);

        $this->assertNotSame(500, $response->getStatusCode(), (string) $response->getContent());
    }
}
