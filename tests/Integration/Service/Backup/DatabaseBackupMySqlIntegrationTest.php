<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Backup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Backup\BackupRetention;
use VenPosTPV\Service\Backup\DatabaseBackup;
use VenPosTPV\Service\Backup\DatabaseHealthCheck;
use VenPosTPV\Service\Backup\SqlDumper;
use VenPosTPV\Service\Fiscal\FiscalChainVerifier;
use VenPosTPV\Service\Fiscal\FiscalRecordTriggers;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * Dumps the test database, restores the dump with the real `mysql` client into a
 * fresh database and compares both. The restore needs the throwaway MySQL
 * container (TPV_TEST_MYSQL_CONTAINER, root password TPV_TEST_MYSQL_ROOT_PASSWORD).
 */
final class DatabaseBackupMySqlIntegrationTest extends TestCase
{
    private const RESTORE_DB = 'venposbackup_restore';
    private const PROBE = 'backup_probe';

    private ?Connection $connection = null;
    private ?Connection $restored = null;
    private string $dir = '';
    private bool $triggersExisted = false;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        if (!$this->containerAvailable()) {
            $this->markTestSkipped('MySQL test container unavailable for the restore');
        }

        $this->dir = sys_get_temp_dir() . '/venpos-backup-it-' . bin2hex(random_bytes(4)) . '/backups';
        $this->triggersExisted = FiscalRecordTriggers::exist($this->connection);
        $this->connection->executeStatement('DROP TABLE IF EXISTS ' . self::PROBE);
        $this->connection->executeStatement(
            'CREATE TABLE ' . self::PROBE . ' (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                label VARCHAR(255) NULL,
                payload VARBINARY(64) NULL,
                blob_data BLOB NULL,
                created_at TIMESTAMP NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    protected function tearDown(): void
    {
        if ($this->connection === null) {
            return;
        }
        $this->restored?->close();
        $this->root(sprintf('DROP DATABASE IF EXISTS %s', self::RESTORE_DB));
        $this->connection->executeStatement('DROP TABLE IF EXISTS ' . self::PROBE);
        if (!$this->triggersExisted) {
            $this->connection->executeStatement('DROP TRIGGER IF EXISTS fiscal_record_no_update');
            $this->connection->executeStatement('DROP TRIGGER IF EXISTS fiscal_record_no_delete');
        }
        $this->connection->close();
        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach (array_diff(scandir($this->dir) ?: [], ['.', '..']) as $file) {
                unlink($this->dir . '/' . $file);
            }
            rmdir($this->dir);
            rmdir(\dirname($this->dir));
        }
    }

    private static function container(): string
    {
        return getenv('TPV_TEST_MYSQL_CONTAINER') ?: 'venpos-test-mysql';
    }

    private function containerAvailable(): bool
    {
        exec(sprintf('docker exec %s mysql --version 2>/dev/null', escapeshellarg(self::container())), $out, $code);

        return $code === 0;
    }

    private function root(string $sql): void
    {
        $password = getenv('TPV_TEST_MYSQL_ROOT_PASSWORD') ?: 'testroot';
        exec(sprintf(
            'docker exec %s mysql -uroot -p%s -e %s 2>&1',
            escapeshellarg(self::container()),
            escapeshellarg($password),
            escapeshellarg($sql)
        ), $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    private function restore(string $dump): Connection
    {
        $settings = TestDatabase::settings();
        $this->root(sprintf(
            "DROP DATABASE IF EXISTS %1\$s; CREATE DATABASE %1\$s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON %1\$s.* TO '%2\$s'@'%%'",
            self::RESTORE_DB,
            $settings['user']
        ));
        // The import runs as the application user, as it would in Plesk.
        exec(sprintf(
            'gunzip -c %s | docker exec -i %s mysql -u%s -p%s %s 2>&1',
            escapeshellarg($dump),
            escapeshellarg(self::container()),
            escapeshellarg($settings['user']),
            escapeshellarg($settings['password']),
            self::RESTORE_DB
        ), $out, $code);
        $this->assertSame(0, $code, 'mysql import failed: ' . implode("\n", $out));

        return DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => $settings['host'],
            'port' => $settings['port'],
            'user' => $settings['user'],
            'password' => $settings['password'],
            'dbname' => self::RESTORE_DB,
            'charset' => 'utf8mb4',
        ]);
    }

