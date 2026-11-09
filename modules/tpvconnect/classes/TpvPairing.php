<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

// No class_exists() guard: TpvPairing is declared at compile time, so a guard on it
// returned before the classes below were declared. Every include is require_once.

/** A refused pairing request: a stable error code for the TPV and its HTTP status. */
class TpvPairingException extends Exception
{
    private string $errorCode;
    private int $httpCode;

    public function __construct(string $errorCode, int $httpCode)
    {
        parent::__construct($errorCode);
        $this->errorCode = $errorCode;
        $this->httpCode = $httpCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }
}

/** What the TPV's .env needs to know about the shop the request reached. */
interface TpvPairingShop
{
    public function shopId(): int;

    public function shopUrl(): string;

    public function currencyId(): int;

    public function timezone(): string;
}

/** The running shop (front controller context). */
class PsTpvPairingShop implements TpvPairingShop
{
    public function shopId(): int
    {
        return (int) Context::getContext()->shop->id;
    }

    public function shopUrl(): string
    {
        return (string) Context::getContext()->shop->getBaseURL(true);
    }

    public function currencyId(): int
    {
        return (int) Configuration::get('PS_CURRENCY_DEFAULT');
    }

    public function timezone(): string
    {
        return (string) Configuration::get('PS_TIMEZONE');
    }
}

/**
 * Pairs a TPV with this shop through a one-time 6-digit code, so nobody copies
 * keys by hand. The configuration page issues the code (issueCode()); the TPV
 * installer sends it with its own address to POST /module/tpvconnect/pair
 * (redeem()), which gets the webservice key, the SSO secret (the HMAC key of the
 * signed module endpoints too) and the shop's ids.
 *
 * The code is stored only as a salted SHA-256 with its expiry and an attempt
 * counter. It lasts 10 minutes, works once, and MAX_ATTEMPTS wrong codes
 * invalidate it, so guessing 1 in 1 000 000 five times is all an attacker gets
 * per code an administrator issues. Every refusal answers the same error, so
 * nobody learns whether a code exists.
 */
final class TpvPairing
{
    public const CODE_TTL_SECONDS = 600;
    public const MAX_ATTEMPTS = 5;
    public const CONFIG_CODE = 'TPVCONNECT_PAIR_CODE';
    public const CONFIG_EXPIRES = 'TPVCONNECT_PAIR_EXPIRES';
    public const CONFIG_ATTEMPTS = 'TPVCONNECT_PAIR_ATTEMPTS';
    public const KEY_DESCRIPTION = 'TPV Connect API Key';
    private const LOCK_NAME = 'tpvconnect_pair';
    private const LOCK_WAIT_SECONDS = 5;

    private TpvConnectInstaller $installer;
    private TpvPairingShop $shop;
    private string $moduleVersion;
    /** @var Closure(): int */
    private Closure $clock;

    public function __construct(TpvConnectInstaller $installer, TpvPairingShop $shop, string $moduleVersion, ?Closure $clock = null)
    {
        $this->installer = $installer;
        $this->shop = $shop;
        $this->moduleVersion = $moduleVersion;
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * A fresh code (CSPRNG) that replaces any previous one.
     *
     * @return array{code: string, expires_at: int}
     */
    public function issueCode(int $employeeId = 0): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $salt = bin2hex(random_bytes(16));
        $expiresAt = ($this->clock)() + self::CODE_TTL_SECONDS;

        Configuration::updateValue(self::CONFIG_CODE, $salt . ':' . self::hash($salt, $code));
        Configuration::updateValue(self::CONFIG_EXPIRES, (string) $expiresAt);
        Configuration::updateValue(self::CONFIG_ATTEMPTS, '0');
        self::log(sprintf('TPV Connect: pairing code issued by employee %d, valid until %s.', $employeeId, gmdate('Y-m-d H:i:s', $expiresAt) . ' UTC'), 1);

        return ['code' => $code, 'expires_at' => $expiresAt];
    }

    /**
     * Redeems a code: on success the webservice is on, the TPV's key exists with
     * every permission (an existing key keeps its value), the SSO secret exists,
     * TPVCONNECT_URL is the TPV's address and the code is gone.
     *
     * @param array<string, mixed> $input {code, tpv_url}
     *
     * @return array{api_key: string, sso_secret: string, shop_id: int, shop_url: string, currency_id: int, timezone: string, module_version: string}
     *
     * @throws TpvPairingException
     */
    public function redeem(array $input, string $clientIp): array
    {
        $tpvUrl = self::normalizeTpvUrl(is_string($input['tpv_url'] ?? null) ? $input['tpv_url'] : '');
        if ($tpvUrl === null) {
            throw new TpvPairingException('invalid_tpv_url', 422);
        }
        $given = preg_replace('/[\s-]+/', '', is_string($input['code'] ?? null) ? $input['code'] : '');

        if (!$this->acquireLock()) {
            throw new TpvPairingException('busy', 503);
        }
        try {
            // Another request may have used or counted the code since this one
            // loaded the configuration: read it again under the lock.
            if (method_exists('Configuration', 'loadConfiguration')) {
                Configuration::loadConfiguration();
            }
            $this->consume((string) $given, $clientIp);

            $key = $this->ensureWebserviceKey();
            $secret = $this->ensureSsoSecret();
            Configuration::updateValue('TPVCONNECT_URL', $tpvUrl);
        } finally {
            $this->releaseLock();
        }

        self::log(sprintf(
            'TPV Connect: TPV %s paired from IP %s (webservice key id %d %s).',
            $tpvUrl,
            $clientIp,
            $key['id'],
            $key['created'] ? 'created' : 'reused'
        ), 1);

        return [
            'api_key' => $key['key'],
            'sso_secret' => $secret,
            'shop_id' => $this->shop->shopId(),
            'shop_url' => rtrim($this->shop->shopUrl(), '/'),
            'currency_id' => $this->shop->currencyId(),
            'timezone' => $this->shop->timezone(),
            'module_version' => $this->moduleVersion,
        ];
    }

