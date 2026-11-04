<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Install;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;

/**
 * First-run web installer: validates the form, checks the database, runs the
 * migrations and writes .env. It refuses to run once .env or the lock exists,
 * and only accepts a one-time key stored outside the web root.
 */
final class WebInstaller
{
    public const KEY_FILE = 'INSTALL_KEY.txt';
    public const LOCK_FILE = 'var/install.lock';
    private const MIGRATIONS_TABLE = 'doctrine_migration_versions';

    public function __construct(
        private readonly string $projectDir,
        private readonly EnvFileWriter $envWriter = new EnvFileWriter(),
    ) {
    }

    public function isLocked(): bool
    {
        return is_file($this->projectDir . '/.env') || is_file($this->projectDir . '/' . self::LOCK_FILE);
    }

    /**
     * Creates the one-time key file on first use. The key never appears in
     * the page: the owner reads it from the hosting file manager.
     */
    public function ensureInstallKey(): void
    {
        $file = $this->projectDir . '/' . self::KEY_FILE;
        if (is_file($file)) {
            return;
        }
        $key = strtoupper(bin2hex(random_bytes(10)));
        if (@file_put_contents($file, $key . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo crear ' . self::KEY_FILE . ': revisa los permisos de la carpeta del TPV.');
        }
        @chmod($file, 0600);
    }

    public function checkInstallKey(string $given): bool
    {
        $file = $this->projectDir . '/' . self::KEY_FILE;
        $expected = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $expected !== '' && hash_equals($expected, strtoupper(trim($given)));
    }

    /**
     * @param array<string, mixed> $input raw form fields
     *
     * @return array{errors: list<string>, values: array<string, string>}
     */
    public function validate(array $input): array
    {
        $get = static fn (string $k, string $default = ''): string => trim((string) ($input[$k] ?? $default));
        $errors = [];

        $tpvUrl = rtrim($get('tpv_url'), '/');
        if (!$this->isAllowedUrl($tpvUrl)) {
            $errors[] = 'La dirección del TPV debe ser una URL https:// válida.';
        }
        $psUrl = rtrim($get('ps_url'), '/');
        if (!$this->isAllowedUrl($psUrl)) {
            $errors[] = 'La dirección de PrestaShop debe ser una URL https:// válida.';
        }
        foreach (['db_host' => 'el servidor', 'db_name' => 'el nombre', 'db_user' => 'el usuario'] as $k => $label) {
            if ($get($k) === '') {
                $errors[] = "Falta {$label} de la base de datos.";
            }
        }
        $port = $get('db_port', '3306');
        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            $errors[] = 'El puerto de la base de datos debe ser un número entre 1 y 65535.';
        }
        if (\strlen($get('admin_user')) < 3) {
            $errors[] = 'El usuario administrador debe tener al menos 3 caracteres.';
        }
        if (\strlen((string) ($input['admin_password'] ?? '')) < 12) {
            $errors[] = 'La contraseña del administrador debe tener al menos 12 caracteres.';
        }
        if ($get('store_name') === '') {
            $errors[] = 'Falta el nombre de la tienda.';
        }
        if ($get('ps_api_key') === '') {
            $errors[] = 'Falta la clave del webservice de PrestaShop.';
        }
        $sso = $get('sso_secret');
        if ($sso !== '' && \strlen($sso) < 32) {
            $errors[] = 'El secreto SSO del módulo debe tener al menos 32 caracteres.';
        }
        foreach (['ps_currency' => 'La moneda', 'ps_shop' => 'La tienda'] as $k => $label) {
            $v = $get($k, '1');
            if (!ctype_digit($v) || (int) $v < 1) {
                $errors[] = "{$label} de PrestaShop debe ser un número de 1 en adelante.";
            }
        }
        $tz = $get('timezone', 'Europe/Madrid');
        if (!\in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            $errors[] = 'La zona horaria no es válida.';
        }

        $values = [
            'APP_ENV' => 'prod',
            'APP_DEBUG' => '0',
            'TPV_URL' => $tpvUrl,
            // URL path prefix of the app; empty when it owns the whole (sub)domain.
            'TPV_BASE_PATH' => (string) parse_url($tpvUrl, PHP_URL_PATH),
            'APP_ADMIN_USER' => $get('admin_user'),
            // Passwords are kept exactly as typed (no trim).
            'APP_ADMIN_PASSWORD' => (string) ($input['admin_password'] ?? ''),
            'APP_SECRET' => bin2hex(random_bytes(32)),
            'JWT_SECRET' => bin2hex(random_bytes(32)),
            'SSO_SHARED_SECRET' => $sso,
            'TPV_DB_HOST' => $get('db_host'),
            'TPV_DB_PORT' => $port,
            'TPV_DB_NAME' => $get('db_name'),
            'TPV_DB_USER' => $get('db_user'),
            'TPV_DB_PASSWORD' => (string) ($input['db_password'] ?? ''),
            'TPV_STORE_NAME' => $get('store_name'),
            'TPV_STORE_ADDRESS' => $get('store_address'),
            'PS_SHOP_URL' => $psUrl,
            'PS_API_KEY' => $get('ps_api_key'),
            'PS_DEFAULT_CURRENCY' => $get('ps_currency', '1'),
            'PS_DEFAULT_SHOP' => $get('ps_shop', '1'),
            'PS_SHOP_TIMEZONE' => $tz,
            'TPV_COUPON_CHECKOUT_ENABLED' => '0',
            'MESSENGER_TRANSPORT_DSN' => 'doctrine://default',
            'TRUSTED_PROXIES' => '127.0.0.1,::1',
        ];

        foreach ($values as $value) {
            if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
                $errors[] = 'Ningún campo puede contener saltos de línea.';
                break;
            }
        }

