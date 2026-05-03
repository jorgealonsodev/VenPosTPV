<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Sso;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use VenPosTPV\Service\Sso\Exception\ExpiredTokenException;
use VenPosTPV\Service\Sso\Exception\InvalidTokenException;
use VenPosTPV\Service\Sso\Exception\ReplayedNonceException;

class SsoTokenVerifier
{
    private const TOKEN_TTL = 60;
    private const NONCE_TTL_SECONDS = 120;

    public function __construct(
        private readonly string $sharedSecret,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{employee_id: int, shop_id: int, timestamp: int, nonce: string}
     *
     * @throws InvalidTokenException  malformed token or HMAC mismatch
     * @throws ExpiredTokenException  timestamp too old
     * @throws ReplayedNonceException nonce already consumed
     */
    public function verify(string $token): array
    {
        if ($this->sharedSecret === '') {
            throw new InvalidTokenException('SSO configuration error');
        }

        $parts = explode('~', $token, 2);
        if (count($parts) !== 2) {
            throw new InvalidTokenException('Invalid token format');
        }

        [$encodedPayload, $signature] = $parts;

        $expectedSignature = hash_hmac('sha256', $encodedPayload, $this->sharedSecret);
        if (!hash_equals($expectedSignature, $signature)) {
            throw new InvalidTokenException('Invalid token signature');
        }

        $payload = $this->base64UrlDecode($encodedPayload);
        if ($payload === false) {
            throw new InvalidTokenException('Invalid token format');
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            throw new InvalidTokenException('Invalid token format');
        }

        if (!isset($data['employee_id'], $data['shop_id'], $data['timestamp'], $data['nonce'])) {
            throw new InvalidTokenException('Invalid token format');
        }

        if ((time() - (int) $data['timestamp']) > self::TOKEN_TTL) {
            throw new ExpiredTokenException('Token expired');
        }

        $this->cleanupNonces();

        try {
            $this->connection->executeStatement(
                'INSERT INTO sso_nonce (nonce, created_at) VALUES (?, NOW())',
                [(string) $data['nonce']]
            );
        } catch (UniqueConstraintViolationException) {
            throw new ReplayedNonceException('Token already used');
        }

        return [
            'employee_id' => (int) $data['employee_id'],
            'shop_id' => (int) $data['shop_id'],
            'timestamp' => (int) $data['timestamp'],
            'nonce' => (string) $data['nonce'],
        ];
    }

    private function base64UrlDecode(string $data): string|false
    {
        $padded = str_pad(
            strtr($data, '-_', '+/'),
            (int) (4 * ceil(strlen($data) / 4)),
            '=',
            STR_PAD_RIGHT
        );

        return base64_decode($padded, true);
    }

    private function cleanupNonces(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM sso_nonce WHERE created_at < NOW() - INTERVAL ? SECOND',
            [self::NONCE_TTL_SECONDS]
        );
    }
}
