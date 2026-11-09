<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Provisioning;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Backup\BackupRetention;
use VenPosTPV\Service\Backup\DatabaseBackup;
use VenPosTPV\Service\Backup\DatabaseHealthCheck;
use VenPosTPV\Service\Backup\SqlDumper;
use VenPosTPV\Service\Fiscal\FiscalChainVerifier;
use VenPosTPV\Service\Install\MigrationRunner;
use VenPosTPV\Service\Provisioning\DatabaseLock;
use VenPosTPV\Service\Provisioning\PendingMigrationsStep;
use VenPosTPV\Service\Provisioning\ProvisioningRunner;
use VenPosTPV\Service\Provisioning\ProvisioningStateStore;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * The blocker on real MySQL: a migration whose second ALTER fails leaves its first
 * one applied (MySQL commits DDL) and no version row, so every rerun fails with
 * «1060 Duplicate column name». The minute task must back up once, block, and from
 * then on take no backup and run no migration; the clean pre-migration copy must
 * survive the daily retention. Uses a scratch database venpostpv_fix_… that is
 * dropped afterwards, and scratch folders under the system temp dir.
 */
final class MigrationBlockMySqlIntegrationTest extends TestCase
{
    private ?Connection $server = null;
    private ?Connection $conn = null;
    private string $database = '';
    private string $root = '';
    private string $migrationsDir = '';
    private string $backupsDir = '';
    private string $suffix = '';

    protected function setUp(): void
    {
        $settings = TestDatabase::settings();
        try {
            $this->server = DriverManager::getConnection([
                'driver' => 'pdo_mysql', 'host' => $settings['host'], 'port' => $settings['port'],
                'user' => $settings['user'], 'password' => $settings['password'], 'charset' => 'utf8mb4',
            ]);
            $this->server->executeQuery('SELECT 1');
        } catch (\Throwable) {
            $this->server = null;
            self::markTestSkipped('MySQL is not available.');
        }

        $this->suffix = bin2hex(random_bytes(4));
        $this->database = 'venpostpv_fix_' . $this->suffix;
        $this->server->executeStatement(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->database));
        $this->conn = DriverManager::getConnection([
            'driver' => 'pdo_mysql', 'host' => $settings['host'], 'port' => $settings['port'], 'dbname' => $this->database,
            'user' => $settings['user'], 'password' => $settings['password'], 'charset' => 'utf8mb4',
        ]);

        $this->root = sys_get_temp_dir() . '/venpostpv-fix-' . $this->suffix;
        $this->migrationsDir = $this->root . '/migrations';
        $this->backupsDir = $this->root . '/backups';
        mkdir($this->migrationsDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->conn?->close();
        if ($this->server !== null && $this->database !== '') {
            $this->server->executeStatement(sprintf('DROP DATABASE IF EXISTS `%s`', $this->database));
            $this->server->close();
        }
        if ($this->root !== '' && is_dir($this->root)) {
            foreach ([$this->migrationsDir, $this->backupsDir] as $dir) {
                if (is_dir($dir)) {
                    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $file) {
                        unlink($dir . '/' . $file);
                    }
                    rmdir($dir);
                }
            }
            rmdir($this->root);
        }
    }

    /** @param list<string> $statements */
    private function migration(int $number, array $statements): void
    {
        $class = sprintf('Version%08dFix%s', $number, $this->suffix);
        $sql = implode("\n", array_map(static fn (string $s): string => '        $this->addSql(' . var_export($s, true) . ');', $statements));
        file_put_contents($this->migrationsDir . '/' . $class . '.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace VenPosTPV\\Migrations;

            use Doctrine\\DBAL\\Schema\\Schema;
            use Doctrine\\Migrations\\AbstractMigration;

            final class {$class} extends AbstractMigration
            {
                public function up(Schema \$schema): void
                {
            {$sql}
                }
            }
            PHP);
    }

    private function runner(): ProvisioningRunner
    {
        $backup = new DatabaseBackup(
            new SqlDumper($this->conn),
            new BackupRetention(),
            new DatabaseHealthCheck($this->conn, new FiscalChainVerifier($this->conn)),
            $this->backupsDir,
        );
        $step = new PendingMigrationsStep($this->conn, new MigrationRunner($this->migrationsDir), $backup, new DatabaseLock($this->conn));

        return new ProvisioningRunner([$step], new ProvisioningStateStore($this->conn));
    }

    /** @return list<string> */
    private function backups(): array
    {
        return array_values(preg_grep('/\.sql\.gz\z/', scandir($this->backupsDir) ?: []) ?: []);
    }

    public function testAHalfFailedMigrationBlocksTheMinuteTaskAndItsCleanBackupSurvives(): void
    {
        // The installed release.
        $this->migration(1, [
            'CREATE TABLE app_setting (name VARCHAR(100) NOT NULL PRIMARY KEY, value TEXT NULL) ENGINE=InnoDB',
            'CREATE TABLE ticket (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, total INT NOT NULL) ENGINE=InnoDB',
            'INSERT INTO ticket (total) VALUES (100), (250)',
        ]);
        (new MigrationRunner($this->migrationsDir))->migrate($this->conn);

        // The new release: its second ALTER fails after the first was committed.
        $this->migration(2, [
            'ALTER TABLE ticket ADD ticket_note VARCHAR(255) NULL',
            'ALTER TABLE ticket ADD broken NOT_A_TYPE',
        ]);

        $start = new \DateTimeImmutable('2026-11-09 10:00:00');
        $first = $this->runner()->run(throttled: true, now: $start);

        $this->assertTrue($first->steps[0]->blocked, (string) $first->steps[0]->reason);
        $this->assertCount(1, $this->backups());
        [$clean] = $this->backups();
        $this->assertMatchesRegularExpression('/\Avenpostpv-premigrate-\d{8}-\d{6}\.sql\.gz\z/', $clean);
        $this->assertStringContainsString('La copia previa está en backups/' . $clean . '.', (string) $first->steps[0]->reason);
        $this->assertStringNotContainsString('ticket_note', (string) gzdecode((string) file_get_contents($this->backupsDir . '/' . $clean)), 'taken before the migration');
        $this->assertContains('ticket_note', array_column($this->conn->fetchAllAssociative('SHOW COLUMNS FROM ticket'), 'Field'), 'half migrated');

        for ($i = 1; $i <= 10; ++$i) {
            $report = $this->runner()->run(throttled: true, now: $start->modify(sprintf('+%d minutes', 15 * $i)));
            $this->assertTrue($report->steps[0]->blocked);
            $this->assertFalse($report->steps[0]->attempted);
        }
        $this->assertSame([$clean], $this->backups(), 'ten cron runs later: no new backup');

        // Rerunning by hand still fails (1060) and takes one new pre-migration copy.
        $manual = $this->runner()->run(throttled: false, now: $start->modify('+3 hours'));
        $this->assertTrue($manual->steps[0]->blocked);
        $this->assertStringContainsString('1060', (string) $manual->steps[0]->reason);
        $this->assertCount(2, $this->backups());

        $daily = new DatabaseBackup(new SqlDumper($this->conn), new BackupRetention(), new DatabaseHealthCheck($this->conn, new FiscalChainVerifier($this->conn)), $this->backupsDir);
        for ($day = 10; $day <= 18; ++$day) {
            $daily->run(7, new \DateTimeImmutable(sprintf('2026-11-%02d 04:00:00', $day)));
        }
        $this->assertContains($clean, $this->backups(), 'the daily retention never deletes it');
    }
}
