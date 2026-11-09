<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Share;

/**
 * Signs and checks the links that open a document's PDF without a session (sent by
 * WhatsApp): {base64url("type:id:expiry")}.{base64url(HMAC-SHA256)}.
 *
 * The HMAC key is derived from APP_SECRET with its own context, so these links
 * share nothing with the ticket verification JWT or the stored mail password. A
 * link cannot be guessed, pointed at another document or extended, and it stops
 * working after 30 days.
 */
class ShareLinkSigner
{
    public const TYPES = ['ticket', 'invoice', 'coupon'];
    public const LIFETIME_SECONDS = 30 * 86400;
    public const KEY_CONTEXT = 'venpos-document-share-link-v1';
    public const PATH = '/d/';

    private readonly string $secret;
    private readonly string $appUrl;
    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(string $secret, string $appUrl, ?\Closure $clock = null)
    {
        $this->secret = $secret;
        $this->appUrl = rtrim($appUrl, '/');
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @throws \InvalidArgumentException for an unknown type or id */
    public function sign(string $type, int $id): string
    {
        if (!\in_array($type, self::TYPES, true) || $id <= 0) {
            throw new \InvalidArgumentException('Unknown document to share.');
        }
        $payload = sprintf('%s:%d:%d', $type, $id, ($this->clock)() + self::LIFETIME_SECONDS);

        return self::encode($payload) . '.' . self::encode($this->mac($payload));
    }

    /** The public link of the document's PDF. */
    public function url(string $type, int $id): string
    {
        return $this->appUrl . self::PATH . $this->sign($type, $id);
    }

    /**
     * The document a token opens, or null when it is malformed, forged or expired.
     *
     * @return array{type: string, id: int}|null
     */
    public function verify(string $token): ?array
    {
        if ($this->secret === '' || \strlen($token) > 256) {
            return null;
        }
        $parts = explode('.', $token);
        if (\count($parts) !== 2) {
            return null;
        }
        $payload = self::decode($parts[0]);
        $mac = self::decode($parts[1]);
        if ($payload === null || $mac === null || !hash_equals($this->mac($payload), $mac)) {
            return null;
        }
        if (preg_match('/^(ticket|invoice|coupon):([1-9]\d{0,17}):(\d{1,12})$/D', $payload, $m) !== 1) {
            return null;
        }
        if ((int) $m[3] < ($this->clock)()) {
            return null;
        }

        return ['type' => $m[1], 'id' => (int) $m[2]];
    }

    private function mac(string $payload): string
    {
        if ($this->secret === '') {
            throw new \LogicException('APP_SECRET is empty: document links cannot be signed.');
        }

        return hash_hmac('sha256', $payload, hash_hmac('sha256', self::KEY_CONTEXT, $this->secret, true), true);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $text): ?string
    {
        if ($text === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $text) !== 1) {
            return null;
        }
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}
