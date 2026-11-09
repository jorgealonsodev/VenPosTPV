<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'tpvconnect/install/install.php';
require_once _PS_MODULE_DIR_ . 'tpvconnect/classes/TpvPairing.php';

/**
 * Pairing endpoint (TpvPairing), called by the TPV installer and by Ajustes →
 * Acerca de of an installed TPV. Public: the one-time code is the credential.
 *
 * POST /module/tpvconnect/pair {"code": "123456", "tpv_url": "https://tpv.example.com"}
 * 200 {api_key, sso_secret, shop_id, shop_url, currency_id, timezone, module_version}
 * 403 {"error": "invalid_code"} for a wrong, expired, used or missing code (the same
 * answer for all of them), 403 https_required, 405, 422 invalid_tpv_url / invalid_body,
 * 429 rate limited, 503 busy. Never cached.
 */
class TpvconnectPairModuleFrontController extends ModuleFrontController
{
    private const MAX_BODY_BYTES = 4096;

    public function initContent(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->jsonResponse(['error' => 'method_not_allowed'], 405);
        }

        if (!TpvPairing::isRequestAllowed(Tools::usingSecureMode(), (string) ($_SERVER['HTTP_HOST'] ?? ''))) {
            $this->jsonResponse(['error' => 'https_required'], 403);
        }

        // REMOTE_ADDR only: X-Forwarded-For is whatever the client wants it to be.
        $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!Tpvconnect::checkRateLimit('pair-' . $clientIp)) {
            $this->jsonResponse(['error' => 'rate_limited'], 429);
        }

        $rawBody = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        $data = strlen($rawBody) > self::MAX_BODY_BYTES ? null : json_decode($rawBody, true);
        if (!is_array($data)) {
            $this->jsonResponse(['error' => 'invalid_body'], 422);
        }

        try {
            $pairing = new TpvPairing(new TpvConnectInstaller($this->module), new PsTpvPairingShop(), (string) $this->module->version);
            $this->jsonResponse($pairing->redeem($data, $clientIp), 200);
        } catch (TpvPairingException $e) {
            $this->jsonResponse(['error' => $e->getErrorCode()], $e->getHttpCode());
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('TPV Connect: pairing failed: ' . get_class($e), 3, null, 'Module', 0, true);
            $this->jsonResponse(['error' => 'pairing_failed'], 500);
        }
    }

    /** JSON answer that no browser, proxy or CDN may keep. */
    private function jsonResponse(array $data, int $httpCode): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
        echo json_encode($data);
        exit;
    }
}
