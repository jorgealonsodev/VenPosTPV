<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

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
        $values = [
            'TPV_DB_HOST' => $_ENV['TPV_DB_HOST'] ?? null,
            'TPV_DB_PORT' => $_ENV['TPV_DB_PORT'] ?? null,
            'TPV_DB_NAME' => $_ENV['TPV_DB_NAME'] ?? null,
            'TPV_DB_USER' => $_ENV['TPV_DB_USER'] ?? null,
            'TPV_DB_PASSWORD' => $_ENV['TPV_DB_PASSWORD'] ?? null,
            'TPV_DB_TEST_NAME' => $_ENV['TPV_DB_TEST_NAME'] ?? null,
        ];

        if ($values['TPV_DB_NAME'] === null) {
            $values = array_merge($values, self::fromEnvFile());
        }

        $baseName = $values['TPV_DB_NAME'] ?: 'venpostpv';
        $testName = $values['TPV_DB_TEST_NAME'] ?: $baseName . self::DEFAULT_SUFFIX;

        return [
            'host' => $values['TPV_DB_HOST'] ?: '127.0.0.1',
            'port' => (int) ($values['TPV_DB_PORT'] ?: 3306),
            'name' => $testName,
            'user' => $values['TPV_DB_USER'] ?: 'tpv_user',
            'password' => $values['TPV_DB_PASSWORD'] ?? '',
        ];
    }

    /**
     * Connects to the test database, creating it from `migrations/install.sql` when
     * it does not exist yet. Returns null when MySQL is unreachable, so the calling
     * test can skip instead of failing.
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
            $server->executeStatement(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $settings['name']
            ));
            $server->close();

            $connection = self::makeConnection($settings);
            $connection->executeQuery('SELECT 1');

            if (!$connection->createSchemaManager()->tablesExist(['catalog_product'])) {
                self::applyInstallSchema($connection);
            }

            return $connection;
        } catch (\Throwable) {
            return null;
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

    private static function applyInstallSchema(Connection $connection): void
    {
        $schemaFile = \dirname(__DIR__, 2) . '/migrations/install.sql';
        if (!is_file($schemaFile)) {
            return;
        }

        $sql = (string) file_get_contents($schemaFile);

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
            if ($statement === '') {
                continue;
            }

            try {
                $connection->executeStatement($statement);
            } catch (\Throwable) {
                // Tables created by a later migration may already exist; keep going.
            }
        }
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
            if (!str_starts_with($matches[1], 'TPV_DB_')) {
                continue;
            }
            $values[$matches[1]] = trim($matches[2], "'\"");
        }

        return $values;
    }
}
