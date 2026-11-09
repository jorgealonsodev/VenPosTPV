<?php
/**
 * Test bootstrap for tpvconnect module.
 * Provides isolated PrestaShop-contract doubles without a PS runtime.
 */

declare(strict_types=1);

define('_PS_VERSION_', '8.1.0');
define('_DB_PREFIX_', 'ps_');
define('_MYSQL_ENGINE_', 'InnoDB');
define('_PS_MODULE_DIR_', __DIR__ . '/../');
define('_PS_CACHE_DIR_', sys_get_temp_dir() . '/');

if (!class_exists('Tools', false)) {
    class Tools
    {
        public static function strlen(string $str): int { return strlen($str); }
        public static function htmlentitiesUTF8(string $str): string { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }
        public static function passwdGen(int $length = 8): string { return bin2hex(random_bytes((int) ceil($length / 2))); }
        public static function getValue(string $key, mixed $default = false): mixed { return $_POST[$key] ?? $_GET[$key] ?? $default; }
    }
}

if (!class_exists('Configuration', false)) {
    class Configuration
    {
        /** This request's static cache: what get() answers. */
        public static array $values = [];
        /**
         * The configuration table every request shares. A test can model another
         * request by giving $values a snapshot taken earlier: that stale cache
         * keeps keys this store has since lost, as in a real shop.
         */
        public static array $db = [];
        public static function updateValue(string $key, mixed $value, bool $html = false, int $idShopGroup = 0, int $idShop = 0): bool { self::$values[$key] = $value; self::$db[$key] = $value; return true; }
        public static function get(string $key, int $idLang = 0, int $idShopGroup = 0, int $idShop = 0, mixed $default = false): mixed { return self::$values[$key] ?? $default; }
        public static function deleteByName(string $key): bool { unset(self::$values[$key], self::$db[$key]); return true; }
        /**
         * Native (PrestaShop 9.2): merges the table rows into the static cache and
         * never drops a cached key whose row another request deleted.
         */
        public static function loadConfiguration(): void { self::$values = array_replace(self::$values, self::$db); }
        /** The id_configuration the fake table gives a key. */
        public static function idOf(string $key): int { return crc32($key) & 0x7FFFFFFF; }
    }
}

if (!class_exists('Module', false)) {
    class Module
    {
        /** @var list<string> hooks registered through registerHook(), in order */
        public array $registeredHooks = [];
        public function registerHook(string|array $hookName, ?array $shopList = null): bool
        {
            foreach ((array) $hookName as $hook) { $this->registeredHooks[] = $hook; }
            return true;
        }
        public function unregisterHook(string $hookName, ?array $shopList = null): bool { return true; }
    }
}
if (!class_exists('PaymentModule', false)) {
    class PaymentModule extends Module {}
}

if (!function_exists('pSQL')) {
    function pSQL(string $string, bool $htmlOK = false): string { return addslashes($string); }
}

if (!class_exists('Context', false)) {
    class Context
    {
        public static ?self $context = null;
        public mixed $language;
        public mixed $shop;
        public mixed $customer = null;
        public mixed $currency = null;
        public mixed $cart = null;
        public static function getContext(): self { return self::$context ??= new self(); }
    }
}

if (!class_exists('CouponTestState', false)) {
    class CouponTestState
    {
        public static array $rules = [];
        public static array $carts = [];
        public static array $orders = [];
        public static array $productPrices = [];
        public static array $heldLocks = [];
        public static array $acquiredLocks = [];
        public static array $lockQueries = [];
        public static ?string $throwOnGetLock = null;
        public static float $payableAdjustment = 0.0;
        public static array $totalQueries = [];
        public static int $nextCartId = 1;
        public static int $nextAddressId = 1;
        public static int $ordersCreated = 0;
        public static int $locksReleased = 0;
        public static int $stockQuantity = 999;
        /** What Customer::getAddresses() answers. */
        public static array $customerAddresses = [];
        /** Countries whose need_identification_number is 1. */
        public static array $dniRequiredCountries = [];
        /** Addresses add() stored, in order. */
        public static array $addedAddresses = [];
        /** Product ids Cart::updateQty() refuses. */
        public static array $unavailableProducts = [];

        public static function reset(): void
        {
            self::$rules = [];
            self::$carts = [];
            self::$orders = [];
            self::$productPrices = [1 => 100.0];
            self::$heldLocks = [];
            self::$acquiredLocks = [];
            self::$lockQueries = [];
            self::$throwOnGetLock = null;
            self::$payableAdjustment = 0.0;
            self::$totalQueries = [];
            self::$nextCartId = 1;
            self::$nextAddressId = 1;
            self::$ordersCreated = 0;
            self::$locksReleased = 0;
            self::$stockQuantity = 999;
            self::$customerAddresses = [['id_address' => 1]];
            self::$dniRequiredCountries = [];
            self::$addedAddresses = [];
            self::$unavailableProducts = [];
            Context::$context = new Context();
            Context::$context->language = (object) ['id' => 1];
            Context::$context->shop = (object) ['id' => 1];
        }
    }
}
CouponTestState::reset();

