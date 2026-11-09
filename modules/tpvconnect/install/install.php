<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

if (class_exists('TpvConnectInstaller')) {
    return;
}

class TpvConnectInstaller
{
    private const TECHNICAL_CUSTOMER_EMAIL = 'venpostpv@local.invalid';
    private const TECHNICAL_CUSTOMER_FIRSTNAME = 'VenPosTPV';
    private const TECHNICAL_CUSTOMER_LASTNAME = 'Cliente';

    /** Hooks the module listens to; registered on install and by upgrades. */
    public const HOOKS = [
        'displayBackOfficeHeader',
        'actionAdminControllerSetMedia',
        // Gives TPV orders the ticket code as reference (web orders are untouched).
        'actionGenerateDocumentReference',
        // Shows a free item's concept instead of the generic product in the order e-mail.
        'actionPaymentModuleProductVarTplAfter',
    ];

    /**
     * Webservice resources and methods the TPV's key needs: catalog and stock
     * reads, stock writes, customers created at the till (POST customers) and
     * coupons created at the till (GET cart_rules?schema=blank, POST cart_rules).
     */
    public const WEBSERVICE_PERMISSIONS = [
        'products' => ['GET', 'HEAD'],
        'combinations' => ['GET', 'HEAD'],
        'stock_availables' => ['GET', 'PUT', 'HEAD'],
        'customers' => ['GET', 'POST', 'HEAD'],
        'categories' => ['GET', 'HEAD'],
        'currencies' => ['GET', 'HEAD'],
        'order_states' => ['GET', 'HEAD'],
        'images' => ['GET', 'HEAD'],
        'orders' => ['GET', 'POST', 'PUT', 'HEAD'],
        'product_options' => ['GET', 'HEAD'],
        'product_option_values' => ['GET', 'HEAD'],
        'cart_rules' => ['GET', 'POST', 'HEAD'],
    ];

    private Module $module;

    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    public function run(): bool
    {
        return $this->installSql()
            && $this->createOrderStates()
            && $this->createAnonymousCustomer()
            && $this->setConfigurationDefaults();
    }

