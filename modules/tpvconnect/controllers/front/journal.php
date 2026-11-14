<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'tpvconnect/classes/JournalStore.php';

/**
 * Front controller for the shop copy of the TPV's fiscal journal (JournalStore).
 *
 * POST /module/tpvconnect/journal {"journal_id", "line": {...}} stores one line:
 * 201 {"stored": true, "duplicate": false}, 200 {"stored": false, "duplicate": true}
 * for the same line again, 400 line_hash mismatch, 409 another line at that
 * position or the document at another position.
 * POST /module/tpvconnect/journal {"journal_id", "action": "head"}
 * 200 {"journal_id", "last_seq", "series": {"A26": {"number", "hash", "seq"}, ...}}.
 * 401 bad signature, 405, 413 body over 1 MiB, 422 invalid body, 429 rate limited.
 */
class TpvconnectJournalModuleFrontController extends ModuleFrontController
{
    public function initContent(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->jsonResponse(['error' => 'Method not allowed'], 405);
        }

        // Own bucket, as pair.php: a backlog of journal uploads must never use up
        // the requests the till's orders and refunds need.
        if (!Tpvconnect::checkRateLimit('journal-' . $this->getClientIp())) {
            $this->jsonResponse(['error' => 'Rate limit exceeded'], 429);
        }

        // One byte over the limit is enough to answer 413 without reading the rest.
        $rawBody = (string) file_get_contents('php://input', false, null, 0, JournalStore::MAX_BODY_BYTES + 1);
        $authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? getallheaders()['Authorization']
            ?? '');

        try {
            [$status, $body] = (new JournalStore(new PsJournalStorage()))->respond(
                $rawBody,
                $authHeader,
                (string) Configuration::get('TPVCONNECT_SSO_SECRET')
            );
            $this->jsonResponse($body, $status);
        } catch (Throwable $e) {
            // Only the error class: a database message could quote the document.
            PrestaShopLogger::addLog('TPV Connect: journal line not stored: ' . get_class($e), 3, null, 'Module', 0, true);
            $this->jsonResponse(['error' => 'Journal storage failed'], 500);
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

    /** @param array<string, mixed>|object $data */
    private function jsonResponse($data, int $httpCode): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($data);
        exit;
    }
}