if (!class_exists('Db', false)) {
    class Db
    {
        private static ?self $instance = null;
        public static function getInstance(): self { return self::$instance ??= new self(); }
        public function execute(string $sql): bool { return true; }
        public function executeS(string $sql, bool $array = true, bool $useCache = true): array|false
        {
            if (preg_match("/(GET_LOCK|RELEASE_LOCK)\\('([^']+)'/", $sql, $matches)) {
                [, $operation, $lockName] = $matches;
                CouponTestState::$lockQueries[] = ['operation' => $operation, 'name' => $lockName, 'use_cache' => $useCache];
                if ($operation === 'GET_LOCK') {
                    if (CouponTestState::$throwOnGetLock === $lockName) {
                        throw new RuntimeException('Simulated GET_LOCK failure');
                    }
                    $available = !isset(CouponTestState::$heldLocks[$lockName]) && !isset(CouponTestState::$acquiredLocks[$lockName]);
                    if ($available) { CouponTestState::$acquiredLocks[$lockName] = true; }
                    return [['locked' => $available ? '1' : '0']];
                }
                unset(CouponTestState::$acquiredLocks[$lockName]);
                CouponTestState::$locksReleased++;
                return [['released' => '1']];
            }
            if (str_contains($sql, 'webservice_permission')) {
                return self::$permissionRows;
            }
            return [];
        }
        /** @var list<array{table: string, data: array<string, mixed>}> rows insert() received, in order */
        public static array $inserted = [];
        /** What a SELECT on webservice_permission answers. */
        public static array $permissionRows = [];
        public function insert(string $table, array $data, bool $nullValues = false, bool $useCache = true, int $type = 1): bool { self::$inserted[] = ['table' => $table, 'data' => $data]; return true; }
        /** @var list<array{sql: string, use_cache: bool}> getRow() calls on the configuration table */
        public static array $configurationReads = [];
        /** A configuration row comes from Configuration::$db, never from the static cache. */
        public function getRow(string $sql, bool $useCache = true): array|false
        {
            if (preg_match("/FROM `?" . _DB_PREFIX_ . "configuration`? .*`?name`? = '([^']+)'/s", $sql, $matches)) {
                self::$configurationReads[] = ['sql' => $sql, 'use_cache' => $useCache];
                $key = stripslashes($matches[1]);
                return array_key_exists($key, Configuration::$db)
                    ? ['id_configuration' => (string) Configuration::idOf($key), 'value' => Configuration::$db[$key]]
                    : false;
            }
            return false;
        }
        /** An update by id_configuration changes the table row only, like a real UPDATE. */
        public function update(string $table, array $data, string $where = '', int $limit = 0, bool $nullValues = false, bool $useCache = true, bool $addPrefix = true): bool
        {
            if ($table === 'configuration' && preg_match('/`?id_configuration`? = (\d+)/', $where, $matches)) {
                foreach (array_keys(Configuration::$db) as $key) {
                    if (Configuration::idOf((string) $key) === (int) $matches[1]) {
                        Configuration::$db[$key] = $data['value'];
                    }
                }
            }
            return true;
        }
    }
}

