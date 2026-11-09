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
require_once __DIR__ . '/classes/ReferencedOrderCreator.php';
require_once __DIR__ . '/classes/FreeItemOrderLines.php';
require_once __DIR__ . '/classes/TpvPairing.php';

class Tpvconnect extends PaymentModule
{
    public function __construct()
    {
        $this->name = 'tpvconnect';
        $this->tab = 'administration';
        $this->version = '0.8.0';
        $this->author = 'VenPosTPV';
        $this->need_instance = 1;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = [
            'min' => '1.7.7.0',
            'max' => '9.99.99',
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

        $installer = new TpvConnectInstaller($this);
        if (!$installer->registerHooks() || !$installer->run()) {
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

        foreach (TpvConnectInstaller::HOOKS as $hook) {
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

        if (Tools::isSubmit('submit_tpvconnect_pair')) {
            $output .= $this->showPairingCode();
        }

        // After the first install: pairing exchanges every key, nothing to copy.
        if (Configuration::get('TPVCONNECT_FIRST_INSTALL')) {
            $output .= $this->displayConfirmation(
                sprintf(
                    '%s<br><br>%s',
                    $this->l('¡Módulo instalado correctamente!'),
                    $this->l('Para conectar el TPV, pulsa «Emparejar con un TPV» y escribe el código y la dirección de la tienda en el instalador del TPV. El TPV y la tienda se intercambian solos las claves.')
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

    /**
     * Order::generateReference() asks this hook first. Only an order this module
     * is creating in this request (the TPV's ticket code) gets a reference here;
     * anything else gets '' and keeps PrestaShop's own reference.
     */
    public function hookActionGenerateDocumentReference(array $params): string
    {
        return TpvOrderReference::claim((string) ($params['type'] ?? ''));
    }

    /**
     * The order confirmation e-mail lists the cart's products before the module
     * renames a free item's order line: give it the concept too, only for the
     * free lines of the order this request is creating (articulo-libre.md, A5.4).
     */
    public function hookActionPaymentModuleProductVarTplAfter(array $params): void
    {
        $product = $params['product'] ?? null;
        if (!is_array($product) || !isset($params['product_var_tpl']) || !is_array($params['product_var_tpl'])) {
            return;
        }
        $concept = TpvFreeItemNames::nameFor((int) ($product['id_product'] ?? 0), (int) ($product['id_product_attribute'] ?? 0));
        if ($concept !== null) {
            $params['product_var_tpl']['name'] = $concept;
        }
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
     * A fresh pairing code (TpvPairing): the configuration page and the CLI use
     * this same method. The code is returned once and stored only as a hash.
     *
     * @return array{code: string, expires_at: int}
     */
    public function issuePairingCode(int $employeeId = 0): array
    {
        return (new TpvPairing(new TpvConnectInstaller($this), new PsTpvPairingShop(), $this->version))->issueCode($employeeId);
    }

    /** The shop address to type in the TPV installer next to the code. */
    public function getPairingShopUrl(): string
    {
        return rtrim((new PsTpvPairingShop())->shopUrl(), '/');
    }

    /**
     * «Emparejar con un TPV»: shows a new code and the shop address to type in
     * the TPV installer (or in Ajustes → Acerca de of an installed TPV).
     */
    private function showPairingCode(): string
    {
        $employee = $this->context->employee;
        $issued = $this->issuePairingCode($employee instanceof Employee ? (int) $employee->id : 0);
        $code = $issued['code'];

        return $this->displayConfirmation(sprintf(
            '<strong>%s</strong><br><br>%s<br><code style="font-size:28px;letter-spacing:6px">%s %s</code><br><br>%s<br><code style="font-size:15px">%s</code><br><br>%s',
            $this->l('Código de emparejamiento'),
            $this->l('Escríbelo en el instalador del TPV (o en Ajustes → Acerca de → «Volver a emparejar con la tienda»):'),
            Tools::htmlentitiesUTF8(substr($code, 0, 3)),
            Tools::htmlentitiesUTF8(substr($code, 3)),
            $this->l('Dirección de la tienda:'),
            Tools::htmlentitiesUTF8($this->getPairingShopUrl()),
            sprintf(
                $this->l('Vale 10 minutos (hasta las %s) y una sola vez. Si pulsas otra vez el botón, este código deja de valer.'),
                date('H:i', $issued['expires_at'])
            )
        ));
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

        // A new key gets the current permission list (TpvConnectInstaller::WEBSERVICE_PERMISSIONS).
        (new TpvConnectInstaller($this))->grantWebservicePermissions((int) $wsKey->id);

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
