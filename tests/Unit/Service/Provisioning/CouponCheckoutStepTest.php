<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Provisioning;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use VenPosTPV\Service\Install\EnvFileUpdater;
use VenPosTPV\Service\Provisioning\CouponCheckoutStep;
use VenPosTPV\Service\Provisioning\ProvisioningException;

/**
 * Installs made before coupon checkout was on by default have
 * TPV_COUPON_CHECKOUT_ENABLED=0 in .env (the installer wrote it). The upgrade turns
 * it on once; an administrator who turns it off again afterwards keeps it off.
 */
final class CouponCheckoutStepTest extends TestCase
{
    private string $dir;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tpv-coupon-step-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        mkdir($this->dir . '/var');
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE app_setting (name VARCHAR(100) PRIMARY KEY, value TEXT)');
    }

    protected function tearDown(): void
    {
        foreach ([...glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [], ...glob($this->dir . '/var/{,.}*', GLOB_BRACE) ?: []] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($this->dir . '/var');
        @rmdir($this->dir);
    }

    private function step(bool $enabled): CouponCheckoutStep
    {
        return new CouponCheckoutStep($this->connection, new EnvFileUpdater($this->dir), $enabled, 'prod', static fn (): array => []);
    }

    /** @return array<string, string> */
    private function env(): array
    {
        return (new Dotenv())->parse((string) file_get_contents($this->dir . '/.env'));
    }

    public function testItIsDoneWhenCouponCheckoutIsAlreadyOn(): void
    {
        self::assertTrue($this->step(true)->isDone());
        self::assertSame('coupon-checkout', $this->step(true)->id());
        self::assertSame('Cobro con cupón activado', $this->step(true)->description());
    }

    public function testAnOldInstallTurnsItOnInEnvKeepingEverythingElse(): void
    {
        file_put_contents($this->dir . '/.env', "APP_ENV='prod'\nAPP_SECRET='keep-me'\nTPV_COUPON_CHECKOUT_ENABLED='0'\n");
        $step = $this->step(false);
        self::assertFalse($step->isDone());

        $step->run();

        self::assertTrue($step->isDone(), 'the runner checks isDone() again in the same process');
        self::assertSame('1', $this->env()['TPV_COUPON_CHECKOUT_ENABLED']);
        self::assertSame('keep-me', $this->env()['APP_SECRET']);
        self::assertCount(1, glob($this->dir . '/.env.bak-*') ?: []);
    }

    public function testTurningItOffAgainAfterTheUpgradeIsRespected(): void
    {
        file_put_contents($this->dir . '/.env', "TPV_COUPON_CHECKOUT_ENABLED='0'\n");
        $this->step(false)->run();
        file_put_contents($this->dir . '/.env', "TPV_COUPON_CHECKOUT_ENABLED='0'\n");

        self::assertTrue($this->step(false)->isDone());
        self::assertSame('0', $this->env()['TPV_COUPON_CHECKOUT_ENABLED']);
    }

    public function testAValueSetOutsideEnvIsReportedWithWhereToChangeIt(): void
    {
        file_put_contents($this->dir . '/.env', "TPV_COUPON_CHECKOUT_ENABLED='0'\n");
        file_put_contents($this->dir . '/.env.local', "TPV_COUPON_CHECKOUT_ENABLED=0\n");
        $step = $this->step(false);

        try {
            $step->run();
            self::fail('A ProvisioningException was expected.');
        } catch (ProvisioningException $e) {
            self::assertStringContainsString('TPV_COUPON_CHECKOUT_ENABLED está definida en .env.local', $e->getMessage());
        }
        self::assertFalse($step->isDone());
        self::assertSame('0', $this->env()['TPV_COUPON_CHECKOUT_ENABLED']);
    }
}