if (!class_exists('Shop', false)) {
    class Shop
    {
        public int $id;
        public function __construct(int $id = 0) { $this->id = $id; }
        /** Native: null without multistore when asked for the null value. */
        public static function getContextShopID(bool $nullValueWithoutMultishop = false): ?int { return null; }
        public static function getContextShopGroupID(bool $nullValueWithoutMultishop = false): ?int { return null; }
    }
}
if (!class_exists('Customer', false)) {
    class Customer
    {
        public int $id;
        public string $secure_key = 'test-secure-key';
        public bool $loaded = true;
        public function __construct(int $id = 0) { $this->id = $id; }
        public function getAddresses(int $idLang): array { return CouponTestState::$customerAddresses; }
    }
}
if (!class_exists('Currency', false)) {
    class Currency { public int $id; public bool $loaded = true; public function __construct(int $id = 0) { $this->id = $id; } }
}
if (!class_exists('Address', false)) {
    class Address
    {
        public int $id = 0;
        public int $id_customer = 0;
        public string $firstname = '';
        public string $lastname = '';
        public string $address1 = '';
        public string $city = '';
        public int $id_country = 0;
        public string $alias = '';
        public mixed $dni = null;
        public bool $deleted = false;
        public static function dniRequired($idCountry): bool { return in_array((int) $idCountry, CouponTestState::$dniRequiredCountries, true); }
        /** Native ObjectModel::validateFields() with Address::validateField()'s dni rule. */
        public function validateFields($die = true, $errorReturn = false): bool|string
        {
            $message = true;
            if ($this->dni !== null && $this->dni !== '' && !Validate::isDniLite($this->dni)) {
                $message = 'Property Address->dni is not valid';
            } elseif (!$this->deleted && static::dniRequired($this->id_country) && ($this->dni === null || $this->dni === '')) {
                $message = 'Property Address->dni is empty.';
            }
            if ($message !== true && $die) {
                throw new RuntimeException($message);
            }
            return $message === true ? true : ($errorReturn ? $message : false);
        }
        public function add(): bool
        {
            // Native add() runs validateFields() and throws when a field is invalid.
            $this->validateFields();
            $this->id = CouponTestState::$nextAddressId++;
            CouponTestState::$addedAddresses[] = clone $this;
            return true;
        }
    }
}

if (!class_exists('Cart', false)) {
    class Cart
    {
        public const ONLY_PRODUCTS = 1;
        public const ONLY_DISCOUNTS = 2;
        public const BOTH = 3;
        public const BOTH_WITHOUT_SHIPPING = 4;
        public const ONLY_SHIPPING = 5;
        public const ONLY_WRAPPING = 6;
        public int $id = 0;
        public int $id_customer = 0;
        public int $id_currency = 0;
        public int $id_shop = 0;
        public int $id_lang = 0;
        public string $secure_key = '';
        public int $id_address_delivery = 0;
        public int $id_address_invoice = 0;
        public array $products = [];
        public array $cartRules = [];
        public function __construct(int $id = 0)
        {
            if ($id > 0 && isset(CouponTestState::$carts[$id])) {
                foreach (get_object_vars(CouponTestState::$carts[$id]) as $key => $value) { $this->$key = $value; }
            }
        }
        public function save(): bool
        {
            if ($this->id === 0) { $this->id = CouponTestState::$nextCartId++; }
            CouponTestState::$carts[$this->id] = clone $this;
            return true;
        }
        public function updateQty(int $quantity, int $idProduct, int $idProductAttribute = 0): bool
        {
            if (in_array($idProduct, CouponTestState::$unavailableProducts, true)) {
                return false;
            }
            // Native Cart::getProducts exposes cart_quantity; quantity is stock.
            $this->products[] = [
                'id_product' => $idProduct,
                'id_product_attribute' => $idProductAttribute,
                'cart_quantity' => $quantity,
                'quantity' => CouponTestState::$stockQuantity,
            ];
            return $this->save();
        }
        public function addCartRule(int $idCartRule): bool { $this->cartRules[] = $idCartRule; return $this->save(); }
        public function checkQuantities(bool $returnErrors = false): bool { return true; }
        public function getProducts(): array { return $this->products; }
        public function getCartRules(): array
        {
            $rules = [];
            foreach ($this->cartRules as $id) {
                foreach (CouponTestState::$rules as $rule) {
                    if ($rule['id'] === $id) { $rules[] = ['id_cart_rule' => $id, 'value_real' => (float) $rule['reduction']]; }
                }
            }
            return $rules;
        }
        public function getOrderTotal(bool $withTaxes = true, int $type = self::BOTH, mixed $products = null, mixed $idCarrier = null, bool $useCache = false, bool $keepOrderPrices = false): float
        {
            CouponTestState::$totalQueries[] = ['type' => $type, 'use_cache' => $useCache];
            $productsTotal = 0.0;
            foreach ($this->products as $product) { $productsTotal += (CouponTestState::$productPrices[$product['id_product']] ?? 0) * $product['cart_quantity']; }
            if ($type === self::ONLY_PRODUCTS) {
                return max(0, $productsTotal);
            }
            $discounts = 0.0;
            foreach ($this->cartRules as $id) {
                foreach (CouponTestState::$rules as $rule) { if ($rule['id'] === $id) { $discounts += $rule['reduction']; } }
            }
            if ($type === self::ONLY_DISCOUNTS) {
                return -$discounts;
            }
            return max(0, $productsTotal - $discounts + CouponTestState::$payableAdjustment);
        }
    }
}