        return ['errors' => $errors, 'values' => $values];
    }

    /**
     * @param array<string, string> $values output of validate()
     */
    public function connect(array $values): Connection
    {
        $conn = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => $values['TPV_DB_HOST'],
            'port' => (int) $values['TPV_DB_PORT'],
            'dbname' => $values['TPV_DB_NAME'],
            'user' => $values['TPV_DB_USER'],
            'password' => $values['TPV_DB_PASSWORD'],
            'charset' => 'utf8mb4',
        ]);
        $conn->executeQuery('SELECT 1');

        return $conn;
    }

    /**
     * A database with tables but no migration history belongs to something
     * else (for example PrestaShop): never migrate into it.
     */
    public function assertDatabaseUsable(Connection $conn): void
    {
        $tables = $conn->createSchemaManager()->listTableNames();
        if ($tables !== [] && !\in_array(self::MIGRATIONS_TABLE, $tables, true)) {
            throw new \RuntimeException(sprintf(
                'La base de datos ya tiene %d tablas y no son del TPV. Usa una base de datos vacía, creada solo para el TPV.',
                \count($tables)
            ));
        }
    }

    /**
     * @return int number of migrations executed
     */
    public function migrate(Connection $conn): int
    {
        $configuration = new Configuration();
        $configuration->addMigrationsDirectory('VenPosTPV\\Migrations', $this->projectDir . '/migrations');
        $configuration->setAllOrNothing(true);
        $configuration->setCheckDatabasePlatform(false);

        $factory = DependencyFactory::fromConnection(
            new ExistingConfiguration($configuration),
            new ExistingConnection($conn)
        );
        $factory->getMetadataStorage()->ensureInitialized();

        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion(
            $factory->getVersionAliasResolver()->resolveVersionAlias('latest')
        );
        if (\count($plan) === 0) {
            return 0;
        }
        $factory->getMigrator()->migrate($plan, (new MigratorConfiguration())->setAllOrNothing(true));

        return \count($plan);
    }

    /**
     * Writes .env atomically, then the lock, then removes the one-time key.
     *
     * @param array<string, string> $values
     */
    public function finish(array $values): void
    {
        $env = $this->projectDir . '/.env';
        $tmp = $env . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $this->envWriter->render($values), LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo escribir el archivo .env: revisa los permisos de la carpeta del TPV.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $env)) {
            @unlink($tmp);
            throw new \RuntimeException('No se pudo crear el archivo .env.');
        }

        $lockDir = \dirname($this->projectDir . '/' . self::LOCK_FILE);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }
        @file_put_contents($this->projectDir . '/' . self::LOCK_FILE, gmdate('c') . "\n");
        @unlink($this->projectDir . '/' . self::KEY_FILE);
    }

    private function isAllowedUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $scheme === 'https' || ($scheme === 'http' && \in_array($host, ['localhost', '127.0.0.1'], true));
    }
}
