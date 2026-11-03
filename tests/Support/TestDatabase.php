<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;

/**
 * Connection settings for tests that need a real MySQL database.
 *
 * The suite used to point at the development database. That was unsafe twice over:
 * the migration tests dropped and recreated columns, wiping the synced prices on
 * every run, and the integration tests that seed fixtures would run against real
 * catalog rows. Tests now use their own database, which is rebuilt from
 * `migrations/install.sql` the first time it is needed.
 *
 * Settings resolve from the real environment when PHPUnit is given them, otherwise
 * from the project `.env`, without writing to `$_ENV` (PHPUnit shares it across the
 * whole suite in one process, so assigning it would silently affect other tests).
 */
final class TestDatabase
{
    public const DEFAULT_SUFFIX = '_test';

    /**
     * @return array{host: string, port: int, name: string, user: string, password: string}
     */
    public static function settings(): array
    {
        $values = array_replace(self::fromEnvFile(), self::fromEnvironment());

        $baseName = ($values['TPV_DB_NAME'] ?? null) ?: 'venpostpv';
        $testName = ($values['TPV_DB_TEST_NAME'] ?? null) ?: $baseName . self::DEFAULT_SUFFIX;
        self::assertSafeTestDatabaseName($baseName, $testName);

        return [
            'host' => ($values['TPV_DB_HOST'] ?? null) ?: '127.0.0.1',
            'port' => (int) (($values['TPV_DB_PORT'] ?? null) ?: 3306),
            'name' => $testName,
            'user' => ($values['TPV_DB_USER'] ?? null) ?: 'tpv_user',
            'password' => $values['TPV_DB_PASSWORD'] ?? '',
        ];
    }