if (!class_exists('CartRule', false)) {
    class CartRule
    {
        public int $id = 0;
        public string $code = '';
        public string $date_from = '2000-01-01 00:00:00';
        public string $date_to = '0000-00-00 00:00:00';
        public bool $active = false;
        public int $quantity = 0;
        public bool $loaded = false;
        public function __construct(int $id = 0)
        {
            foreach (CouponTestState::$rules as $code => $rule) {
                if ($rule['id'] === $id) {
                    $this->id = $id;
                    $this->code = $code;
                    $this->date_from = $rule['date_from'] ?? '2000-01-01 00:00:00';
                    $this->date_to = $rule['date_to'];
                    $this->active = $rule['active'] ?? true;
                    $this->quantity = $rule['quantity'] ?? 1;
                    $this->loaded = true;
                }
            }
        }
        public static function getIdByCode(string $code, int $idLang = 0): int { return CouponTestState::$rules[$code]['id'] ?? 0; }
        public function checkValidity(Context $context, bool $alreadyInCart = false, bool $displayError = true): bool|string|null
        {
            $rule = CouponTestState::$rules[$this->code] ?? null;
            $error = null;
            if ($rule === null) { $error = 'unknown'; }
            elseif (!$alreadyInCart && (!$this->active || $this->quantity < 1 || strtotime($this->date_from) > time() || strtotime($this->date_to) < time())) { $error = 'inactive, exhausted, or expired'; }
            elseif (!$alreadyInCart && $context->cart !== null && $context->cart->id > 0 && in_array($this->id, $context->cart->cartRules, true)) { $error = 'already in cart'; }
            elseif (isset($rule['customer_id']) && (int) $context->customer->id !== $rule['customer_id']) { $error = 'customer restricted'; }
            elseif (isset($rule['shop_id']) && (int) $context->shop->id !== $rule['shop_id']) { $error = 'shop restricted'; }
            if ($error !== null) { return $displayError ? $error : false; }
            return $displayError ? null : true;
        }
    }
}

if (!class_exists('Order', false)) {
    class Order
    {
        public int $id = 0;
        public string $reference = '';
        public float $total_paid = 0.0;
        public array $cart_rules = [];
        public bool $loaded = false;
        public function __construct(int $id = 0)
        {
            if (isset(CouponTestState::$orders[$id])) { foreach (CouponTestState::$orders[$id] as $key => $value) { $this->$key = $value; } $this->loaded = true; }
        }
        public static function getIdByCartId(int $idCart): int { return CouponTestState::$orders['cart:' . $idCart]['id'] ?? 0; }
        public static function record(int $idCart, int $idOrder, float $total, string $reference, array $cartRules = []): void
        {
            CouponTestState::$orders[$idOrder] = ['id' => $idOrder, 'reference' => $reference, 'total_paid' => $total, 'cart_rules' => $cartRules];
            CouponTestState::$orders['cart:' . $idCart] = ['id' => $idOrder];
        }
        public function getCartRules(): array { return CouponTestState::$orders[$this->id]['cart_rules'] ?? []; }
    }
}
if (!class_exists('Validate', false)) {
    class Validate
    {
        public static function isLoadedObject(object $object): bool { return ($object->loaded ?? true) === true; }
        /** Native Validate::isDniLite (PrestaShop 8.1). */
        public static function isDniLite($dni): bool { return empty($dni) || (bool) preg_match('/^[0-9A-Za-z-.]{1,16}$/U', (string) $dni); }
    }
}

