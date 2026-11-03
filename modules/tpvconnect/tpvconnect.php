<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/install/install.php';
require_once __DIR__ . '/classes/SsoToken.php';

class Tpvconnect extends PaymentModule
{
    private const HOOKS = [
        'displayBackOfficeHeader',
        'actionAdminControllerSetMedia',
    ];

    public function __construct()
    {
        $this->name = 'tpvconnect';
        $this->tab = 'administration';
        $this->version = '0.4.0';
        $this->author = 'VenPosTPV';
        $this->need_instance = 1;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = [
            'min' => '1.7.7.0',
            'max' => '8.99.99',
        ];

        parent::__construct();

        $this->displayName = $this->l('TPV Connect');
        $this->description = $this->l('Conecta PrestaShop con la aplicación TPV independiente vía SSO y API.');
        $this->confirmUninstall = $this->l('¿Estás seguro de que deseas desinstalar TPV Connect? Los estados de pedido se conservarán.');
    }

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        foreach (self::HOOKS as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        $installer = new TpvConnectInstaller($this);
        if (!$installer->run()) {
            return false;
        }

        if (!$this->installTab()) {
            return false;
        }

        Configuration::updateValue('TPVCONNECT_FIRST_INSTALL', '1');

        return true;
    }

    public function uninstall(): bool
    {
        $installer = new TpvConnectInstaller($this);
        $installer->uninstallConfiguration();

        $this->uninstallTab();

        foreach (self::HOOKS as $hook) {
            $this->unregisterHook($hook);
        }

        return parent::uninstall();
    }

