<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'tpvconnect/classes/OrderLookupProcessor.php';

/**
 * Front controller for the order lookup used by TPV refunds of online orders.
 *
 * POST /module/tpvconnect/orderlookup {id_order} or {reference}
 * 200 {orders: [...]}, 401 bad signature, 404 no such order, 422 invalid body.
 * Read-only: it never changes the order.
 */
class TpvconnectOrderlookupModuleFrontController extends ModuleFrontController
{
    public function initContent(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['error' => 'Method not allowed'], 405);
        }

        if (!Tpvconnect::checkRateLimit($this->getClientIp())) {
            $this->jsonResponse(['error' => 'Rate limit exceeded'], 429);
        }

        $rawBody = (string) file_get_contents('php://input');
        $authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? getallheaders()['Authorization']
            ?? '');
        if (!OrderLookupProcessor::verifySignature($rawBody, (string) Configuration::get('TPVCONNECT_SSO_SECRET'), $authHeader)) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            $this->jsonResponse(['error' => 'Validation failed', 'details' => ['body' => 'Invalid JSON']], 422);
        }

        try {
            $this->jsonResponse((new OrderLookupProcessor(new PsOrderLookupGateway()))->process($data), 200);
        } catch (RefundRequestException $e) {
            $this->jsonResponse(['error' => $e->getMessage(), 'details' => $e->getDetails()], $e->getHttpCode());
        } catch (Throwable $e) {
            $this->jsonResponse(['error' => 'Order lookup failed', 'details' => $e->getMessage()], 500);
        }
    }

    private function getClientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                return trim(explode(',', $_SERVER[$key])[0]);
            }
        }

        return '0.0.0.0';
    }

    private function jsonResponse(array $data, int $httpCode): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
