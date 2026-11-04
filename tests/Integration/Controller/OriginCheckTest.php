<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use VenPosTPV\Kernel;

/**
 * docs/prd-seguridad-strix.md R7 through the real kernel: the Origin check is
 * registered and runs before the controller (vuln-0005: cross-site logout).
 */
final class OriginCheckTest extends TestCase
{
    private string $cacheDir = '';
    private ?Kernel $kernel = null;
    /** @var array<string, string|null> values replaced for the test, restored afterwards */
    private array $savedEnv = [];

    /**
     * Values the container needs to build the request's services. The database
     * points nowhere on purpose: these requests must never reach MySQL.
     */
    private const ENV = [
        'APP_SECRET' => 'test_secret_for_phpunit_32_chars_min',
        'APP_URL' => 'https://tpv.example.com',
        'APP_ADMIN_USER' => 'admin',
        'APP_ADMIN_PASSWORD' => 'not-used-by-these-tests',
        'TPV_BASE_PATH' => '',
        'TPV_URL' => 'https://tpv.example.com',
        'TPV_STORE_NAME' => 'Test',
        'TPV_STORE_ADDRESS' => '',
        'TPV_DB_HOST' => '127.0.0.1',
        'TPV_DB_PORT' => '1',
        'TPV_DB_NAME' => 'none',
        'TPV_DB_USER' => 'none',
        'TPV_DB_PASSWORD' => 'none',
        'PS_SHOP_URL' => 'https://tienda.example.com',
        'PS_API_KEY' => 'none',
        'PS_DEFAULT_CURRENCY' => '1',
        'PS_DEFAULT_SHOP' => '1',
        'SSO_SHARED_SECRET' => 'none',
        'MESSENGER_TRANSPORT_DSN' => 'sync://',
    ];

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/venpos-origin-check-' . bin2hex(random_bytes(4));
        foreach (self::ENV as $name => $value) {
            $this->savedEnv[$name] = isset($_ENV[$name]) ? (string) $_ENV[$name] : null;
            $_ENV[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        foreach ($this->savedEnv as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
        (new Filesystem())->remove($this->cacheDir);
    }

    private function logout(?string $origin): Response
    {
        $cacheDir = $this->cacheDir;
        $this->kernel ??= new class ('test', false, $cacheDir) extends Kernel {
            public function __construct(string $environment, bool $debug, private readonly string $dir)
            {
                parent::__construct($environment, $debug);
            }

            public function getCacheDir(): string
            {
                return $this->dir;
            }
        };
        $server = $origin !== null ? ['HTTP_ORIGIN' => $origin, 'CONTENT_TYPE' => 'text/plain'] : [];
        $request = Request::create('https://tpv.example.com/api/auth/logout', 'POST', [], [], [], $server, '{}');
        $session = new Session(new MockArraySessionStorage());
        $session->set('user_id', 7);
        $request->setSession($session);

        $response = $this->kernel->handle($request);
        $response->headers->set('X-Test-Session-User', var_export($session->get('user_id'), true));

        return $response;
    }

    public function testAForeignSiteCannotLogTheCashierOut(): void
    {
        $response = $this->logout('https://tienda.example.com');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['error' => 'Origin not allowed'], json_decode((string) $response->getContent(), true));
        $this->assertSame('7', $response->headers->get('X-Test-Session-User'), 'the session is untouched');
    }

    public function testTheTpvItselfCanStillLogOut(): void
    {
        $response = $this->logout('https://tpv.example.com');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('NULL', $response->headers->get('X-Test-Session-User'));
    }
}
