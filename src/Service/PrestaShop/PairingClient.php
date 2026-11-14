<?php

declare(strict_types=1);

namespace VenPosTPV\Service\PrestaShop;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pairs the TPV with a shop through the 6-digit code its TPV Connect module shows
 * (POST /module/tpvconnect/pair, module 0.8.0+): the shop answers its webservice
 * key, the SSO secret and its ids, and stores the TPV's address. Used by the web
 * installer and by Ajustes → Acerca de. Messages are Spanish and never hold a secret.
 */
final class PairingClient
{
    public const MIN_MODULE_VERSION = '0.8.0';

    private const MSG_CODE_FORMAT = 'El código de emparejamiento tiene 6 cifras. Pulsa «Emparejar con un TPV» en el módulo TPV Connect de PrestaShop para ver uno.';
    private const MSG_SHOP_URL = 'La dirección de la tienda debe ser una URL https:// válida (por ejemplo https://tutienda.com).';
    private const MSG_MODULE = 'La tienda no tiene el módulo TPV Connect ' . self::MIN_MODULE_VERSION . ' o superior. Instala o actualiza el módulo en PrestaShop (Módulos → Gestor de módulos → Subir un módulo, con tpvconnect.zip) y vuelve a intentarlo.';

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * "123 456" → "123456"; null unless it is 6 digits. A pasted code may bring any
     * kind of space (no-break ones too), line breaks or a dash: all are dropped.
     */
    public static function normalizeCode(string $code): ?string
    {
        // null on text that is not UTF-8, which then is not 6 digits either.
        $code = (string) preg_replace('/[\s\p{Z}\p{Pd}\x{200B}\x{FEFF}]+/u', '', $code);

        return preg_match('/^\d{6}$/', $code) === 1 ? $code : null;
    }

    /** https, or http on localhost; without the trailing slash, or null. */
    public static function normalizeShopUrl(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);
        if (!\is_array($parts) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        return $scheme === 'https' || ($scheme === 'http' && \in_array($host, ['localhost', '127.0.0.1'], true)) ? $url : null;
    }

    /** @throws PairingException */
    public static function assertCode(string $code): string
    {
        return self::normalizeCode($code) ?? throw new PairingException(self::MSG_CODE_FORMAT);
    }

    /** @throws PairingException */
    public static function assertShopUrl(string $url): string
    {
        return self::normalizeShopUrl($url) ?? throw new PairingException(self::MSG_SHOP_URL);
    }

    /**
     * @param string $tpvUrl the TPV's public address, stored by the module as TPVCONNECT_URL
     *
     * @throws PairingException
     */
    public function pair(string $shopUrl, #[\SensitiveParameter] string $code, string $tpvUrl): PairingResult
    {
        $shopUrl = self::assertShopUrl($shopUrl);
        $code = self::assertCode($code);

        try {
            $response = $this->httpClient->request('POST', $shopUrl . '/module/tpvconnect/pair', [
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'body' => (string) json_encode(['code' => $code, 'tpv_url' => rtrim($tpvUrl, '/')]),
                // A redirect would resend the code elsewhere (or drop it): name the address instead.
                'max_redirects' => 0,
                'timeout' => 20,
                'max_duration' => 30,
            ]);
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface) {
            throw new PairingException(sprintf('No se pudo conectar con la tienda en %s. Revisa la dirección y que la tienda esté en línea.', $shopUrl));
        }

        if ($status >= 300 && $status < 400) {
            throw new PairingException(self::redirectMessage((string) ($headers['location'][0] ?? '')));
        }
        $data = json_decode($content, true);
        $error = \is_array($data) && \is_string($data['error'] ?? null) ? $data['error'] : null;

        if ($status !== 200) {
            throw new PairingException(match (true) {
                $error === 'invalid_code' => 'El código de emparejamiento no es correcto o ha caducado. Pulsa otra vez «Emparejar con un TPV» en el módulo TPV Connect y escribe el código nuevo (vale 10 minutos).',
                $error === 'invalid_tpv_url' => sprintf('La tienda no acepta la dirección del TPV (%s): tiene que ser https://.', $tpvUrl),
                $error === 'https_required' => 'La tienda solo acepta el emparejamiento por https://. Escribe la dirección de la tienda con https://.',
                $error === 'rate_limited' || $error === 'busy' || $status === 429 => 'La tienda está ocupada o ha recibido demasiados intentos. Espera un minuto y vuelve a intentarlo.',
                $error !== null && $status >= 500 => 'La tienda no pudo completar el emparejamiento. Revisa el registro de PrestaShop (Parámetros avanzados → Registros) y vuelve a intentarlo con un código nuevo.',
                default => self::MSG_MODULE,
            });
        }

        return self::result($shopUrl, $data);
    }

    /** @throws PairingException */
    private static function result(string $shopUrl, mixed $data): PairingResult
    {
        if (!\is_array($data)
            || !\is_string($data['api_key'] ?? null) || preg_match('/^[A-Za-z0-9]{32}$/', $data['api_key']) !== 1
            || !\is_string($data['sso_secret'] ?? null) || \strlen($data['sso_secret']) < 32 || preg_match('/[\x00-\x20\x7F]/', $data['sso_secret']) === 1
            || !\is_int($data['shop_id'] ?? null) || $data['shop_id'] < 1
            || !\is_int($data['currency_id'] ?? null) || $data['currency_id'] < 1
            || !\is_string($data['timezone'] ?? null) || !\in_array($data['timezone'], \DateTimeZone::listIdentifiers(), true)
            || !\is_string($data['module_version'] ?? null) || !version_compare($data['module_version'], self::MIN_MODULE_VERSION, '>=')) {
            throw new PairingException(self::MSG_MODULE);
        }

        return new PairingResult($shopUrl, $data['api_key'], $data['sso_secret'], $data['shop_id'], $data['currency_id'], $data['timezone'], $data['module_version']);
    }

    private static function redirectMessage(string $location): string
    {
        $parts = parse_url($location);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if ($host === '' || !\in_array($scheme, ['http', 'https'], true)) {
            return self::MSG_MODULE;
        }
        // The base address only: the module's path, the query and any user are dropped.
        $path = (string) preg_replace('#/module/tpvconnect/pair/?$#', '', (string) ($parts['path'] ?? ''));
        $base = $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . rtrim($path, '/');

        return sprintf('La tienda redirige a %s. Escribe esa dirección como dirección de la tienda.', $base);
    }
}
