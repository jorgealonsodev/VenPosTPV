<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Install;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\PrestaShop\PairingClient;
use VenPosTPV\Service\Install\InstallException;
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
        foreach (['.env', WebInstaller::KEY_FILE, WebInstaller::LOCK_FILE, '.htaccess'] as $f) {
            @unlink($this->dir . '/' . $f);
        }
        @rmdir($this->dir . '/var');
        @rmdir($this->dir . '/public');
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
        // The bundled module knows coupon_preview: coupon checkout is on from the start.
        self::assertSame('1', $v['TPV_COUPON_CHECKOUT_ENABLED']);
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

    public function testFinishNeverOverwritesAnEnvThatAppearedMeanwhile(): void
    {
        $installer = new WebInstaller($this->dir);
        $installer->ensureInstallKey();
        file_put_contents($this->dir . '/.env', "APP_SECRET=keep\n");

        try {
            $installer->finish($installer->validate($this->validInput())['values']);
            self::fail('An existing .env must not be replaced.');
        } catch (InstallException $e) {
            self::assertStringContainsString('.env', $e->getMessage());
        }

        self::assertSame("APP_SECRET=keep\n", file_get_contents($this->dir . '/.env'));
        self::assertSame([], glob($this->dir . '/.env.tmp-*'));
        self::assertFileExists($this->dir . '/' . WebInstaller::KEY_FILE);
    }

    public function testInstallerErrorsAreInstallExceptions(): void
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $conn->executeStatement('CREATE TABLE ps_orders (id INTEGER)');

        $this->expectException(InstallException::class);
        (new WebInstaller($this->dir))->assertDatabaseUsable($conn);
    }

    public function testDocumentRootMayBePublicOrTheProjectWhenItsHtaccessForwardsToPublic(): void
    {
        mkdir($this->dir . '/public');
        $installer = new WebInstaller($this->dir);

        self::assertTrue($installer->isSafeDocumentRoot($this->dir . '/public'));
        self::assertFalse($installer->isSafeDocumentRoot($this->dir), 'without the forwarding .htaccess, .env and vendor/ would be served');
        self::assertFalse($installer->isSafeDocumentRoot(''));
        self::assertFalse($installer->isSafeDocumentRoot(sys_get_temp_dir()));

        file_put_contents($this->dir . '/.htaccess', "# something else\nOptions -Indexes\n");
        self::assertFalse($installer->isSafeDocumentRoot($this->dir));

        copy(\dirname(__DIR__, 4) . '/deploy/hosting/root.htaccess', $this->dir . '/.htaccess');
        self::assertTrue($installer->isSafeDocumentRoot($this->dir));
    }

    public function testTheInstallKeyFileHasNoStaticFileExtension(): void
    {
        // nginx may serve .txt and other static extensions without passing through .htaccess.
        self::assertSame('', pathinfo(WebInstaller::KEY_FILE, PATHINFO_EXTENSION));
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

    /** @return array<string, string> the form as the pairing path sends it */
    private function pairingInput(): array
    {
        $input = $this->validInput();
        unset($input['ps_api_key'], $input['sso_secret'], $input['ps_currency'], $input['ps_shop'], $input['timezone']);

        return ['ps_mode' => 'pair', 'ps_url' => 'https://shop.example.com/', 'pair_code' => '123 456'] + $input;
    }

    private static function pairingHttp(array &$requests = []): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['url' => $url, 'body' => json_decode($options['body'], true)];

            return new MockResponse((string) json_encode([
                'api_key' => 'PAIREDKEY0123456789ABCDEF0123456', 'sso_secret' => str_repeat('f', 64),
                'shop_id' => 3, 'shop_url' => 'https://shop.example.com', 'currency_id' => 2,
                'timezone' => 'Atlantic/Canary', 'module_version' => '0.8.0',
            ]), ['http_code' => 200]);
        });
    }

    public function testThePairingPathNeedsTheShopAddressAndTheCodeButNoKeys(): void
    {
        $result = (new WebInstaller($this->dir))->validate($this->pairingInput());

        self::assertSame([], $result['errors']);
        self::assertSame(['shop_url' => 'https://shop.example.com', 'code' => '123456'], $result['pairing']);
    }

    public function testThePairingPathRefusesAMissingOrMalformedCode(): void
    {
        $input = ['pair_code' => '12a'] + $this->pairingInput();

        self::assertContains('El código de emparejamiento tiene 6 cifras. Pulsa «Emparejar con un TPV» en el módulo TPV Connect de PrestaShop para ver uno.', (new WebInstaller($this->dir))->validate($input)['errors']);
    }

    public function testPairingFillsTheShopValuesOfTheEnvItself(): void
    {
        $installer = new WebInstaller($this->dir);
        $installer->ensureInstallKey();
        $result = $installer->validate($this->pairingInput());
        $requests = [];

        $values = $installer->pair($result['values'], $result['pairing'], new PairingClient(self::pairingHttp($requests)));
        $installer->finish($values);

        self::assertSame('https://shop.example.com/module/tpvconnect/pair', $requests[0]['url']);
        self::assertSame(['code' => '123456', 'tpv_url' => 'https://tpv.example.com'], $requests[0]['body']);
        $env = (new Dotenv())->parse((string) file_get_contents($this->dir . '/.env'));
        self::assertSame('https://shop.example.com', $env['PS_SHOP_URL']);
        self::assertSame('PAIREDKEY0123456789ABCDEF0123456', $env['PS_API_KEY']);
        self::assertSame(str_repeat('f', 64), $env['SSO_SHARED_SECRET']);
        self::assertSame('3', $env['PS_DEFAULT_SHOP']);
        self::assertSame('2', $env['PS_DEFAULT_CURRENCY']);
        self::assertSame('Atlantic/Canary', $env['PS_SHOP_TIMEZONE']);
        self::assertSame('1', $env['TPV_COUPON_CHECKOUT_ENABLED']);
        self::assertSame('prod', $env['APP_ENV']);
        self::assertSame(" p'w\$X ", $env['TPV_DB_PASSWORD']);
    }

    public function testAPairingFailureIsAnInstallErrorAndWritesNothing(): void
    {
        $installer = new WebInstaller($this->dir);
        $result = $installer->validate($this->pairingInput());
        $http = new MockHttpClient([new MockResponse((string) json_encode(['error' => 'invalid_code']), ['http_code' => 403])]);

        try {
            $installer->pair($result['values'], $result['pairing'], new PairingClient($http));
            self::fail('A refused code must stop the install.');
        } catch (InstallException $e) {
            self::assertStringContainsString('no es correcto o ha caducado', $e->getMessage());
        }
        self::assertFileDoesNotExist($this->dir . '/.env');
    }

    public function testTheManualFallbackKeepsTheTypedKeysAndNeedsNoPairing(): void
    {
        $result = (new WebInstaller($this->dir))->validate(['ps_mode' => 'manual', 'pair_code' => ''] + $this->validInput());

        self::assertSame([], $result['errors']);
        self::assertNull($result['pairing']);
        self::assertSame('KEY', $result['values']['PS_API_KEY']);
        self::assertSame('https://shop.example.com', $result['values']['PS_SHOP_URL']);
    }

    public function testTheManualFallbackStillNeedsTheWebserviceKey(): void
    {
        $input = ['ps_mode' => 'manual', 'ps_api_key' => ''] + $this->validInput();

        self::assertContains('Falta la clave del webservice de PrestaShop.', (new WebInstaller($this->dir))->validate($input)['errors']);
    }

    public function testTheInstallPageDefaultsToPairingAndOffersTheManualLink(): void
    {
        $page = (string) file_get_contents(\dirname(__DIR__, 4) . '/public/install.php');

        self::assertStringContainsString('Dirección de la tienda', $page);
        self::assertStringContainsString('Código de emparejamiento', $page);
        self::assertStringContainsString('Introducir las claves a mano', $page);
        self::assertStringContainsString('name="pair_code"', $page);
        self::assertMatchesRegularExpression('/value="pair"[^>]*\'\s*\.\s*\(\$mode === \'pair\' \? \' checked\'/', $page, 'pairing is the default mode');
        self::assertStringNotContainsString('copia su secreto SSO en', $page, 'the final page no longer asks to paste the SSO secret');
        self::assertStringNotContainsString('pon como URL del TPV', $page, 'the final page no longer asks to type the TPV URL in the module');
    }
}
