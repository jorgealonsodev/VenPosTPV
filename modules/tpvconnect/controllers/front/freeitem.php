<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'tpvconnect/classes/FreeItemProductSetup.php';

/**
 * Front controller for the free item setup (docs/prd/articulo-libre.md, A5.2).
 *
 * POST /module/tpvconnect/freeitem {vat_rates: [21, 10]}
 * 200 {products: {"21.00": {id_product, slots: {"1": id_product_attribute, ...}}, ...}}:
 * the hidden generic products, created when missing (running it again returns the
 * same ids); 422 invalid body or a rate without tax rules group in the shop.
 */
class TpvconnectFreeitemModuleFrontController extends ModuleFrontController
{
    public function initContent(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['error' => 'Method not allowed'], 405);
        }

        $clientIp = $this->getClientIp();
        if (!Tpvconnect::checkRateLimit($clientIp)) {
            $this->jsonResponse(['error' => 'Rate limit exceeded'], 429);
        }

        $rawBody = (string) file_get_contents('php://input');
        $secret = (string) Configuration::get('TPVCONNECT_SSO_SECRET');
        if (!$this->verifyHmac($rawBody, $secret)) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            $this->jsonResponse(['error' => 'Validation failed', 'details' => ['body' => 'Invalid JSON']], 422);
        }
        $errors = FreeItemProductSetup::validateRequest($data);
        if ($errors !== []) {
            $this->jsonResponse(['error' => 'Validation failed', 'details' => $errors], 422);
        }

        try {
            $products = (new FreeItemProductSetup(new PsFreeItemSetupGateway()))->setup($data['vat_rates']);
            $this->jsonResponse(['products' => $products], 200);
        } catch (FreeItemSetupException $e) {
            $this->jsonResponse(['error' => 'Validation failed', 'details' => ['vat_rates' => $e->getMessage()]], 422);
        } catch (Throwable $e) {
            $this->jsonResponse(['error' => 'Free item setup failed', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * Verify HMAC-SHA256 signature from Authorization header.
     */
    private function verifyHmac(string $rawBody, string $secret): bool
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? getallheaders()['Authorization']
            ?? '';

        if (strpos($authHeader, 'HMAC-SHA256 ') !== 0) {
            return false;
        }

        $providedSignature = substr($authHeader, strlen('HMAC-SHA256 '));
        $expectedSignature = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expectedSignature, $providedSignature);
    }

    /**
     * Get client IP address.
     */
    private function getClientIp(): string
    {
        $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);

                return trim($ips[0]);
            }
        }

        return '0.0.0.0';
    }

    /**
     * Send JSON response and exit.
     */
    private function jsonResponse(array $data, int $httpCode): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