    /**
     * The TPV's public address: https, or http on localhost only; no user,
     * query or fragment. Without the trailing slash, or null when not allowed.
     */
    public static function normalizeTpvUrl(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '' || strlen($url) > 255 || preg_match('/[\x00-\x20\x7F]/', $url) === 1
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme === 'https' || ($scheme === 'http' && self::isLocalHost($host))) {
            return $url;
        }

        return null;
    }

    /** The code and the keys only travel over HTTPS, except on a local test shop. */
    public static function isRequestAllowed(bool $https, string $hostHeader): bool
    {
        if ($https) {
            return true;
        }
        $host = strtolower((string) parse_url('http://' . $hostHeader, PHP_URL_HOST));

        return self::isLocalHost($host);
    }

    private static function isLocalHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /** @throws TpvPairingException when there is no valid code or it does not match */
    private function consume(string $given, string $clientIp): void
    {
        $stored = (string) Configuration::get(self::CONFIG_CODE);
        $expiresAt = (int) Configuration::get(self::CONFIG_EXPIRES);
        $parts = explode(':', $stored);
        if (count($parts) !== 2 || $expiresAt <= ($this->clock)()) {
            $this->clearCode();
            throw new TpvPairingException('invalid_code', 403);
        }
        [$salt, $hash] = $parts;

        // Constant-time compare; a malformed code is hashed too, so it costs the same.
        if (preg_match('/^\d{6}$/', $given) === 1 && hash_equals($hash, self::hash($salt, $given))) {
            $this->clearCode();

            return;
        }

        $attempts = (int) Configuration::get(self::CONFIG_ATTEMPTS) + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->clearCode();
            self::log(sprintf('TPV Connect: pairing code invalidated after %d wrong attempts (last from IP %s).', $attempts, $clientIp), 2);
        } else {
            Configuration::updateValue(self::CONFIG_ATTEMPTS, (string) $attempts);
        }
        throw new TpvPairingException('invalid_code', 403);
    }

    private function clearCode(): void
    {
        Configuration::deleteByName(self::CONFIG_CODE);
        Configuration::deleteByName(self::CONFIG_EXPIRES);
        Configuration::deleteByName(self::CONFIG_ATTEMPTS);
    }

    /**
     * The key in TPVCONNECT_WS_KEY_ID keeps its value (a TPV may already use it);
     * it is made active and given every WEBSERVICE_PERMISSIONS entry. Without one,
     * a new 32-character key is created. The webservice is turned on.
     *
     * @return array{id: int, key: string, created: bool}
     */
    private function ensureWebserviceKey(): array
    {
        if (!Configuration::get('PS_WEBSERVICE')) {
            Configuration::updateValue('PS_WEBSERVICE', '1');
        }

        $created = false;
        $wsKey = new WebserviceKey((int) Configuration::get('TPVCONNECT_WS_KEY_ID'));
        if ((int) Configuration::get('TPVCONNECT_WS_KEY_ID') > 0 && Validate::isLoadedObject($wsKey) && (int) $wsKey->id > 0) {
            if (!$wsKey->active) {
                $wsKey->active = true;
                $wsKey->update();
            }
        } else {
            $wsKey = new WebserviceKey();
            $wsKey->key = strtoupper(bin2hex(random_bytes(16)));
            $wsKey->description = self::KEY_DESCRIPTION;
            $wsKey->active = true;
            if (!$wsKey->add()) {
                throw new TpvPairingException('key_failed', 500);
            }
            Configuration::updateValue('TPVCONNECT_WS_KEY_ID', (string) (int) $wsKey->id);
            $created = true;
        }
        if (!$this->installer->grantWebservicePermissions((int) $wsKey->id)) {
            throw new TpvPairingException('key_failed', 500);
        }

        return ['id' => (int) $wsKey->id, 'key' => (string) $wsKey->key, 'created' => $created];
    }

    /** The existing secret is never rotated here; only a missing one is created. */
    private function ensureSsoSecret(): string
    {
        $secret = (string) Configuration::get('TPVCONNECT_SSO_SECRET');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            Configuration::updateValue('TPVCONNECT_SSO_SECRET', $secret);
        }

        return $secret;
    }

    private static function hash(string $salt, string $code): string
    {
        return hash('sha256', $salt . ':' . $code);
    }

    private static function log(string $message, int $severity): void
    {
        if (class_exists('PrestaShopLogger')) {
            PrestaShopLogger::addLog($message, $severity, null, 'Module', 0, true);
        }
    }

    private function acquireLock(): bool
    {
        $rows = Db::getInstance()->executeS("SELECT GET_LOCK('" . pSQL(self::LOCK_NAME) . "', " . self::LOCK_WAIT_SECONDS . ') AS locked', true, false);

        return is_array($rows) && isset($rows[0]['locked']) && (int) $rows[0]['locked'] === 1;
    }

    private function releaseLock(): void
    {
        Db::getInstance()->executeS("SELECT RELEASE_LOCK('" . pSQL(self::LOCK_NAME) . "') AS released", true, false);
    }
}
