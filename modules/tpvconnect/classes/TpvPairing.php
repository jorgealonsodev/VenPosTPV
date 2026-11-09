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
 *
 * Every read and write of the code, its expiry and its attempt counter happens
 * under one MySQL lock, and redeem() reads them straight from the configuration
 * table: Configuration::get() answers this request's static cache, and in
 * PrestaShop 9.2 Configuration::loadConfiguration() only merges rows into it, so
 * a code another request used or cancelled would still be there.
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
     *
     * @throws TpvPairingException busy (503) when a redeem holds the lock too long
     */
    public function issueCode(int $employeeId = 0): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $salt = bin2hex(random_bytes(16));
        $expiresAt = ($this->clock)() + self::CODE_TTL_SECONDS;

        if (!$this->acquireLock()) {
            throw new TpvPairingException('busy', 503);
        }
        try {
            // updateValue() decides between UPDATE and INSERT from the static
            // cache; deleting first resets that cache, so the rows are inserted.
            $this->clearCode();
            Configuration::updateValue(self::CONFIG_CODE, $salt . ':' . self::hash($salt, $code));
            Configuration::updateValue(self::CONFIG_EXPIRES, (string) $expiresAt);
            Configuration::updateValue(self::CONFIG_ATTEMPTS, '0');
        } finally {
            $this->releaseLock();
        }
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
            $this->consume((string) $given, $clientIp);

            $key = $this->ensureWebserviceKey();
            $secret = $this->ensureSsoSecret();
            $previousRow = self::readConfigurationRow('TPVCONNECT_URL');
            $previousUrl = $previousRow === null ? '' : $previousRow['value'];
            Configuration::updateValue('TPVCONNECT_URL', $tpvUrl);
        } finally {
            $this->releaseLock();
        }

        self::log(sprintf(
            'TPV Connect: TPV %s paired from IP %s (webservice key id %d %s; previous TPV address %s).',
            $tpvUrl,
            $clientIp,
            $key['id'],
            $key['created'] ? 'created' : 'reused',
            $previousUrl === '' ? 'none' : $previousUrl
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
        // No whitespace, control, non-ASCII, quote, angle bracket or backtick
        // anywhere: the address ends up in .env files, HTML and logs.
        if ($url === '' || strlen($url) > 255 || preg_match('/[^\x21-\x7E]|[\'"<>`]/', $url) === 1
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

    /**
     * The code and the keys only travel over HTTPS. The decision uses what the
     * web server knows, never the client's Host header:
     * - TLS this web server terminated (HTTPS, SSL or REDIRECT_HTTPS on);
     * - a client on this machine (REMOTE_ADDR 127.0.0.1 or ::1); when it is a
     *   reverse proxy (X-Forwarded-* or Forwarded present), only if it says the
     *   client used HTTPS. X-Forwarded-Proto and HTTP_SSL are trusted from it only;
     * - a local test shop (its own configured domain is localhost) reached from a
     *   private network, such as a Docker shop seen from its host.
     *
     * @param array<string, mixed> $server $_SERVER
     */
    public static function isRequestAllowed(array $server, string $shopDomain): bool
    {
        foreach (['HTTPS', 'SSL', 'REDIRECT_HTTPS'] as $variable) {
            if (self::isOn($server[$variable] ?? null)) {
                return true;
            }
        }

        $remoteAddr = (string) ($server['REMOTE_ADDR'] ?? '');
        if (in_array($remoteAddr, ['127.0.0.1', '::1'], true)) {
            $proxied = isset($server['HTTP_X_FORWARDED_FOR']) || isset($server['HTTP_X_FORWARDED_PROTO'])
                || isset($server['HTTP_FORWARDED']) || isset($server['HTTP_SSL']);

            return !$proxied
                || strtolower(trim((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https'
                || self::isOn($server['HTTP_SSL'] ?? null);
        }

        $shopHost = strtolower((string) parse_url('http://' . $shopDomain, PHP_URL_HOST));

        return self::isLocalHost($shopHost)
            && filter_var($remoteAddr, FILTER_VALIDATE_IP) !== false
            && filter_var($remoteAddr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;
    }

    private static function isOn(mixed $value): bool
    {
        return is_scalar($value) && in_array(strtolower((string) $value), ['1', 'on'], true);
    }

    private static function isLocalHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /**
     * Checks the code against the configuration table (never the static cache)
     * and, under the caller's lock, either consumes it or counts the attempt.
     *
     * @throws TpvPairingException when there is no valid code or it does not match
     */
    private function consume(string $given, string $clientIp): void
    {
        $codeRow = self::readConfigurationRow(self::CONFIG_CODE);
        $expiresRow = self::readConfigurationRow(self::CONFIG_EXPIRES);
        $attemptsRow = self::readConfigurationRow(self::CONFIG_ATTEMPTS);
        $parts = explode(':', $codeRow === null ? '' : $codeRow['value']);
        if (count($parts) !== 2 || $expiresRow === null || $attemptsRow === null
            || (int) $expiresRow['value'] <= ($this->clock)()) {
            $this->clearCode();
            throw new TpvPairingException('invalid_code', 403);
        }
        [$salt, $hash] = $parts;

        // Constant-time compare; a malformed code is hashed too, so it costs the same.
        if (preg_match('/^\d{6}$/', $given) === 1 && hash_equals($hash, self::hash($salt, $given))) {
            $this->clearCode();

            return;
        }

        $attempts = (int) $attemptsRow['value'] + 1;
        if ($attempts >= self::MAX_ATTEMPTS || !self::writeConfigurationRow($attemptsRow['id'], (string) $attempts)) {
            $this->clearCode();
            self::log(sprintf('TPV Connect: pairing code invalidated after %d wrong attempts (last from IP %s).', $attempts, $clientIp), 2);
        }
        throw new TpvPairingException('invalid_code', 403);
    }

    /**
     * The configuration row Configuration::get() would answer in this shop
     * context (shop, then group, then global; the context updateValue() writes
     * in), read from the table past the SQL cache.
     *
     * @return array{id: int, value: string}|null
     */
    private static function readConfigurationRow(string $key): ?array
    {
        $idShop = (int) Shop::getContextShopID(true);
        $idShopGroup = (int) Shop::getContextShopGroupID(true);
        $scopes = ['((`id_shop_group` IS NULL OR `id_shop_group` = 0) AND (`id_shop` IS NULL OR `id_shop` = 0))'];
        if ($idShopGroup > 0) {
            $scopes[] = '(`id_shop_group` = ' . $idShopGroup . ' AND (`id_shop` IS NULL OR `id_shop` = 0))';
        }
        if ($idShop > 0) {
            $scopes[] = '`id_shop` = ' . $idShop;
        }

        $row = Db::getInstance()->getRow(
            'SELECT `id_configuration`, `value` FROM `' . _DB_PREFIX_ . 'configuration`'
            . " WHERE `name` = '" . pSQL($key) . "' AND (" . implode(' OR ', $scopes) . ')'
            . ' ORDER BY `id_shop` DESC, `id_shop_group` DESC',
            false
        );
        if (!is_array($row) || !isset($row['id_configuration'])) {
            return null;
        }

        return ['id' => (int) $row['id_configuration'], 'value' => (string) ($row['value'] ?? '')];
    }

    /** Writes the row read under the lock by its id; updateValue() would trust the cache. */
    private static function writeConfigurationRow(int $id, string $value): bool
    {
        return (bool) Db::getInstance()->update(
            'configuration',
            ['value' => pSQL($value), 'date_upd' => date('Y-m-d H:i:s')],
            '`id_configuration` = ' . $id,
            1
        );
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
