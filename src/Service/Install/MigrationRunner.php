<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Install;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;

/**
 * The Doctrine Migrations setup of the TPV (the migrations/ folder, all or
 * nothing), shared by the web installer and the «migrations» provisioning step,
 * so both apply exactly the same migrations.
 */
class MigrationRunner
{
    public function __construct(private readonly string $migrationsDirectory)
    {
    }

    /** Migrations in migrations/ that this database has not executed yet. */
    public function pendingCount(Connection $conn): int
    {
        return \count($this->plan($this->factory($conn)));
    }

    /** Migrations this database has executed (0 on an empty database). */
    public function executedCount(Connection $conn): int
    {
        return \count($this->factory($conn)->getMetadataStorage()->getExecutedMigrations()->getItems());
    }

    /** @return int number of migrations executed */
    public function migrate(Connection $conn): int
    {
        $factory = $this->factory($conn);
        $plan = $this->plan($factory);
        if (\count($plan) === 0) {
            return 0;
        }
        $factory->getMigrator()->migrate($plan, (new MigratorConfiguration())->setAllOrNothing(true));

        return \count($plan);
    }

    private function factory(Connection $conn): DependencyFactory
    {
        $configuration = new Configuration();
        $configuration->addMigrationsDirectory('VenPosTPV\\Migrations', $this->migrationsDirectory);
        $configuration->setAllOrNothing(true);
        $configuration->setCheckDatabasePlatform(false);

        $factory = DependencyFactory::fromConnection(
            new ExistingConfiguration($configuration),
            new ExistingConnection($conn)
        );
        $factory->getMetadataStorage()->ensureInitialized();

        return $factory;
    }

    private function plan(DependencyFactory $factory): \Doctrine\Migrations\Metadata\MigrationPlanList
    {
        return $factory->getMigrationPlanCalculator()->getPlanUntilVersion(
            $factory->getVersionAliasResolver()->resolveVersionAlias('latest')
        );
    }
}
