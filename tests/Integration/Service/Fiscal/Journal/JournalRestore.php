<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use VenPosTPV\Service\Backup\SqlDumper;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * A real backup and restore of the test database: dumped with `mysqldump` and
 * loaded with the `mysql` client of the test container into a database of its own
 * (TPV_TEST_MYSQL_CONTAINER, TPV_TEST_MYSQL_ROOT_PASSWORD).
 */
final class JournalRestore
{
    public static function container(): string
    {
        return getenv('TPV_TEST_MYSQL_CONTAINER') ?: 'venpos-test-mysql';
    }

    public static function available(): bool
    {
        exec(sprintf('docker exec %s mysql --version 2>/dev/null', escapeshellarg(self::container())), $out, $code);

        return $code === 0;
    }

    /**
     * A dump of the test database with `mysqldump` of the container, so the restore
     * is exact and the comparisons only see what the replay did (the project's own
     * dump: dumpWithSqlDumper).
     *
     * @return string the path of the dump (the caller deletes it)
     */
    public static function dump(Connection $connection): string
    {
        $settings = TestDatabase::settings();
        $path = sys_get_temp_dir() . '/venpos-journal-replay-' . bin2hex(random_bytes(4)) . '.sql';
        exec(sprintf(
            'docker exec %s mysqldump -u%s -p%s --single-transaction --skip-triggers %s > %s 2>/dev/null',
            escapeshellarg(self::container()),
            escapeshellarg($settings['user']),
            escapeshellarg($settings['password']),
            escapeshellarg((string) $connection->getDatabase()),
            escapeshellarg($path)
        ), $out, $code);
        if ($code !== 0 || !is_file($path) || filesize($path) === 0) {
            throw new \RuntimeException('mysqldump failed');
        }
        // The triggers (append-only fiscal_record), as SqlDumper writes them: without DEFINER.
        $triggers = '';
        foreach ($connection->fetchAllAssociative('SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()') as $trigger) {
            $create = $connection->fetchAssociative(sprintf('SHOW CREATE TRIGGER `%s`', $trigger['name']));
            $triggers .= SqlDumper::triggerStatement((string) $trigger['name'], (string) ($create['SQL Original Statement'] ?? ''));
        }
        file_put_contents($path, $triggers, \FILE_APPEND);

        return $path;
    }

    /**
     * A dump of the test database with the project's own SqlDumper, as the nightly
     * backup (tpv:backup:database) writes it.
     *
     * @return string the path of the dump (the caller deletes it)
     */
    public static function dumpWithSqlDumper(Connection $connection): string
    {
        $path = sys_get_temp_dir() . '/venpos-journal-sqldumper-' . bin2hex(random_bytes(4)) . '.sql';
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot write ' . $path);
        }
        try {
            (new SqlDumper($connection))->dump(static function (string $part) use ($handle): void {
                fwrite($handle, $part);
            });
        } finally {
            fclose($handle);
        }

        return $path;
    }

    /** Loads the dump into a fresh database $name and connects to it. */
    public static function restore(string $dump, string $name): Connection
    {
        $settings = TestDatabase::settings();
        [$code, $output] = self::root(sprintf(
            "DROP DATABASE IF EXISTS %1\$s; CREATE DATABASE %1\$s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON %1\$s.* TO '%2\$s'@'%%'",
            $name,
            $settings['user']
        ));
        if ($code !== 0) {
            throw new \RuntimeException('Cannot create ' . $name . ': ' . $output);
        }
        exec(sprintf(
            'docker exec -i %s mysql -u%s -p%s %s < %s 2>&1',
            escapeshellarg(self::container()),
            escapeshellarg($settings['user']),
            escapeshellarg($settings['password']),
            $name,
            escapeshellarg($dump)
        ), $out, $code);
        if ($code !== 0) {
            throw new \RuntimeException('mysql import failed: ' . implode("\n", $out));
        }

        return self::connect($name);
    }

    public static function connect(string $name): Connection
    {
        $settings = TestDatabase::settings();

        return DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => $settings['host'],
            'port' => $settings['port'],
            'user' => $settings['user'],
            'password' => $settings['password'],
            'dbname' => $name,
            'charset' => 'utf8mb4',
        ]);
    }

    public static function drop(string $name): void
    {
        if (self::available()) {
            self::root(sprintf('DROP DATABASE IF EXISTS %s', $name));
        }
    }

    /** @return array{0: int, 1: string} exit code and output */
    private static function root(string $sql): array
    {
        exec(sprintf(
            'docker exec %s mysql -uroot -p%s -e %s 2>&1',
            escapeshellarg(self::container()),
            escapeshellarg(getenv('TPV_TEST_MYSQL_ROOT_PASSWORD') ?: 'testroot'),
            escapeshellarg($sql)
        ), $out, $code);

        return [$code, implode("\n", $out)];
    }
}