    /**
     * Connects to the dedicated test database, creating it from
     * `migrations/install.sql` when it does not exist yet. A missing MySQL server
     * returns null so integration tests can report their unavailable dependency.
     * Once MySQL is reachable, every database or schema setup error is surfaced:
     * silently skipping after partial DDL could make fixtures unsafe or misleading.
     */
    public static function connection(): ?Connection
    {
        $settings = self::settings();

        try {
            $server = self::makeConnection([
                'host' => $settings['host'],
                'port' => $settings['port'],
                'user' => $settings['user'],
                'password' => $settings['password'],
            ]);
            $server->executeQuery('SELECT 1');
        } catch (\Throwable) {
            return null;
        }

        $connection = null;
        $prepared = false;
        try {
            $server->executeStatement(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $settings['name']
            ));
            $connection = self::makeConnection($settings);
            $connection->executeQuery('SELECT 1');
            self::ensureCurrentSchema($connection);
            $prepared = true;

            return $connection;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Unable to prepare the isolated test database.', 0, $e);
        } finally {
            $server->close();
            if (!$prepared && $connection !== null && $connection->isConnected()) {
                $connection->close();
            }
        }
    }

    /**
     * @param array{host: string, port: int, name?: string, user: string, password: string} $settings
     */
    private static function makeConnection(array $settings): Connection
    {
        $parameters = [
            'driver' => 'pdo_mysql',
            'host' => $settings['host'],
            'port' => $settings['port'],
            'user' => $settings['user'],
            'password' => $settings['password'],
            'charset' => 'utf8mb4',
        ];

        if (isset($settings['name'])) {
            $parameters['dbname'] = $settings['name'];
        }

        return DriverManager::getConnection($parameters);
    }

    private static function ensureCurrentSchema(Connection $connection): void
    {
        $schemaManager = $connection->createSchemaManager();
        if (!$schemaManager->tablesExist([
            'catalog_category',
            'catalog_product',
            'catalog_combination',
            'catalog_stock',
        ])) {
            self::applyInstallSchema($connection);
        }

        self::runMigration($connection, 'Version00000003CreateCatalogTables');

        if (!$connection->createSchemaManager()->introspectTable('catalog_product')->hasColumn('image_url')) {
            self::runMigration($connection, 'Version00000006AddImageUrlToCatalogProduct');
        }

        self::runMigration($connection, 'Version00000007AddCombinationAttributeTable');
        self::runMigration($connection, 'Version00000010CreateSyncLogTable');
        self::runMigration($connection, 'Version00000017AddPriceTtcToCatalogTables');
        self::runMigration($connection, 'Version00000018AddPriceNetToCatalogTables');

        if (!$connection->createSchemaManager()->introspectTable('order_meta')->hasColumn('refunded_amount')) {
            self::runMigration($connection, 'Version00000005AddRefundedAmountToOrderMeta');
        }

        self::runMigration($connection, 'Version00000019CreateRefundTables');
        self::runMigration($connection, 'Version00000020CreateCreditNoteNumbering');
        self::runMigration($connection, 'Version00000021AddRefundWindowToTerminal');
        self::runMigration($connection, 'Version00000025AddTicketCodeToTerminal');
        self::runMigration($connection, 'Version00000022AddVoucherToRefund');
        self::runMigration($connection, 'Version00000024CreateExchangeTable');
        self::runMigration($connection, 'Version00000026AddVoucherToExchange');
        self::runMigration($connection, 'Version00000027AddOnlineOrderToRefund');
        self::runMigration($connection, 'Version00000028AddIssuerToTerminal');
        self::runMigration($connection, 'Version00000029AddStoreNameToTerminal');
        self::runMigration($connection, 'Version00000030AddTicketNumbering');
        self::runMigration($connection, 'Version00000031LocalFirstOrders');
    }

    private static function applyInstallSchema(Connection $connection): void
    {
        $schemaFile = \dirname(__DIR__, 2) . '/migrations/install.sql';
        if (!is_file($schemaFile)) {
            throw new \RuntimeException('Test database install schema is missing.');
        }

        $sql = file_get_contents($schemaFile);
        if ($sql === false) {
            throw new \RuntimeException('Test database install schema cannot be read.');
        }

        // install.sql is a list of independent statements separated by semicolons at
        // the end of a line; PDO cannot run them in one call.
        foreach (preg_split('/;\s*\n/', $sql) ?: [] as $statement) {
            // Comment lines precede most statements in install.sql, so they are
            // stripped from the chunk instead of causing the chunk to be skipped.
            $lines = [];
            foreach (preg_split('/\R/', $statement) ?: [] as $line) {
                if (!str_starts_with(ltrim($line), '--')) {
                    $lines[] = $line;
                }
            }

            $statement = trim(implode("\n", $lines));
            if ($statement !== '') {
                $connection->executeStatement($statement);
            }
        }
    }

    private static function runMigration(Connection $connection, string $version): void
    {
        $migrationFile = \dirname(__DIR__, 2) . '/migrations/' . $version . '.php';
        if (!is_file($migrationFile)) {
            throw new \RuntimeException(sprintf('Required test database migration %s is missing.', $version));
        }

        // Doctrine Migrations discovers these files with require_once; they are not
        // in this project's Composer PSR-4 map.
        require_once $migrationFile;

        $class = 'VenPosTPV\\Migrations\\' . $version;
        if (!is_a($class, AbstractMigration::class, true)) {
            throw new \RuntimeException(sprintf('Required test database migration %s is invalid.', $version));
        }

        /** @var AbstractMigration $migration */
        $migration = new $class($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement((string) $query);
        }
    }

    private static function assertSafeTestDatabaseName(string $baseName, string $testName): void
    {
        if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $testName)) {
            throw new \InvalidArgumentException('Test database name must be a safe SQL identifier.');
        }

        // MySQL may resolve database names case-insensitively. Reject aliases on
        // every platform before connecting, even when this server is case-sensitive.
        if (strcasecmp($testName, $baseName) === 0) {
            throw new \InvalidArgumentException('Test database name must differ from the base database.');
        }

        if (!str_ends_with($testName, self::DEFAULT_SUFFIX)) {
            throw new \InvalidArgumentException(sprintf('Test database name must end in "%s".', self::DEFAULT_SUFFIX));
        }
    }

    /**
     * @return array<string, string>
     */
    private static function fromEnvironment(): array
    {
        $values = [];
        foreach ([
            'TPV_DB_HOST',
            'TPV_DB_PORT',
            'TPV_DB_NAME',
            'TPV_DB_USER',
            'TPV_DB_PASSWORD',
            'TPV_DB_TEST_NAME',
        ] as $key) {
            if (isset($_ENV[$key])) {
                $values[$key] = (string) $_ENV[$key];
            }
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private static function fromEnvFile(): array
    {
        $envFile = \dirname(__DIR__, 2) . '/.env';
        if (!is_file($envFile)) {
            return [];
        }

        $values = [];
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (!preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $matches)) {
                continue;
            }
            if (str_starts_with($matches[1], 'TPV_DB_')) {
                $values[$matches[1]] = trim($matches[2], "'\"");
            }
        }

        return $values;
    }
}
