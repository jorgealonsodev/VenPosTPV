<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Auth;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\Exception as PDOException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Sso\Exception\ExpiredTokenException;
use VenPosTPV\Service\Sso\Exception\InvalidTokenException;
use VenPosTPV\Service\Sso\Exception\ReplayedNonceException;
use VenPosTPV\Service\Sso\SsoTokenVerifier;

class SsoTokenVerifierTest extends TestCase
{
    private const TEST_SECRET = 'test_shared_secret_32_chars_long';

    private function createMockConnection(): Connection
    {
        return $this->createMock(Connection::class);
    }

    private function generateValidToken(int $employeeId = 1, int $shopId = 1): string
    {
        $nonce = bin2hex(random_bytes(16));
        $timestamp = time();

        $payload = json_encode([
            'employee_id' => $employeeId,
            'shop_id' => $shopId,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
        ]);

        $encodedPayload = $this->base64UrlEncode((string) $payload);
        $signature = hash_hmac('sha256', $encodedPayload, self::TEST_SECRET);

        return $encodedPayload . '~' . $signature;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function testValidTokenPassesAllChecks(): void
    {
        $conn = $this->createMockConnection();

        $callCount = 0;
        $conn->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$callCount): int {
                ++$callCount;
                if ($callCount === 1) {
                    return 0;
                }

                return 1;
            });

        $verifier = new SsoTokenVerifier(self::TEST_SECRET, $conn);
        $token = $this->generateValidToken();

        $result = $verifier->verify($token);

        $this->assertArrayHasKey('employee_id', $result);
        $this->assertArrayHasKey('shop_id', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('nonce', $result);
        $this->assertSame(1, $result['employee_id']);
        $this->assertSame(1, $result['shop_id']);
    }

    public function testInvalidFormatNoTildeThrowsException(): void
    {
        $conn = $this->createMockConnection();
        $verifier = new SsoTokenVerifier(self::TEST_SECRET, $conn);

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('Invalid token format');

        $verifier->verify('not_a_valid_token_without_tilde');
    }

    public function testBadHmacThrowsException(): void
    {
        $conn = $this->createMockConnection();
        $verifier = new SsoTokenVerifier(self::TEST_SECRET, $conn);

        $token = $this->generateValidToken();
        [$encodedPayload] = explode('~', $token, 2);
        $badSignature = hash_hmac('sha256', $encodedPayload, 'wrong_secret');
        $badToken = $encodedPayload . '~' . $badSignature;

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('Invalid token signature');

        $verifier->verify($badToken);
    }

    public function testExpiredTimestampThrowsException(): void
    {
        $conn = $this->createMockConnection();
        $verifier = new SsoTokenVerifier(self::TEST_SECRET, $conn);

        $nonce = bin2hex(random_bytes(16));
        $timestamp = time() - 61;

        $payload = json_encode([
            'employee_id' => 1,
            'shop_id' => 1,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
        ]);

        $encodedPayload = $this->base64UrlEncode((string) $payload);
        $signature = hash_hmac('sha256', $encodedPayload, self::TEST_SECRET);
        $token = $encodedPayload . '~' . $signature;

        $this->expectException(ExpiredTokenException::class);
        $this->expectExceptionMessage('Token expired');

        $verifier->verify($token);
    }

    public function testReplayedNonceThrowsException(): void
    {
        $driverException = new \PDOException('Duplicate entry');
        $dbalDriverException = PDOException::new($driverException);
        $uniqueException = new UniqueConstraintViolationException($dbalDriverException, null);

        $conn = $this->createMockConnection();
        $conn->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use ($uniqueException): int {
                if (str_contains($sql, 'DELETE FROM sso_nonce')) {
                    return 0;
                }

                throw $uniqueException;
            });

        $verifier = new SsoTokenVerifier(self::TEST_SECRET, $conn);
        $token = $this->generateValidToken();

        $this->expectException(ReplayedNonceException::class);
        $this->expectExceptionMessage('Token already used');

        $verifier->verify($token);
    }

    public function testEmptySecretThrowsException(): void
    {
        $conn = $this->createMockConnection();
        $verifier = new SsoTokenVerifier('', $conn);
        $token = $this->generateValidToken();

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('SSO configuration error');

        $verifier->verify($token);
    }

    public function testCleanupNoncesIsCalledBeforeInsert(): void
    {
        $conn = $this->createMockConnection();

        $callOrder = [];
        $conn->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$callOrder): int {
                if (str_contains($sql, 'DELETE FROM sso_nonce')) {
                    $callOrder[] = 'cleanup';

                    return 0;
                }
                if (str_contains($sql, 'INSERT INTO sso_nonce')) {
                    $callOrder[] = 'insert';

                    return 1;
                }

                return 0;
            });

        $verifier = new SsoTokenVerifier(self::TEST_SECRET, $conn);
        $token = $this->generateValidToken();

        $verifier->verify($token);

        $this->assertSame(['cleanup', 'insert'], $callOrder);
    }
}
