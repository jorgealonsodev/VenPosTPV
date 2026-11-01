<?php
/**
 * Test bootstrap for tpvconnect module.
 * Provides isolated cart-contract doubles without a PS runtime.
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
        public static array $values = [];
        public static function updateValue(string $key, mixed $value, bool $html = false, int $idShopGroup = 0, int $idShop = 0): bool { self::$values[$key] = $value; return true; }
        public static function get(string $key, int $idLang = 0, int $idShopGroup = 0, int $idShop = 0, mixed $default = false): mixed { return self::$values[$key] ?? $default; }
        public static function deleteByName(string $key): bool { unset(self::$values[$key]); return true; }
    }
}

if (!class_exists('Module', false)) {
    class Module {}
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
        public static int $nextCartId = 1;
        public static int $nextAddressId = 1;
        public static int $ordersCreated = 0;
        public static int $stockQuantity = 999;

        public static function reset(): void
        {
            self::$rules = [];
            self::$carts = [];
            self::$orders = [];
            self::$productPrices = [1 => 100.0];
            self::$nextCartId = 1;
            self::$nextAddressId = 1;
            self::$ordersCreated = 0;
            self::$stockQuantity = 999;
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
        public function executeS(string $sql): array|false { return []; }
        public function insert(string $table, array $data, bool $nullValues = false, bool $useCache = true, int $type = 1): bool { return true; }
    }
}

if (!class_exists('Shop', false)) {
    class Shop { public int $id; public function __construct(int $id = 0) { $this->id = $id; } }
}
if (!class_exists('Customer', false)) {
    class Customer
    {
        public int $id;
        public string $secure_key = 'test-secure-key';
        public bool $loaded = true;
        public function __construct(int $id = 0) { $this->id = $id; }
        public function getAddresses(int $idLang): array { return [['id_address' => 1]]; }
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
        public function add(): bool { $this->id = CouponTestState::$nextAddressId++; return true; }
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
            $productsTotal = 0.0;
            foreach ($this->products as $product) { $productsTotal += (CouponTestState::$productPrices[$product['id_product']] ?? 0) * $product['cart_quantity']; }
            if ($type === self::ONLY_PRODUCTS) { return max(0, $productsTotal); }
            $discounts = 0.0;
            foreach ($this->cartRules as $id) {
                foreach (CouponTestState::$rules as $rule) { if ($rule['id'] === $id) { $discounts += $rule['reduction']; } }
            }
            if ($type === self::ONLY_DISCOUNTS) { return -$discounts; }
            return max(0, $productsTotal - $discounts);
        }
    }
}

require_once __DIR__ . '/../classes/SsoToken.php';
require_once __DIR__ . '/../install/install.php';
require_once __DIR__ . '/../classes/CouponCheckout.php';
