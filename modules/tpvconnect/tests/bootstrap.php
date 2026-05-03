<?php
/**
 * Test bootstrap for tpvconnect module.
 * Provides minimal PrestaShop stubs for unit testing without PS runtime.
 */

declare(strict_types=1);

define('_PS_VERSION_', '8.1.0');
define('_DB_PREFIX_', 'ps_');
define('_MYSQL_ENGINE_', 'InnoDB');
define('_PS_MODULE_DIR_', __DIR__ . '/../');
define('_PS_CACHE_DIR_', sys_get_temp_dir() . '/');

// Minimal Tools stub
if (!class_exists('Tools', false)) {
    class Tools
    {
        public static function strlen(string $str): int
        {
            return strlen($str);
        }

        public static function htmlentitiesUTF8(string $str): string
        {
            return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
        }

        public static function passwdGen(int $length = 8): string
        {
            return bin2hex(random_bytes((int) ceil($length / 2)));
        }

        public static function getValue(string $key, mixed $default = false): mixed
        {
            return $_POST[$key] ?? $_GET[$key] ?? $default;
        }
    }
}

// Minimal Configuration stub
if (!class_exists('Configuration', false)) {
    class Configuration
    {
        public static array $values = [];

        public static function updateValue(string $key, mixed $value, bool $html = false, int $idShopGroup = 0, int $idShop = 0): bool
        {
            self::$values[$key] = $value;
            return true;
        }

        public static function get(string $key, int $idLang = 0, int $idShopGroup = 0, int $idShop = 0, mixed $default = false): mixed
        {
            return self::$values[$key] ?? $default;
        }

        public static function deleteByName(string $key): bool
        {
            unset(self::$values[$key]);
            return true;
        }
    }
}

// Minimal Db stub
if (!class_exists('Db', false)) {
    class Db
    {
        private static ?self $instance = null;

        public static function getInstance(): self
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function execute(string $sql): bool
        {
            return true;
        }

        public function executeS(string $sql): array|false
        {
            return [];
        }

        public function insert(string $table, array $data, bool $nullValues = false, bool $useCache = true, int $type = 1): bool
        {
            return true;
        }
    }
}

// Minimal Module stub
if (!class_exists('Module', false)) {
    class Module {}
}

if (!function_exists('pSQL')) {
    function pSQL(string $string, bool $htmlOK = false): string
    {
        return addslashes($string);
    }
}

// Autoload module classes
require_once __DIR__ . '/../classes/SsoToken.php';
require_once __DIR__ . '/../install/install.php';
