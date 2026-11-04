<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class TicketVerificationService
{
    private readonly string $secret;
    private readonly string $appUrl;

    public function __construct(
        string $secret,
        string $appUrl,
    ) {
        // php-jwt 7 refuses HS256 keys under 32 bytes. A secret that long (the
        // installer writes 64) is used as is, so printed tickets stay valid; a
        // shorter one is stretched with SHA-256 instead of breaking printing.
        $this->secret = \strlen($secret) >= 32 ? $secret : hash('sha256', $secret, true);
        $this->appUrl = rtrim($appUrl, '/');
    }

    /**
     * @param array<string, mixed> $orderData
     */
    public function generateToken(array $orderData): string
    {
        $payload = [
            'order_id' => (int) ($orderData['order_id'] ?? 0),
            'reference' => (string) ($orderData['reference'] ?? ''),
            'total' => (float) ($orderData['total'] ?? 0.0),
            'iat' => time(),
            'exp' => time() + 86400, // 24 hours
        ];

        return JWT::encode($payload, $this->secret, 'HS256');
    }

    public function verifyToken(string $token): array
    {
        $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));

        return (array) $decoded;
    }

    public function buildVerificationUrl(string $token): string
    {
        return $this->appUrl . '/api/ticket/verify?token=' . urlencode($token);
    }
}
