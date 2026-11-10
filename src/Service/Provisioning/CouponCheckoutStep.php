<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Provisioning;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use VenPosTPV\Service\Install\EnvFileUpdater;
use VenPosTPV\Service\Install\InstallException;

/**
 * Turns coupon checkout on in installs made while it was off by default: the
 * web installer used to write TPV_COUPON_CHECKOUT_ENABLED=0 into .env, so the
 * till answered «coupon_unavailable» to every coupon (docs/tpv-coupon-checkout.md).
 * The bundled TPV Connect module knows coupon_preview, so it is safe to enable.
 *
 * It runs once: the app_setting marker records it, and an administrator who sets
 * the variable back to 0 afterwards keeps it off.
 */
#[AsTaggedItem(priority: 90)]
final class CouponCheckoutStep implements ProvisioningStep
{
    public const MARKER = 'coupon_checkout.enabled_by_upgrade';
    private const VARIABLE = 'TPV_COUPON_CHECKOUT_ENABLED';

    /**
     * @param (\Closure(): array<string, string>)|null $environment the process environment; tests pass their own
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EnvFileUpdater $envUpdater,
        #[Autowire('%env(bool:TPV_COUPON_CHECKOUT_ENABLED)%')] private readonly bool $enabled,
        #[Autowire('%kernel.environment%')] private readonly string $appEnv = 'prod',
        private readonly ?\Closure $environment = null,
    ) {
    }

    public function id(): string
    {
        return 'coupon-checkout';
    }

    public function description(): string
    {
        return 'Cobro con cupón activado';
    }

    public function isDone(): bool
    {
        if ($this->enabled) {
            return true;
        }
        try {
            return $this->connection->fetchOne('SELECT value FROM app_setting WHERE name = ?', [self::MARKER]) !== false;
        } catch (TableNotFoundException) {
            // Before the migrations: the runner retries it later.
            return false;
        }
    }

    public function run(): void
    {
        try {
            $this->envUpdater->update([self::VARIABLE => '1'], ($this->environment ?? EnvFileUpdater::processEnvironment(...))(), $this->appEnv);
        } catch (InstallException $e) {
            throw new ProvisioningException(
                'No se pudo activar el cobro con cupón: ' . rtrim($e->getMessage(), '. ') . ' (pon ' . self::VARIABLE . '=1).',
                0,
                $e
            );
        }
        // Portable upsert (the unit tests run on SQLite), as ProvisioningStateStore.
        $this->connection->transactional(function (Connection $conn): void {
            $conn->executeStatement('DELETE FROM app_setting WHERE name = ?', [self::MARKER]);
            $conn->executeStatement('INSERT INTO app_setting (name, value) VALUES (?, ?)', [self::MARKER, gmdate(\DATE_ATOM)]);
        });
    }
}
