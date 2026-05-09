<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Print\TicketVerificationService;

final class TicketVerificationServiceTest extends TestCase
{
    private function createService(): TicketVerificationService
    {
        return new TicketVerificationService('test-secret-key-123', 'https://example.com');
    }

    public function testGenerateTokenReturnsValidJwt(): void
    {
        $service = $this->createService();
        $token = $service->generateToken([
            'order_id' => 42,
            'reference' => 'ORD-042',
            'total' => 99.99,
        ]);

        self::assertNotEmpty($token);
        self::assertSame(2, substr_count($token, '.'));
    }

    public function testVerifyTokenReturnsPayload(): void
    {
        $service = $this->createService();
        $token = $service->generateToken([
            'order_id' => 42,
            'reference' => 'ORD-042',
            'total' => 99.99,
        ]);

        $payload = $service->verifyToken($token);

        self::assertSame(42, $payload['order_id']);
        self::assertSame('ORD-042', $payload['reference']);
        self::assertSame(99.99, $payload['total']);
    }

    public function testVerifyExpiredTokenThrowsException(): void
    {
        $service = new TicketVerificationService('test-secret-key-123', 'https://example.com');

        // Create an expired token manually
        $expiredPayload = [
            'order_id' => 42,
            'reference' => 'ORD-042',
            'total' => 99.99,
            'iat' => time() - 172800, // 2 days ago
            'exp' => time() - 86400, // 1 day ago (expired)
        ];
        $token = \Firebase\JWT\JWT::encode($expiredPayload, 'test-secret-key-123', 'HS256');

        $this->expectException(\Firebase\JWT\ExpiredException::class);
        $service->verifyToken($token);
    }

    public function testVerifyTamperedTokenThrowsException(): void
    {
        $service = $this->createService();
        $token = $service->generateToken([
            'order_id' => 42,
            'reference' => 'ORD-042',
            'total' => 99.99,
        ]);

        // Tamper with the payload
        $parts = explode('.', $token);
        $parts[1] = base64_encode(json_encode(['order_id' => 99, 'reference' => 'HACKED']));
        $tamperedToken = implode('.', $parts);

        $this->expectException(\Firebase\JWT\SignatureInvalidException::class);
        $service->verifyToken($tamperedToken);
    }

    public function testBuildVerificationUrlContainsToken(): void
    {
        $service = $this->createService();
        $token = 'my-test-token';
        $url = $service->buildVerificationUrl($token);

        self::assertStringStartsWith('https://example.com/api/ticket/verify?token=', $url);
        self::assertStringContainsString('my-test-token', $url);
    }
}
