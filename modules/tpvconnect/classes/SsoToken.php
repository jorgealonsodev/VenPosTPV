<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace TpvConnect;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * SSO token generator and verifier.
 *
 * Format: base64url(json(payload))~hmac_sha256
 * Payload: {employee_id, shop_id, timestamp, nonce}
 */
class SsoToken
{
    private const TOKEN_TTL = 60;
    private const NONCE_TTL = 120;
    private const NONCE_PREFIX = 'TPVCONNECT_NONCE_';

    /**
     * Generate a new SSO token.
     */
    public static function generate(int $employeeId, int $shopId, string $secret): string
    {
        self::cleanupNonces();

        $nonce = bin2hex(random_bytes(16));
        $timestamp = time();

        $payload = json_encode([
            'employee_id' => $employeeId,
            'shop_id' => $shopId,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
        ]);

        $encodedPayload = self::base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $encodedPayload, $secret);

        // Store nonce with timestamp for replay protection
        self::storeNonce($nonce, $timestamp);

        return $encodedPayload . '~' . $signature;
    }

    /**
     * Verify an SSO token.
     *
     * @return array|null Decoded payload or null if invalid
     */
    public static function verify(string $token, string $secret): ?array
    {
        $parts = explode('~', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encodedPayload, $signature] = $parts;

        // Verify HMAC
        $expectedSignature = hash_hmac('sha256', $encodedPayload, $secret);
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        // Decode payload
        $payload = self::base64UrlDecode($encodedPayload);
        if ($payload === false) {
            return null;
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return null;
        }

        // Validate required fields
        if (!isset($data['employee_id'], $data['shop_id'], $data['timestamp'], $data['nonce'])) {
            return null;
        }

        // Check expiry
        if ((time() - (int) $data['timestamp']) > self::TOKEN_TTL) {
            return null;
        }

        // Check nonce (must exist and not be reused)
        if (!self::validateNonce((string) $data['nonce'])) {
            return null;
        }

        // Consume nonce to prevent replay
        self::consumeNonce((string) $data['nonce']);

        return $data;
    }

    /**
     * Base64URL-encode a string.
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64URL-decode a string.
     */
    public static function base64UrlDecode(string $data): string|false
    {
        $padded = str_pad(
            strtr($data, '-_', '+/'),
            (int) (4 * ceil(\Tools::strlen($data) / 4)),
            '=',
            STR_PAD_RIGHT
        );

        return base64_decode($padded, true);
    }

    /**
     * Store nonce in Configuration.
     */
    private static function storeNonce(string $nonce, int $timestamp): void
    {
        \Configuration::updateValue(self::NONCE_PREFIX . $nonce, (string) $timestamp);
    }

    /**
     * Validate nonce exists and is within TTL.
     */
    private static function validateNonce(string $nonce): bool
    {
        $value = \Configuration::get(self::NONCE_PREFIX . $nonce);

        if ($value === false || $value === null || $value === '') {
            return false;
        }

        $timestamp = (int) $value;

        return (time() - $timestamp) <= self::NONCE_TTL;
    }

    /**
     * Consume (delete) a nonce after successful verification.
     */
    private static function consumeNonce(string $nonce): void
    {
        \Configuration::deleteByName(self::NONCE_PREFIX . $nonce);
    }

    /**
     * Clean up expired nonces from Configuration.
     */
    private static function cleanupNonces(): void
    {
        $now = time();
        $sql = 'SELECT `name` FROM `' . _DB_PREFIX_ . "configuration`
            WHERE `name` LIKE '" . pSQL(self::NONCE_PREFIX) . "%'";
        $rows = \Db::getInstance()->executeS($sql);

        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $value = \Configuration::get($row['name']);
            if ($value === false || $value === null || $value === '') {
                \Configuration::deleteByName($row['name']);
                continue;
            }

            if (($now - (int) $value) > self::NONCE_TTL) {
                \Configuration::deleteByName($row['name']);
            }
        }
    }
}