if (!class_exists('OrderState', false)) {
    /** Order states by id; add() appends one, so tests can see what an installer created. */
    class OrderState
    {
        /** @var array<int, array<string, mixed>> */
        public static array $states = [];
        public ?int $id = null;
        public string $color = '';
        public bool $paid = false;
        public bool $logable = false;
        public string $module_name = '';
        /** @var array<int, string> */
        public array $name = [];
        public bool $loaded = false;
        public function __construct(int $id = 0)
        {
            if ($id > 0 && isset(self::$states[$id])) { $this->id = $id; $this->loaded = true; }
        }
        public function add(): bool
        {
            $this->id = (self::$states === [] ? 0 : max(array_keys(self::$states))) + 1;
            self::$states[$this->id] = ['name' => $this->name, 'color' => $this->color, 'module_name' => $this->module_name];
            $this->loaded = true;
            return true;
        }
    }
}
if (!class_exists('Language', false)) {
    class Language { public static function getIdByIso(string $iso): int|false { return $iso === 'es' ? 1 : false; } }
}

if (!class_exists('WebserviceKey', false)) {
    /** Webservice keys by id: key value, active flag and description. */
    class WebserviceKey
    {
        /** @var array<int, array{key: string, active: bool, description: string}> */
        public static array $keys = [];
        public int $id = 0;
        public string $key = '';
        public bool $active = true;
        public string $description = '';
        public bool $loaded = false;
        public function __construct(int $id = 0)
        {
            if ($id > 0 && isset(self::$keys[$id])) {
                $this->id = $id;
                $this->key = self::$keys[$id]['key'];
                $this->active = self::$keys[$id]['active'];
                $this->description = self::$keys[$id]['description'];
                $this->loaded = true;
            }
        }
        public static function keyExists(string $key): bool
        {
            foreach (self::$keys as $row) { if ($row['key'] === $key) { return true; } }
            return false;
        }
        public function add(): bool
        {
            if (self::keyExists($this->key)) { return false; }
            $this->id = (self::$keys === [] ? 100 : max(array_keys(self::$keys))) + 1;
            self::$keys[$this->id] = ['key' => $this->key, 'active' => (bool) $this->active, 'description' => $this->description];
            $this->loaded = true;
            return true;
        }
        public function update(): bool
        {
            self::$keys[$this->id] = ['key' => $this->key, 'active' => (bool) $this->active, 'description' => $this->description];
            return true;
        }
    }
}
if (!class_exists('PrestaShopLogger', false)) {
    class PrestaShopLogger
    {
        /** @var list<array{message: string, severity: int}> */
        public static array $logs = [];
        public static function addLog($message, $severity = 1, $errorCode = null, $objectType = null, $objectId = null, $allowDuplicate = false, $idEmployee = null): bool
        {
            self::$logs[] = ['message' => (string) $message, 'severity' => (int) $severity];
            return true;
        }
    }
}

require_once __DIR__ . '/../classes/SsoToken.php';
require_once __DIR__ . '/../install/install.php';
require_once __DIR__ . '/../classes/CouponCheckout.php';
if (is_file(__DIR__ . '/../classes/RefundProcessor.php')) {
    require_once __DIR__ . '/../classes/RefundProcessor.php';
}
if (is_file(__DIR__ . '/../classes/OrderLookupProcessor.php')) {
    require_once __DIR__ . '/../classes/OrderLookupProcessor.php';
}
if (is_file(__DIR__ . '/../classes/RestockProcessor.php')) {
    require_once __DIR__ . '/../classes/RestockProcessor.php';
}
if (is_file(__DIR__ . '/../classes/ReferencedOrderCreator.php')) {
    require_once __DIR__ . '/../classes/ReferencedOrderCreator.php';
}
foreach (['FreeItemOrderLines', 'FreeItemProductSetup', 'LeftoverVoucher', 'TpvPairing'] as $class) {
    if (is_file(__DIR__ . '/../classes/' . $class . '.php')) {
        require_once __DIR__ . '/../classes/' . $class . '.php';
    }
}
require_once __DIR__ . '/../tpvconnect.php';
