<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'tpvconnect/classes/SsoToken.php';
require_once _PS_MODULE_DIR_ . 'tpvconnect/classes/RefundProcessor.php';

/**
 * Front controller for refund creation endpoint.
 *
 * POST /module/tpvconnect/refunds
 */
class TpvconnectRefundsModuleFrontController extends ModuleFrontController
{
    private const RATE_LIMIT_MAX = 60;
    private const RATE_LIMIT_WINDOW = 60;

    public function initContent(): void
    {
        // Only accept POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['error' => 'Method not allowed'], 405);
        }

        // Rate limiting
        $clientIp = $this->getClientIp();
        if (!Tpvconnect::checkRateLimit($clientIp)) {
            $this->jsonResponse(['error' => 'Rate limit exceeded'], 429);
        }

        // HMAC authentication
        $rawBody = (string) file_get_contents('php://input');
        $secret = (string) Configuration::get('TPVCONNECT_SSO_SECRET');

        if (!$this->verifyHmac($rawBody, $secret)) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
        }

        // Parse and validate JSON body
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            $this->jsonResponse(['error' => 'Validation failed', 'details' => ['body' => 'Invalid JSON']], 422);
        }

        // Validation, quantity/amount caps, idempotency and restock live in
        // RefundProcessor so they can be tested without a shop.
        try {
            $processor = new RefundProcessor(new PsRefundGateway(), (int) Configuration::get('TPV_OS_REFUND'));
            $result = $processor->process($data);
            $this->jsonResponse($result, $result['replayed'] ? 200 : 201);
        } catch (RefundRequestException $e) {
            $this->jsonResponse(['error' => $e->getMessage(), 'details' => $e->getDetails()], $e->getHttpCode());
        } catch (Throwable $e) {
            $this->jsonResponse(['error' => 'Refund creation failed', 'details' => $e->getMessage()], 500);
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
