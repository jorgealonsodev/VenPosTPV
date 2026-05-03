<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class TpvConnectInstaller
{
    private Module $module;

    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    public function run(): bool
    {
        return $this->installSql()
            && $this->createOrderStates()
            && $this->setConfigurationDefaults();
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
            'TPVCONNECT_WS_KEY_ID',
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
