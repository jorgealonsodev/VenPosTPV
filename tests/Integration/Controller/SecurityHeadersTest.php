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
 * docs/prd-seguridad-strix.md R10 through the real kernel: the listener is
 * registered, the page renders, and its inline scripts carry the header's nonce.
 */
final class SecurityHeadersTest extends TestCase
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
        $this->cacheDir = sys_get_temp_dir() . '/venpos-security-headers-' . bin2hex(random_bytes(4));
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

    private function handle(string $path): Response
    {
        if ($this->kernel === null) {
            $cacheDir = $this->cacheDir;
            $this->kernel = new class ('test', false, $cacheDir) extends Kernel {
                public function __construct(string $environment, bool $debug, private readonly string $dir)
                {
                    parent::__construct($environment, $debug);
                }

                public function getCacheDir(): string
                {
                    return $this->dir;
                }
            };
        }
        $request = Request::create($path);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $this->kernel->handle($request);
    }

    public function testTheLoginPageCarriesTheCspAndItsNonce(): void
    {
        $response = $this->handle('/app/login');

        $this->assertSame(200, $response->getStatusCode());
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertSame(1, preg_match("/'nonce-([A-Za-z0-9+\\/=]+)'/", $csp, $m), $csp);
        $this->assertSame(2, substr_count((string) $response->getContent(), '<script nonce="' . $m[1] . '">'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('same-origin', $response->headers->get('Referrer-Policy'));
    }

    public function testTheNonceChangesOnEveryRequest(): void
    {
        $first = (string) $this->handle('/app/login')->headers->get('Content-Security-Policy');
        $second = (string) $this->handle('/app/login')->headers->get('Content-Security-Policy');

        $this->assertNotSame($first, $second);
    }

    public function testThePosPageRedirectCarriesTheHeadersToo(): void
    {
        // Without a cashier the POS page sends the browser to the login page.
        $response = $this->handle('/app/pos');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Content-Security-Policy'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function testTheHealthApiGetsNoCsp(): void
    {
        $response = $this->handle('/api/health');

        $this->assertFalse($response->headers->has('Content-Security-Policy'));
        $this->assertFalse($response->headers->has('X-Frame-Options'));
    }
}
