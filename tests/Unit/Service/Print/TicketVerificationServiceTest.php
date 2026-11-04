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
        $secret = str_repeat('k', 64);
        $service = new TicketVerificationService($secret, 'https://example.com');

        // Create an expired token manually
        $expiredPayload = [
            'order_id' => 42,
            'reference' => 'ORD-042',
            'total' => 99.99,
            'iat' => time() - 172800, // 2 days ago
            'exp' => time() - 86400, // 1 day ago (expired)
        ];
        $token = \Firebase\JWT\JWT::encode($expiredPayload, $secret, 'HS256');

        $this->expectException(\Firebase\JWT\ExpiredException::class);
        $service->verifyToken($token);
    }

    public function testALongSecretSignsExactlyAsBeforeSoPrintedTicketsStayValid(): void
    {
        $secret = bin2hex(random_bytes(32)); // what the installer writes as APP_SECRET
        $token = (new TicketVerificationService($secret, 'https://example.com'))->generateToken(['order_id' => 7, 'reference' => 'R7', 'total' => 1.0]);

        $decoded = (array) \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
        self::assertSame(7, $decoded['order_id']);
    }

    public function testAShortSecretStillSignsAndVerifiesTickets(): void
    {
        // php-jwt 7 refuses HS256 keys under 32 bytes; a short APP_SECRET must not stop printing.
        $service = new TicketVerificationService('short', 'https://example.com');
        $token = $service->generateToken(['order_id' => 8, 'reference' => 'R8', 'total' => 2.0]);

        self::assertSame(8, $service->verifyToken($token)['order_id']);
        $this->expectException(\Firebase\JWT\SignatureInvalidException::class);
        (new TicketVerificationService('other', 'https://example.com'))->verifyToken($token);
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
