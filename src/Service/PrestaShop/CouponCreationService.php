<?php

declare(strict_types=1);

namespace VenPosTPV\Service\PrestaShop;

use DateTimeImmutable;
use DateTimeZone;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use VenPosTPV\Service\Coupon\IssuedCouponStore;
use VenPosTPV\Service\PrestaShop\Exception\CouponCreationException;

/** Creates native PrestaShop cart rules; creation responses are never retried. */
class CouponCreationService
{
    private readonly string $baseUrl;
    private readonly DateTimeZone $timezone;

    public function __construct(
        string $shopUrl,
        private readonly string $apiKey,
        private readonly HttpClientInterface $httpClient,
        private readonly int $defaultCurrencyId,
        string $shopTimezone = 'Europe/Madrid',
        private readonly ?IssuedCouponStore $issuedCoupons = null,
    ) {
        $shopUrl = rtrim($shopUrl, '/');
        $this->baseUrl = str_ends_with($shopUrl, '/api') ? substr($shopUrl, 0, -4) : $shopUrl;
        try {
            $this->timezone = new DateTimeZone($shopTimezone);
        } catch (\Throwable) {
            throw CouponCreationException::validation('La zona horaria de la tienda no es válida.');
        }
    }

    /**
     * Optional `partial_use` (bool, default false): when true, PrestaShop keeps the
     * unspent balance of an amount coupon as a new coupon instead of losing it.
     *
     * @param array<string, mixed> $input
     *
     * @return array{id:int,code:string,type:string,value:float,expires_at:string,quantity:int,quantity_per_user:int}
     */
    public function create(array $input, int $shopId): array
    {
        $coupon = $this->validate($input, $shopId);
        $partialUse = $input['partial_use'] ?? false;
        if (!\is_bool($partialUse)) {
            throw CouponCreationException::validation('El uso parcial no es válido.');
        }
        $code = $this->generateCode();
        $languageIds = $this->fetchNameLanguageIds($shopId);
        $xml = $this->buildXml($coupon, $shopId, $languageIds, $code, $partialUse);

        try {
            $response = $this->httpClient->request('POST', $this->url('/api/cart_rules?id_shop=' . $shopId), [
                'auth_basic' => [$this->apiKey, ''],
                'headers' => ['Content-Type' => 'application/xml', 'Accept' => 'application/xml'],
                'body' => $xml,
                'timeout' => 10,
                'max_redirects' => 0,
            ]);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new \RuntimeException('non-success response');
            }
            $created = $this->parseCreated($response->getContent(false));
        } catch (\Throwable) {
            // A POST may already have been accepted even when its response is lost.
            throw CouponCreationException::reconcile($code);
        }

        if ($created['id'] <= 0 || !hash_equals($code, $created['code'])) {
            throw CouponCreationException::reconcile($code);
        }

