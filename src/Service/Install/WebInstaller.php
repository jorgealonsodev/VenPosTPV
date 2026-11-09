<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Install;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Dotenv\Dotenv;
use VenPosTPV\Kernel;
use VenPosTPV\Service\PrestaShop\PairingClient;
use VenPosTPV\Service\PrestaShop\PairingException;
use VenPosTPV\Service\Provisioning\ProvisioningReport;
use VenPosTPV\Service\Provisioning\ProvisioningRunner;

/**
 * First-run web installer: validates the form, checks the database, runs the
 * migrations, writes .env and then runs the provisioning steps. It refuses to run once .env or the lock exists,
 * and only accepts a one-time key stored outside the web root.
 */
final class WebInstaller
{
    // No extension: nginx may serve .txt and other static files straight from the
    // document root, without passing through .htaccess.
    public const KEY_FILE = 'INSTALL_KEY';
    public const ROOT_HTACCESS_MARKER = '# VenPosTPV: forward-to-public';
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
     * The web server may serve public/ itself, or this folder when its .htaccess
     * forwards every request into public/ (deploy/hosting/root.htaccess). Anything
     * else could expose .env, vendor/ or the install key.
     */
    public function isSafeDocumentRoot(string $documentRoot): bool
    {
        $docRoot = realpath($documentRoot);
        if ($documentRoot === '' || $docRoot === false) {
            return false;
        }
        if ($docRoot === realpath($this->projectDir . '/public')) {
            return true;
        }
        if ($docRoot !== realpath($this->projectDir)) {
            return false;
        }
        $htaccess = @file_get_contents($this->projectDir . '/.htaccess');

        return \is_string($htaccess) && str_starts_with($htaccess, self::ROOT_HTACCESS_MARKER);
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
            throw new InstallException('No se pudo crear ' . self::KEY_FILE . ': revisa los permisos de la carpeta del TPV.');
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
     * The PrestaShop section has two modes: "pair" (the form's default: the shop
     * address and the code the TPV Connect module shows; pair() then asks the shop
     * for the keys) and "manual" (the keys typed by hand). Without ps_mode the
     * input is manual, as before pairing existed.
     *
     * @param array<string, mixed> $input raw form fields
     *
     * @return array{errors: list<string>, values: array<string, string>, pairing: array{shop_url: string, code: string}|null}
     */
    public function validate(array $input): array
    {
        $get = static fn (string $k, string $default = ''): string => trim((string) ($input[$k] ?? $default));
        $errors = [];
        $pairing = ($input['ps_mode'] ?? 'manual') === 'pair';

        $tpvUrl = rtrim($get('tpv_url'), '/');
        if (!$this->isAllowedUrl($tpvUrl)) {
            $errors[] = 'La dirección del TPV debe ser una URL https:// válida.';
        }
        $psUrl = rtrim($get('ps_url'), '/');
        if (!$this->isAllowedUrl($psUrl)) {
            $errors[] = 'La dirección de PrestaShop debe ser una URL https:// válida.';
        }
        $code = $pairing ? PairingClient::normalizeCode($get('pair_code')) : null;
        if ($pairing && $code === null) {
            $errors[] = 'El código de emparejamiento tiene 6 cifras. Pulsa «Emparejar con un TPV» en el módulo TPV Connect de PrestaShop para ver uno.';
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
        if (!$pairing && $get('ps_api_key') === '') {
            $errors[] = 'Falta la clave del webservice de PrestaShop.';
        }
        // Pairing: the shop gives the keys, the ids and the timezone (pair()).
        $sso = $pairing ? '' : $get('sso_secret');
        if ($sso !== '' && \strlen($sso) < 32) {
            $errors[] = 'El secreto SSO del módulo debe tener al menos 32 caracteres.';
        }
        foreach (['ps_currency' => 'La moneda', 'ps_shop' => 'La tienda'] as $k => $label) {
            $v = $pairing ? '1' : $get($k, '1');
            if (!ctype_digit($v) || (int) $v < 1) {
                $errors[] = "{$label} de PrestaShop debe ser un número de 1 en adelante.";
            }
        }
        $tz = $pairing ? 'Europe/Madrid' : $get('timezone', 'Europe/Madrid');
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
            'PS_API_KEY' => $pairing ? '' : $get('ps_api_key'),
            'PS_DEFAULT_CURRENCY' => $pairing ? '1' : $get('ps_currency', '1'),
            'PS_DEFAULT_SHOP' => $pairing ? '1' : $get('ps_shop', '1'),
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

        return [
            'errors' => $errors,
            'values' => $values,
            'pairing' => $pairing && $code !== null ? ['shop_url' => $psUrl, 'code' => $code] : null,
        ];
    }

    /**
     * Pairs with the shop (TPV Connect's code): the shop stores this TPV's address
     * and answers its keys and ids, which replace the PrestaShop values of the
     * .env to write. Nothing is written here.
     *
     * @param array<string, string>                   $values  output of validate()
     * @param array{shop_url: string, code: string}   $pairing output of validate()
     *
     * @return array<string, string>
     *
     * @throws InstallException with the reason, in Spanish
     */
    public function pair(array $values, array $pairing, PairingClient $client): array
    {
        try {
            $result = $client->pair($pairing['shop_url'], $pairing['code'], $values['TPV_URL']);
        } catch (PairingException $e) {
            throw new InstallException($e->getMessage(), 0, $e);
        }

        return array_replace($values, $result->envValues());
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
            throw new InstallException(sprintf(
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
        return (new MigrationRunner($this->projectDir . '/migrations'))->migrate($conn);
    }

    /**
     * Runs the provisioning steps (docs/provisioning.md) once the install is
     * written. Nothing here may fail the install: a step that needs the shop
     * (the module is not installed yet) stays pending with its reason and the
     * minute task completes it later; a runner that cannot even start returns null.
     *
     * @param (\Closure(): ProvisioningRunner)|null $runnerFactory the default boots the installed app
     */
    public function provision(?\Closure $runnerFactory = null): ?ProvisioningReport
    {
        try {
            return ($runnerFactory ?? $this->installedRunner(...))()->run();
        } catch (\Throwable $e) {
            error_log('TPV installer provisioning: ' . $e::class . ': ' . $e->getMessage());

            return null;
        }
    }

    /** The runner of the app as installed: the .env just written, production kernel. */
    private function installedRunner(): ProvisioningRunner
    {
        (new Dotenv())->bootEnv($this->projectDir . '/.env');
        $kernel = new Kernel((string) ($_SERVER['APP_ENV'] ?? 'prod'), false);
        $kernel->boot();
        $runner = $kernel->getContainer()->get(ProvisioningRunner::class);
        if (!$runner instanceof ProvisioningRunner) {
            throw new \LogicException('The provisioning runner is not available.');
        }

        return $runner;
    }

    /**
     * Writes .env (never over an existing one), then the lock, then removes the
     * one-time key.
     *
     * @param array<string, string> $values
     */
    public function finish(array $values): void
    {
        $env = $this->projectDir . '/.env';
        $content = $this->envWriter->render($values);
        // Mode 'x' fails when .env already exists, so a concurrent install never
        // replaces it; it needs nothing a shared host may disable (unlike link()).
        $handle = @fopen($env, 'x');
        if ($handle === false) {
            throw new InstallException(is_file($env)
                ? 'Ya existe un archivo .env: el TPV ya está instalado. Para reinstalar, sigue los pasos de docs/despliegue-hosting.md.'
                : 'No se pudo crear el archivo .env: revisa los permisos de la carpeta del TPV.');
        }
        @chmod($env, 0600);
        $written = @fwrite($handle, $content);
        @fclose($handle);
        if ($written !== \strlen($content)) {
            @unlink($env);
            throw new InstallException('No se pudo escribir el archivo .env: revisa el espacio y los permisos de la carpeta del TPV.');
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
