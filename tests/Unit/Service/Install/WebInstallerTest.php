<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Install;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use VenPosTPV\Service\Install\WebInstaller;

final class WebInstallerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tpv-installer-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (['.env', WebInstaller::KEY_FILE, WebInstaller::LOCK_FILE] as $f) {
            @unlink($this->dir . '/' . $f);
        }
        @rmdir($this->dir . '/var');
        @rmdir($this->dir);
    }

    /** @return array<string, string> */
    private function validInput(): array
    {
        return [
            'tpv_url' => 'https://tpv.example.com/',
            'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => 'tpv', 'db_user' => 'tpv',
            'db_password' => " p'w\$X ",
            'admin_user' => 'admin', 'admin_password' => 'a-long-password',
            'store_name' => 'Tienda', 'store_address' => '',
            'ps_url' => 'https://shop.example.com', 'ps_api_key' => 'KEY',
            'sso_secret' => '', 'ps_currency' => '1', 'ps_shop' => '1', 'timezone' => 'Europe/Madrid',
        ];
    }

    public function testKeyIsCreatedOnceAndCheckedExactly(): void
    {
        $installer = new WebInstaller($this->dir);
        $installer->ensureInstallKey();
        $key = trim((string) file_get_contents($this->dir . '/' . WebInstaller::KEY_FILE));
        $installer->ensureInstallKey();

        self::assertSame($key, trim((string) file_get_contents($this->dir . '/' . WebInstaller::KEY_FILE)));
        self::assertSame(20, \strlen($key));
        self::assertTrue($installer->checkInstallKey(' ' . strtolower($key) . ' '));
        self::assertFalse($installer->checkInstallKey(''));
        self::assertFalse($installer->checkInstallKey(substr($key, 0, -1)));
    }

    public function testNoKeyFileRejectsEveryKey(): void
    {
        self::assertFalse((new WebInstaller($this->dir))->checkInstallKey(''));
    }

    public function testValidInputBuildsProductionEnvAndKeepsPasswordsVerbatim(): void
    {
        $result = (new WebInstaller($this->dir))->validate($this->validInput());

        self::assertSame([], $result['errors']);
        $v = $result['values'];
        self::assertSame('prod', $v['APP_ENV']);
        self::assertSame('https://tpv.example.com', $v['TPV_URL']);
        self::assertSame('', $v['TPV_BASE_PATH']);
        self::assertSame(" p'w\$X ", $v['TPV_DB_PASSWORD']);
        self::assertSame('127.0.0.1,::1', $v['TRUSTED_PROXIES']);
        self::assertSame(64, \strlen($v['APP_SECRET']));
    }

    public function testBasePathComesFromTheTpvUrlPath(): void
    {
        $input = ['tpv_url' => 'https://example.com/tpv/'] + $this->validInput();

        self::assertSame('/tpv', (new WebInstaller($this->dir))->validate($input)['values']['TPV_BASE_PATH']);
    }

    public function testInvalidInputListsEveryProblem(): void
    {
        $input = ['tpv_url' => 'http://tpv.example.com', 'db_port' => '0', 'admin_password' => 'short', 'sso_secret' => 'tooshort', 'timezone' => 'Mars/Base'];
        $errors = (new WebInstaller($this->dir))->validate($input)['errors'];

        self::assertContains('La dirección del TPV debe ser una URL https:// válida.', $errors);
        self::assertContains('Falta el nombre de la base de datos.', $errors);
        self::assertContains('El puerto de la base de datos debe ser un número entre 1 y 65535.', $errors);
        self::assertContains('La contraseña del administrador debe tener al menos 12 caracteres.', $errors);
        self::assertContains('El secreto SSO del módulo debe tener al menos 32 caracteres.', $errors);
        self::assertContains('La zona horaria no es válida.', $errors);
    }

    public function testLineBreakInAnyFieldIsRejected(): void
    {
        $input = $this->validInput();
        $input['db_password'] = "x\nAPP_ENV=dev";

        self::assertContains('Ningún campo puede contener saltos de línea.', (new WebInstaller($this->dir))->validate($input)['errors']);
    }

    public function testFinishWritesReadableEnvLocksAndRemovesKey(): void
    {
        $installer = new WebInstaller($this->dir);
        $installer->ensureInstallKey();
        self::assertFalse($installer->isLocked());

        $values = $installer->validate($this->validInput())['values'];
        $installer->finish($values);

        self::assertTrue($installer->isLocked());
        self::assertFileExists($this->dir . '/' . WebInstaller::LOCK_FILE);
        self::assertFileDoesNotExist($this->dir . '/' . WebInstaller::KEY_FILE);
        self::assertSame(0600, fileperms($this->dir . '/.env') & 0777);
        self::assertSame($values, (new Dotenv())->parse((string) file_get_contents($this->dir . '/.env')));
    }

    public function testForeignDatabaseIsRefusedAndEmptyOrOwnDatabaseAccepted(): void
    {
        $installer = new WebInstaller($this->dir);
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $installer->assertDatabaseUsable($conn);

        $conn->executeStatement('CREATE TABLE ps_orders (id INTEGER)');
        try {
            $installer->assertDatabaseUsable($conn);
            self::fail('A database with foreign tables must be refused.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('ya tiene 1 tablas y no son del TPV', $e->getMessage());
        }

        $conn->executeStatement('CREATE TABLE doctrine_migration_versions (version VARCHAR(191))');
        $installer->assertDatabaseUsable($conn);
        $this->addToAssertionCount(1);
    }
}