        return ['id' => $created['id'], 'code' => $code] + $coupon;
    }

    /**
     * create() for «Crear cupón»: the coupon is also stored in the till right after
     * PrestaShop confirms it, so its slip is printed from the server's own data.
     * A failed local copy never turns a created coupon into an error: the code is
     * returned with `printable` false (no slip can be printed for it).
     *
     * @param array<string, mixed> $input
     *
     * @return array{id:int,code:string,type:string,value:float,expires_at:string,quantity:int,quantity_per_user:int,local_id:?int,printable:bool}
     */
    public function createIssued(array $input, int $shopId, ?int $terminalId, ?int $userId): array
    {
        $coupon = $this->create($input, $shopId);
        $localId = $this->issuedCoupons?->record(
            $coupon['id'],
            $coupon['code'],
            $coupon['type'],
            $coupon['value'],
            $coupon['expires_at'],
            $coupon['quantity'],
            $coupon['quantity_per_user'],
            $terminalId,
            $userId,
        );

        return $coupon + ['local_id' => $localId, 'printable' => $localId !== null];
    }

    /** @param array<string, mixed> $input @return array{type:string,value:float,expires_at:string,quantity:int,quantity_per_user:int} */
    private function validate(array $input, int $shopId): array
    {
        if ($shopId <= 0 || $this->defaultCurrencyId <= 0) {
            throw CouponCreationException::validation('Falta el contexto de tienda o moneda.');
        }
        $type = $input['discount_type'] ?? null;
        $value = $input['value'] ?? null;
        if (!in_array($type, ['amount', 'percentage'], true) || !is_int($value) && !is_float($value) || !is_finite((float) $value)) {
            throw CouponCreationException::validation('El descuento no es válido.');
        }
        $value = (float) $value;
        if (($type === 'amount' && ($value <= 0 || abs($value * 100 - round($value * 100)) > 0.000001)) || ($type === 'percentage' && ($value <= 0 || $value > 100))) {
            throw CouponCreationException::validation('El valor del descuento no es válido.');
        }
        $quantity = $input['quantity'] ?? null;
        $perUser = $input['quantity_per_user'] ?? null;
        if (!is_int($quantity) || !is_int($perUser) || $quantity <= 0 || $perUser <= 0 || $perUser > $quantity) {
            throw CouponCreationException::validation('El número de usos no es válido.');
        }
        $date = $input['expires_at'] ?? null;
        $expiry = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->timezone) : false;
        $errors = DateTimeImmutable::getLastErrors();
        if (!$expiry || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $expiry->format('Y-m-d') !== $date || $expiry <= new DateTimeImmutable('today', $this->timezone)) {
            throw CouponCreationException::validation('La fecha de caducidad debe ser futura.');
        }
        return ['type' => $type, 'value' => $value, 'expires_at' => $date, 'quantity' => $quantity, 'quantity_per_user' => $perUser];
    }

    /** Eight unambiguous uppercase characters, generated server-side. */
    private function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($index = 0; $index < 8; ++$index) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    /** @return list<int> */
    private function fetchNameLanguageIds(int $shopId): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->url('/api/cart_rules?schema=blank&id_shop=' . $shopId), [
                'auth_basic' => [$this->apiKey, ''], 'headers' => ['Accept' => 'application/xml'], 'timeout' => 10, 'max_redirects' => 0,
            ]);
            $matches = [];
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || !preg_match_all('/<language\s+id=["\'](\d+)["\']/', $response->getContent(false), $matches)) {
                throw new \RuntimeException('invalid schema');
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $matches[1]), static fn (int $id): bool => $id > 0)));
            if ($ids === []) {
                throw new \RuntimeException('invalid schema');
            }
            return $ids;
        } catch (\Throwable) {
            throw CouponCreationException::unavailable();
        }
    }

    /** @param array{type:string,value:float,expires_at:string,quantity:int,quantity_per_user:int} $coupon @param list<int> $languageIds */
    private function buildXml(array $coupon, int $shopId, array $languageIds, string $code, bool $partialUse = false): string
    {
        $partial = $partialUse ? '1' : '0';
        $amount = $coupon['type'] === 'amount' ? number_format($coupon['value'], 2, '.', '') : '0';
        $percent = $coupon['type'] === 'percentage' ? (string) $coupon['value'] : '0';
        $name = htmlspecialchars('Cupón TPV ' . $code, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $names = implode('', array_map(static fn (int $languageId): string => "<language id=\"{$languageId}\">{$name}</language>", $languageIds));
        return '<?xml version="1.0" encoding="UTF-8"?><prestashop><cart_rule>'
            . "<name>{$names}</name>"
            . "<code>{$code}</code><date_from>" . (new DateTimeImmutable('now', $this->timezone))->format('Y-m-d H:i:s') . "</date_from><date_to>{$coupon['expires_at']} 23:59:59</date_to>"
            . "<quantity>{$coupon['quantity']}</quantity><quantity_per_user>{$coupon['quantity_per_user']}</quantity_per_user><priority>1</priority><partial_use>{$partial}</partial_use><active>1</active><free_shipping>0</free_shipping><reduction_percent>{$percent}</reduction_percent><reduction_amount>{$amount}</reduction_amount><reduction_tax>1</reduction_tax><reduction_currency>{$this->defaultCurrencyId}</reduction_currency><reduction_product>0</reduction_product><reduction_exclude_special>0</reduction_exclude_special><minimum_amount>0</minimum_amount><minimum_amount_tax>0</minimum_amount_tax><minimum_amount_currency>0</minimum_amount_currency><minimum_amount_shipping>0</minimum_amount_shipping></cart_rule></prestashop>";
    }

    /** @return array{id:int,code:string} */
    private function parseCreated(string $xml): array
    {
        // PrestaShop wraps scalar fields in CDATA, so a regex for bare text
        // reports a successful creation as ambiguous.
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($document === false || !isset($document->cart_rule)) {
            return ['id' => 0, 'code' => ''];
        }

        return ['id' => (int) $document->cart_rule->id, 'code' => trim((string) $document->cart_rule->code)];
    }

    private function url(string $path): string
    {
        return $this->baseUrl . $path;
    }
}