    /** @return list<string> */
    private static function tables(Connection $connection): array
    {
        $tables = [];
        foreach ($connection->fetchAllNumeric("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
            $tables[] = (string) $row[0];
        }
        sort($tables);

        return $tables;
    }

    public function testADumpRestoresTheSameTablesRowsTriggersAndValues(): void
    {
        \assert($this->connection !== null);
        $this->assertTrue(FiscalRecordTriggers::create($this->connection), 'the source needs the fiscal triggers');
        $probes = [
            ['label' => null, 'payload' => null, 'blob_data' => null, 'created_at' => null],
            ['label' => "O'Brien \"quoted\" back\\slash; -- not a comment\n\ttab", 'payload' => "\x00\xff\x27\x5c\x0a\x0d\x1a\"", 'blob_data' => '', 'created_at' => '2026-03-29 02:30:00'],
            ['label' => 'Ñandú pingüino 日本語 🏊‍♀️', 'payload' => random_bytes(64), 'blob_data' => random_bytes(3000), 'created_at' => '2026-11-05 04:00:00'],
        ];
        foreach ($probes as $probe) {
            $this->connection->insert(self::PROBE, $probe);
        }
        // Enough rows to span several INSERT batches and key pages.
        $this->connection->executeStatement(
            'INSERT INTO ' . self::PROBE . " (label, payload)
             WITH RECURSIVE d (i) AS (SELECT 0 UNION ALL SELECT i + 1 FROM d WHERE i < 59)
             SELECT CONCAT('row ', a.i * 60 + b.i), UNHEX(SHA2(a.i * 60 + b.i, 256)) FROM d a, d b WHERE a.i < 44"
        );

        $result = (new DatabaseBackup(
            new SqlDumper($this->connection),
            new BackupRetention(),
            new DatabaseHealthCheck($this->connection, new FiscalChainVerifier($this->connection)),
            $this->dir
        ))->run(7);

        $this->assertSame([], $result->health->problems);
        $this->restored = $this->restore($result->path);
        $sourceTables = self::tables($this->connection);
        $this->assertSame($sourceTables, self::tables($this->restored));
        $this->assertSame(\count($sourceTables), $result->tables);
        foreach ($sourceTables as $table) {
            $this->assertSame(
                (int) $this->connection->fetchOne("SELECT COUNT(*) FROM `$table`"),
                (int) $this->restored->fetchOne("SELECT COUNT(*) FROM `$table`"),
                "row count of $table"
            );
        }
        foreach ([self::PROBE, 'terminal', 'order_meta', 'fiscal_chain', 'app_setting'] as $table) {
            $this->assertSame(
                $this->connection->fetchAssociative("CHECKSUM TABLE `$table`")['Checksum'] ?? 'missing',
                $this->restored->fetchAssociative("CHECKSUM TABLE `$table`")['Checksum'] ?? 'missing',
                "checksum of $table"
            );
        }
        $restoredProbes = $this->restored->fetchAllAssociative('SELECT label, payload, blob_data, created_at FROM ' . self::PROBE . ' ORDER BY id LIMIT 3');
        $this->assertSame($probes, $restoredProbes);
        $this->assertSame(
            (int) $this->connection->fetchOne("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::PROBE . "'"),
            (int) $this->restored->fetchOne("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::PROBE . "'"),
            'the next ids continue from the restored counters'
        );
        $this->assertTrue(FiscalRecordTriggers::exist($this->restored), 'the fiscal_record triggers survive a restore');

        $sql = (string) gzdecode((string) file_get_contents($result->path));
        $this->assertStringContainsString("SET FOREIGN_KEY_CHECKS=0;\n", substr($sql, 0, 500));
        $this->assertStringEndsWith("SET FOREIGN_KEY_CHECKS=1;\n", $sql);
        $this->assertStringNotContainsString('DEFINER', $sql);
    }
}
