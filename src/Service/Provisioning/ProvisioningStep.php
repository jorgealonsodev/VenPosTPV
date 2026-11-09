<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Provisioning;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One idempotent piece of setup that an install or an upgrade needs
 * (docs/provisioning.md). Every class implementing this interface is registered
 * automatically and run by ProvisioningRunner: from the web installer, from the
 * minute task tpv:sync:outbox and from tpv:provision. Its order comes from
 * #[AsTaggedItem(priority: …)] on the class (higher runs first).
 *
 * isDone() must be cheap and side-effect free: the minute task calls it on every
 * run. run() may assume isDone() was false, must be safe to repeat, and reports a
 * failure by throwing ProvisioningException with a reason in Spanish that an
 * administrator can act on (it is shown in Ajustes and in the installer).
 */
#[AutoconfigureTag(self::TAG)]
interface ProvisioningStep
{
    public const TAG = 'venpostpv.provisioning_step';

    /** Stable identifier (lowercase, digits and dashes): it names the stored state. */
    public function id(): string;

    /** What the step does, in Spanish, for Ajustes and the installer. */
    public function description(): string;

    public function isDone(): bool;

    /** @throws ProvisioningException with the reason in Spanish */
    public function run(): void;
}
