<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use VenPosTPV\Kernel;

/**
 * Behind a hosting nginx every request comes from the proxy: with TRUSTED_PROXIES the
 * real client IP (X-Forwarded-For) is what the print agent's rate limits and last_ip
 * use (E6, E7). Without it nothing changes.
 */
final class TrustedProxiesTest extends TestCase
{
    private string $cacheDir = '';
    /** @var list<string> */
    private array $proxies = [];
    private int $headers = 0;

    protected function setUp(): void
    {
        $this->proxies = Request::getTrustedProxies();
        $this->headers = Request::getTrustedHeaderSet();
        $this->cacheDir = sys_get_temp_dir() . '/venpos-trusted-proxies-' . bin2hex(random_bytes(4));
        if (!isset($_ENV['APP_SECRET'])) {
            $_ENV['APP_SECRET'] = 'test_secret_for_phpunit_32_chars_min';
        }
    }

    protected function tearDown(): void
    {
        unset($_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
        Request::setTrustedProxies($this->proxies, $this->headers);
        (new Filesystem())->remove($this->cacheDir);
    }

    private function boot(?string $trustedProxies): void
    {
        if ($trustedProxies !== null) {
            $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = $trustedProxies;
        }
        Request::setTrustedProxies([], Request::getTrustedHeaderSet());
        $cacheDir = $this->cacheDir;
        $kernel = new class ('test', false, $cacheDir) extends Kernel {
            public function __construct(string $environment, bool $debug, private readonly string $dir)
            {
                parent::__construct($environment, $debug);
            }

            public function getCacheDir(): string
            {
                return $this->dir;
            }
        };
        $kernel->boot();
        $kernel->shutdown();
    }

    private static function behindNginx(): Request
    {
        return Request::create('https://tpv.example/api/agent/v1/jobs', 'GET', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '83.45.10.7',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
    }

    public function testTheForwardedClientIpIsUsedBehindATrustedProxy(): void
    {
        $this->boot('127.0.0.1,::1');

        $request = self::behindNginx();
        $this->assertSame('83.45.10.7', $request->getClientIp());
        $this->assertTrue($request->isSecure());
    }

    public function testWithoutTheSettingTheProxyAddressIsKept(): void
    {
        $this->boot(null);

        $this->assertSame('127.0.0.1', self::behindNginx()->getClientIp(), 'default: the current behaviour');
    }

    public function testAnUntrustedSenderCannotForgeItsIp(): void
    {
        $this->boot('127.0.0.1');

        $request = Request::create('/x', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '10.0.0.1']);
        $this->assertSame('203.0.113.9', $request->getClientIp());
    }
}
