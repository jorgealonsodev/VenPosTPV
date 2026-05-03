<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use VenPosTPV\Kernel;

class KernelTest extends TestCase
{
    private function createKernel(string $environment = 'test', bool $debug = false): Kernel
    {
        // Ensure APP_SECRET is set for FrameworkBundle during tests
        if (!isset($_ENV['APP_SECRET'])) {
            $_ENV['APP_SECRET'] = 'test_secret_for_phpunit_32_chars_min';
        }

        return new Kernel($environment, $debug);
    }

    public function testKernelBootsSuccessfully(): void
    {
        $kernel = $this->createKernel();
        $kernel->boot();

        $this->assertInstanceOf(KernelInterface::class, $kernel);

        $kernel->shutdown();
    }

    public function testKernelIsInTestEnvironment(): void
    {
        $kernel = $this->createKernel('test');
        $kernel->boot();

        $this->assertSame('test', $kernel->getEnvironment());

        $kernel->shutdown();
    }

    public function testKernelIsInProdEnvironment(): void
    {
        $kernel = $this->createKernel('prod', false);
        $kernel->boot();

        $this->assertSame('prod', $kernel->getEnvironment());

        $kernel->shutdown();
    }

    public function testKernelCacheDirIsSet(): void
    {
        $kernel = $this->createKernel();

        $this->assertStringEndsWith('/var/cache/test', $kernel->getCacheDir());
    }

    public function testKernelLogDirIsSet(): void
    {
        $kernel = $this->createKernel();

        $this->assertStringEndsWith('/var/log', $kernel->getLogDir());
    }

    public function testKernelHasContainer(): void
    {
        $kernel = $this->createKernel();
        $kernel->boot();

        $container = $kernel->getContainer();
        $this->assertNotNull($container);

        $kernel->shutdown();
    }
}
