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
use VenPosTPV\Service\Share\ShareLinkSigner;

/**
 * The WhatsApp links through the real kernel: GET /d/{token} is routed without a
 * session and answers a bare 404 to a bad link before touching the database, and
 * the link APIs are registered behind the session.
 */
final class DocumentDownloadRouteTest extends TestCase
{
    private const ENV = [
        'APP_SECRET' => 'test_secret_for_phpunit_32_chars_min',
        'APP_URL' => 'https://tpv.example.com',
        'APP_ADMIN_USER' => 'admin',
        'APP_ADMIN_PASSWORD' => 'not-used-by-these-tests',
        'TPV_BASE_PATH' => '',
        'TPV_URL' => 'https://tpv.example.com',
        'TPV_STORE_NAME' => 'Test',
        'TPV_STORE_ADDRESS' => '',
        // The database points nowhere on purpose: these requests must never reach MySQL.
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

    private string $cacheDir = '';
    private ?Kernel $kernel = null;
    /** @var array<string, string|null> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/venpos-document-download-' . bin2hex(random_bytes(4));
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

    private function handle(string $path, string $method = 'GET', bool $withSession = false): Response
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
        $request = Request::create($path, $method, [], [], [], ['CONTENT_TYPE' => 'application/json'], $method === 'POST' ? '{}' : null);
        if ($withSession) {
            $request->setSession(new Session(new MockArraySessionStorage()));
        }

        return $this->kernel->handle($request);
    }

    public function testAForgedLinkIsABare404WithoutASession(): void
    {
        // Well formed, but signed with another secret: the controller answers, not the router.
        $forged = (new ShareLinkSigner('another-installation-secret-0123456789', 'https://x'))->sign('ticket', 1);

        $response = $this->handle('/d/' . $forged);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('', (string) $response->getContent());
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies(), 'no session cookie is handed out');
    }

    public function testAnythingElseUnderTheDownloadPathIsNotRouted(): void
    {
        foreach (['/d/', '/d/abc', '/d/a.b/c', '/d/../api/health'] as $path) {
            $this->assertSame(404, $this->handle($path)->getStatusCode(), $path);
        }
    }

    public function testTheLinkApisNeedASession(): void
    {
        foreach (['/api/ticket/sale/5/whatsapp', '/api/invoices/5/whatsapp', '/api/coupons/5/whatsapp'] as $path) {
            $this->assertSame(401, $this->handle($path, 'POST', true)->getStatusCode(), $path);
        }
    }
}