    /**
     * Admin configuration page (HelperForm).
     */
    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('submit_tpvconnect_config')) {
            $output .= $this->saveConfiguration();
        }

        if (Tools::isSubmit('submit_tpvconnect_regenerate_secret')) {
            $output .= $this->regenerateSecret();
        }

        if (Tools::isSubmit('submit_tpvconnect_generate_ws_key')) {
            $output .= $this->generateWebserviceKey();
        }

        // Show SSO secret after first install
        if (Configuration::get('TPVCONNECT_FIRST_INSTALL')) {
            $secret = Configuration::get('TPVCONNECT_SSO_SECRET');
            $output .= $this->displayConfirmation(
                sprintf(
                    '%s<br><br><strong>%s</strong><br><code style="font-size:14px;word-break:break-all">%s</code><br><br>%s',
                    $this->l('¡Módulo instalado correctamente!'),
                    $this->l('Secreto SSO generado (cópialo ahora — solo se muestra una vez):'),
                    $secret,
                    $this->l('Pégalo en el .env de tu TPV como SSO_SHARED_SECRET.')
                )
            );
            Configuration::deleteByName('TPVCONNECT_FIRST_INSTALL');
        }

        $link = $this->context->link;

        $this->context->smarty->assign([
            'module_name' => $this->name,
            'tpv_url' => Configuration::get('TPVCONNECT_URL'),
            'sso_secret' => Configuration::get('TPVCONNECT_SSO_SECRET'),
            'ws_key_id' => (int) Configuration::get('TPVCONNECT_WS_KEY_ID'),
            'ws_enabled' => (bool) Configuration::get('PS_WEBSERVICE'),
            'sso_url' => $this->getSsoUrl(),
            'shop_uri' => $link !== null ? $link->getBaseLink() : '',
            'admin_webservice_url' => $link !== null ? $link->getAdminLink('AdminWebservice') : '',
            'current' => $link !== null ? $link->getAdminLink('AdminModules', true) : '',
            'token' => Tools::getAdminTokenLite('AdminModules'),
        ]);

        $output .= $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . $this->name . '/views/templates/admin/configure.tpl'
        );

        return $output;
    }

    /**
     * Inject SSO redirect script into Back Office header.
     */
    public function hookDisplayBackOfficeHeader(array $params): string
    {
        $tpvUrl = Configuration::get('TPVCONNECT_URL');
        if (empty($tpvUrl)) {
            return '';
        }

        $employee = $this->context->employee;
        if (!$employee instanceof Employee || !(int) $employee->id) {
            return '';
        }

        $secret = Configuration::get('TPVCONNECT_SSO_SECRET');
        if (empty($secret)) {
            return '';
        }

        $ssoUrl = $this->getSsoUrl();

        $this->context->smarty->assign([
            'tpvconnect_sso_url' => $ssoUrl,
        ]);

        return $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . $this->name . '/views/templates/admin/bo_header.tpl'
        );
    }

    public function hookActionAdminControllerSetMedia(array $params): void
    {
        $this->context->controller->addCSS(
            $this->_path . 'views/css/admin.css'
        );
    }

    /**
     * Build the SSO redirect URL for the current employee.
     */
    public function getSsoUrl(): string
    {
        $tpvUrl = rtrim((string) Configuration::get('TPVCONNECT_URL'), '/');
        if (empty($tpvUrl)) {
            return '';
        }

        $employee = $this->context->employee;
        if (!$employee instanceof Employee || !(int) $employee->id) {
            return '';
        }

        $secret = (string) Configuration::get('TPVCONNECT_SSO_SECRET');
        if (empty($secret)) {
            return '';
        }

        $shopId = (int) $this->context->shop->id;
        $token = TpvConnect\SsoToken::generate((int) $employee->id, $shopId, $secret);

        return $tpvUrl . '/sso?token=' . urlencode($token);
    }

    /**
     * Validate and save admin configuration.
     */
    private function saveConfiguration(): string
    {
        $url = trim((string) Tools::getValue('TPVCONNECT_URL'));
        $secret = trim((string) Tools::getValue('TPVCONNECT_SSO_SECRET'));

        $errors = [];

        if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = $this->l('La URL del TPV no es válida.');
        }

        if (!empty($url) && strpos($url, 'https://') !== 0) {
            $errors[] = $this->l('La URL del TPV debe usar HTTPS.');
        }

        if (!empty($secret) && Tools::strlen($secret) < 32) {
            $errors[] = $this->l('El secreto SSO debe tener al menos 32 caracteres.');
        }

        if (!empty($errors)) {
            return $this->displayError(implode('<br>', $errors));
        }

        if (!empty($url)) {
            Configuration::updateValue('TPVCONNECT_URL', $url);
        }

        if (!empty($secret)) {
            Configuration::updateValue('TPVCONNECT_SSO_SECRET', $secret);
        }

        return $this->displayConfirmation($this->l('Configuración guardada correctamente.'));
    }

    /**
     * Regenerate the SSO shared secret.
     */
    private function regenerateSecret(): string
    {
        $newSecret = bin2hex(random_bytes(32));
        Configuration::updateValue('TPVCONNECT_SSO_SECRET', $newSecret);

        return $this->displayConfirmation($this->l('Secreto SSO regenerado. Recuerda actualizarlo también en la configuración del TPV.'));
    }

    /**
     * Generate a new PrestaShop Webservice key with read-only permissions.
     */
    private function generateWebserviceKey(): string
    {
        if (!Configuration::get('PS_WEBSERVICE')) {
            return $this->displayError($this->l('El servicio web de PrestaShop está deshabilitado. Actívalo primero.'));
        }

        $existingKeyId = (int) Configuration::get('TPVCONNECT_WS_KEY_ID');
        if ($existingKeyId > 0) {
            $existingKey = new WebserviceKey($existingKeyId);
            if (Validate::isLoadedObject($existingKey)) {
                $existingKey->delete();
            }
        }

        $keyValue = Tools::passwdGen(32);
        $wsKey = new WebserviceKey();
        $wsKey->key = $keyValue;
        $wsKey->description = 'TPV Connect API Key';
        $wsKey->active = true;

        if (!$wsKey->add()) {
            return $this->displayError($this->l('No se pudo crear la clave de servicio web.'));
        }

        // Permissions per resource
        $permissions = [
            'products'         => ['GET', 'HEAD'],
            'combinations'     => ['GET', 'HEAD'],
            'stock_availables'  => ['GET', 'PUT', 'HEAD'],
            'customers'         => ['GET', 'HEAD'],
            'categories'        => ['GET', 'HEAD'],
            'currencies'        => ['GET', 'HEAD'],
            'order_states'      => ['GET', 'HEAD'],
            'images'            => ['GET', 'HEAD'],
            'orders'            => ['GET', 'POST', 'PUT', 'HEAD'],
            'product_options'   => ['GET', 'HEAD'],
            'product_option_values' => ['GET', 'HEAD'],
        ];

        foreach ($permissions as $resource => $methods) {
            foreach ($methods as $method) {
                Db::getInstance()->insert('webservice_permission', [
                    'resource' => pSQL($resource),
                    'method' => pSQL($method),
                    'id_webservice_account' => (int) $wsKey->id,
                ]);
            }
        }

        Configuration::updateValue('TPVCONNECT_WS_KEY_ID', (int) $wsKey->id);

        // Store the key value temporarily in session to display once
        $this->context->cookie->__set('tpvconnect_ws_key_value', $keyValue);
        $this->context->cookie->write();

        return $this->displayConfirmation(
            $this->l('Clave de servicio web generada. Guárdala ahora; no se volverá a mostrar.') .
            '<br><code>' . $this->l('Clave: ') . Tools::htmlentitiesUTF8($keyValue) . '</code>'
        );
    }

    /**
     * Install admin tabs for the module.
     */
    private function installTab(): bool
    {
        // Main parent tab
        $parentTab = new Tab();
        $parentTab->active = true;
        $parentTab->class_name = 'AdminTpvConnect';
        $parentTab->name = [];

        foreach (Language::getLanguages(true) as $lang) {
            $parentTab->name[$lang['id_lang']] = 'TPV';
        }

        $parentTab->id_parent = (int) Tab::getIdFromClassName('AdminParentOrders');
        $parentTab->module = $this->name;

        if (!$parentTab->add()) {
            return false;
        }

        // Child tab (configuration)
        $childTab = new Tab();
        $childTab->active = true;
        $childTab->class_name = 'AdminTpvConnectConfig';
        $childTab->name = [];

        foreach (Language::getLanguages(true) as $lang) {
            $childTab->name[$lang['id_lang']] = 'Configuración';
        }

        $childTab->id_parent = (int) $parentTab->id;
        $childTab->module = $this->name;

        return $childTab->add();
    }

    /**
     * Remove admin tabs on uninstall.
     */
    private function uninstallTab(): bool
    {
        $tabs = Tab::getCollectionFromModule($this->name);

        foreach ($tabs as $tab) {
            $tab->delete();
        }

        return true;
    }

    /**
     * Check rate limit for a given IP address.
     */
    public static function checkRateLimit(string $ip): bool
    {
        $key = 'tpvconnect_rate_limit_' . md5($ip);
        $window = 60; // seconds
        $maxRequests = 60;

        $now = time();
        $data = self::getRateLimitData($key);

        // Clean expired entries
        $data = array_filter($data, static function ($timestamp) use ($now, $window) {
            return ($now - $timestamp) < $window;
        });

        if (count($data) >= $maxRequests) {
            return false;
        }

        $data[] = $now;
        self::setRateLimitData($key, $data);

        return true;
    }

    /**
     * Get rate limit data from file-based storage.
     */
    private static function getRateLimitData(string $key): array
    {
        $file = _PS_CACHE_DIR_ . $key . '.json';

        if (!file_exists($file)) {
            return [];
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Store rate limit data to file-based storage.
     */
    private static function setRateLimitData(string $key, array $data): void
    {
        $file = _PS_CACHE_DIR_ . $key . '.json';
        file_put_contents($file, json_encode($data), LOCK_EX);
    }
}