    /**
     * Register every module hook. registerHook() is idempotent, so upgrades can
     * run it again to add hooks introduced by a new version.
     */
    public function registerHooks(): bool
    {
        foreach (self::HOOKS as $hook) {
            if (!$this->module->registerHook($hook)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create custom order states for TPV operations.
     * Reuses existing states on reinstall (checked via Configuration
     * and fallback table ps_order_state_tpvconnect).
     */
    public function createOrderStates(): bool
    {
        $states = [
            'TPV_OS_PAID' => [
                'color' => '#32CD32',
                'paid' => true,
                'logable' => true,
                'names' => [
                    'es' => 'Pagado en TPV',
                    'en' => 'Paid at POS',
                    'fr' => 'Payé en TPV',
                    'pt' => 'Pago no TPV',
                ],
            ],
            'TPV_OS_REFUND' => [
                'color' => '#999999',
                'paid' => false,
                'logable' => false,
                'names' => [
                    'es' => 'Devolución TPV',
                    'en' => 'POS Refund',
                    'fr' => 'Remboursement TPV',
                    'pt' => 'Reembolso TPV',
                ],
            ],
            // Online orders returned at the shop: the TPV paid nothing back and the
            // office refunds from the back office.
            'TPV_OS_REFUND_PENDING' => [
                'color' => '#FF8C00',
                'paid' => false,
                'logable' => false,
                'names' => [
                    'es' => 'Devuelto en tienda – reembolso pendiente',
                    'en' => 'Returned in store – refund pending',
                    'fr' => 'Retourné en boutique – remboursement en attente',
                    'pt' => 'Devolvido na loja – reembolso pendente',
                ],
            ],
            'TPV_OS_PENDING_SYNC' => [
                'color' => '#FFD700',
                'paid' => false,
                'logable' => false,
                'names' => [
                    'es' => 'Pendiente sincronización',
                    'en' => 'Pending synchronization',
                    'fr' => 'Synchronisation en attente',
                    'pt' => 'Sincronização pendente',
                ],
            ],
        ];

        foreach ($states as $configKey => $stateData) {
            $existingId = (int) Configuration::get($configKey);

            if ($existingId > 0) {
                $existingState = new OrderState($existingId);
                if (Validate::isLoadedObject($existingState)) {
                    continue;
                }
            }

            // Fallback: check tracking table when config was wiped (reinstall)
            $fallbackId = $this->getFallbackOrderStateId($configKey);
            if ($fallbackId !== null && $fallbackId > 0) {
                $existingState = new OrderState($fallbackId);
                if (Validate::isLoadedObject($existingState)) {
                    Configuration::updateValue($configKey, $fallbackId);
                    continue;
                }
                // Stale fallback entry: state was deleted, clean it up
                $this->removeFallbackOrderState($configKey);
            }

            $orderState = new OrderState();
            $orderState->color = $stateData['color'];
            $orderState->paid = $stateData['paid'];
            $orderState->logable = $stateData['logable'];
            $orderState->module_name = 'tpvconnect';
            $orderState->name = [];

            foreach ($stateData['names'] as $iso => $name) {
                $langId = $this->getLangIdByIso($iso);
                if ($langId > 0) {
                    $orderState->name[$langId] = $name;
                }
            }

            if (!$orderState->add()) {
                return false;
            }

            Configuration::updateValue($configKey, (int) $orderState->id);

            // Also track in fallback table (upsert for corruption recovery)
            $this->trackOrderState((int) $orderState->id, $configKey);
        }

        return true;
    }

    /**
     * Create anonymous "Consumidor final" customer for POS checkouts.
     */
    public function createAnonymousCustomer(): bool
    {
        $existingId = (int) Configuration::get('TPVCONNECT_ANONYMOUS_CUSTOMER_ID');

        if ($existingId > 0) {
            $existingCustomer = new Customer($existingId);
            if (Validate::isLoadedObject($existingCustomer)) {
                $this->ensureCustomerAddress((int) $existingCustomer->id);

                return true;
            }
            // Customer was deleted; clear stale config
            Configuration::deleteByName('TPVCONNECT_ANONYMOUS_CUSTOMER_ID');
        }

        // With $returnId, customerExists() gives the id itself (getByEmail() is
        // an instance method: calling it statically is an Error on PHP 8).
        $customerId = (int) Customer::customerExists(self::TECHNICAL_CUSTOMER_EMAIL, true, false);
        if ($customerId > 0) {
            Configuration::updateValue('TPVCONNECT_ANONYMOUS_CUSTOMER_ID', $customerId);
            $this->ensureCustomerAddress($customerId);

            return true;
        }

        $customer = new Customer();
        $customer->firstname = self::TECHNICAL_CUSTOMER_FIRSTNAME;
        $customer->lastname = self::TECHNICAL_CUSTOMER_LASTNAME;
        $customer->email = self::TECHNICAL_CUSTOMER_EMAIL;
        // Nobody logs in as this customer. password_hash() instead of
        // the old Tools encrypt helper, which PrestaShop 9 removed; it checks
        // customer passwords with password_verify() since 1.7.
        $customer->passwd = password_hash(Tools::passwdGen(32), PASSWORD_BCRYPT);
        $customer->secure_key = md5(uniqid((string) mt_rand(), true));
        $customer->active = 1;
        $customer->deleted = 0;
        $customer->is_guest = 0;
        $customer->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');

        if (!$customer->add()) {
            return false;
        }

        Configuration::updateValue('TPVCONNECT_ANONYMOUS_CUSTOMER_ID', (int) $customer->id);

        $this->ensureCustomerAddress((int) $customer->id);

        return true;
    }

    private function ensureCustomerAddress(int $customerId): void
    {
        $customer = new Customer($customerId);
        if (!Validate::isLoadedObject($customer)) {
            return;
        }

        $addresses = $customer->getAddresses((int) Configuration::get('PS_LANG_DEFAULT'));
        if (!empty($addresses)) {
            return;
        }

        $address = new Address();
        $address->id_customer = $customerId;
        $address->alias = 'TPV';
        $address->firstname = self::TECHNICAL_CUSTOMER_FIRSTNAME;
        $address->lastname = self::TECHNICAL_CUSTOMER_LASTNAME;
        $address->address1 = 'VenPosTPV';
        $address->city = 'TPV';
        $address->id_country = (int) Configuration::get('PS_COUNTRY_DEFAULT');
        $address->phone = '000000000';
        // Countries such as Spain require an identification number: without it
        // PrestaShop refuses the address ("Address->dni is empty") and the install
        // fails. Same placeholder as the temporary addresses of TPV orders.
        if (Address::dniRequired($address->id_country)) {
            $address->dni = 'TPV';
        }
        $address->add();
    }

    /**
     * Gives a webservice key every WEBSERVICE_PERMISSIONS entry it lacks. Rows it
     * already has are kept, so running it again (upgrades) adds nothing twice.
     */
    public function grantWebservicePermissions(int $idWebserviceAccount): bool
    {
        if ($idWebserviceAccount <= 0) {
            return true;
        }
        $db = Db::getInstance();
        $existing = [];
        $rows = $db->executeS(
            'SELECT `resource`, `method` FROM `' . _DB_PREFIX_ . 'webservice_permission` WHERE `id_webservice_account` = ' . $idWebserviceAccount,
            true,
            false
        );
        foreach (is_array($rows) ? $rows : [] as $row) {
            $existing[$row['resource'] . ' ' . $row['method']] = true;
        }
        foreach (self::WEBSERVICE_PERMISSIONS as $resource => $methods) {
            foreach ($methods as $method) {
                if (isset($existing[$resource . ' ' . $method])) {
                    continue;
                }
                if (!$db->insert('webservice_permission', [
                    'resource' => pSQL($resource),
                    'method' => pSQL($method),
                    'id_webservice_account' => $idWebserviceAccount,
                ])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Set default Configuration values on install.
     */
    public function setConfigurationDefaults(): bool
    {
        $secret = bin2hex(random_bytes(32));

        Configuration::updateValue('TPVCONNECT_URL', '');
        Configuration::updateValue('TPVCONNECT_SSO_SECRET', $secret);
        Configuration::updateValue('TPVCONNECT_WS_KEY_ID', '0');

        return true;
    }

    /**
     * Execute SQL install file.
     */
    public function installSql(): bool
    {
        $sqlFile = dirname(__DIR__) . '/install/sql/install.php';
        if (!file_exists($sqlFile)) {
            return true;
        }

        $sql = include $sqlFile;
        if (!is_array($sql)) {
            return true;
        }

        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Clean up Configuration values on uninstall.
     * Order states are deliberately NOT deleted to preserve history.
     */
    public function uninstallConfiguration(): bool
    {
        $keys = [
            'TPVCONNECT_URL',
            'TPVCONNECT_SSO_SECRET',
            'TPV_OS_PAID',
            'TPV_OS_REFUND',
            'TPV_OS_PENDING_SYNC',
            'TPV_OS_REFUND_PENDING',
            'TPVCONNECT_WS_KEY_ID',
            'TPVCONNECT_ANONYMOUS_CUSTOMER_ID',
            // Pairing code (TpvPairing): its hash, expiry and attempt counter.
            'TPVCONNECT_PAIR_CODE',
            'TPVCONNECT_PAIR_EXPIRES',
            'TPVCONNECT_PAIR_ATTEMPTS',
        ];

        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }

        // Clean up any nonce Configuration keys
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . "configuration`
            WHERE `name` LIKE 'TPVCONNECT_NONCE_%'"
        );

        return true;
    }

    private function getLangIdByIso(string $iso): int
    {
        $lang = Language::getIdByIso($iso);

        return $lang !== false ? (int) $lang : 0;
    }

    private function getFallbackOrderStateId(string $stateKey): ?int
    {
        if (!$this->tableExists(_DB_PREFIX_ . 'order_state_tpvconnect')) {
            return null;
        }

        $result = Db::getInstance()->executeS(
            'SELECT `id_order_state` FROM `' . _DB_PREFIX_ . 'order_state_tpvconnect`
            WHERE `state_key` = "' . pSQL($stateKey) . '"'
        );

        if (!empty($result) && is_array($result)) {
            return (int) $result[0]['id_order_state'];
        }

        return null;
    }

    private function removeFallbackOrderState(string $stateKey): void
    {
        if (!$this->tableExists(_DB_PREFIX_ . 'order_state_tpvconnect')) {
            return;
        }

        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'order_state_tpvconnect`
            WHERE `state_key` = "' . pSQL($stateKey) . '"'
        );
    }

    private function trackOrderState(int $idOrderState, string $stateKey): void
    {
        if (!$this->tableExists(_DB_PREFIX_ . 'order_state_tpvconnect')) {
            return;
        }

        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'order_state_tpvconnect`
            (`id_order_state`, `state_key`, `module_name`)
            VALUES (' . (int) $idOrderState . ', "' . pSQL($stateKey) . '", "tpvconnect")
            ON DUPLICATE KEY UPDATE
            `id_order_state` = VALUES(`id_order_state`),
            `module_name` = VALUES(`module_name`)'
        );
    }

    private function tableExists(string $tableName): bool
    {
        $result = Db::getInstance()->executeS(
            'SHOW TABLES LIKE "' . pSQL($tableName) . '"'
        );

        return !empty($result);
    }
}
