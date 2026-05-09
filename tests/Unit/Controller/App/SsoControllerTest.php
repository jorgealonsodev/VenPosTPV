<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\App;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\App\SsoController;
use VenPosTPV\Service\Db\ConnectionManagerInterface;
use VenPosTPV\Service\Sso\Exception\ExpiredTokenException;
use VenPosTPV\Service\Sso\Exception\InvalidTokenException;
use VenPosTPV\Service\Sso\Exception\ReplayedNonceException;
use VenPosTPV\Service\Sso\SsoTokenVerifier;

class SsoControllerTest extends TestCase
{
    private function createController(
        SsoTokenVerifier $verifier,
        ConnectionManagerInterface $connectionManager,
        string $basePath = '/tpv'
    ): SsoController {
        return new SsoController($verifier, $connectionManager, $basePath);
    }

    public function testMissingTokenReturns400(): void
    {
        $verifier = $this->createMock(SsoTokenVerifier::class);
        $connManager = $this->createMock(ConnectionManagerInterface::class);

        $controller = $this->createController($verifier, $connManager);
        $request = new Request();
        $response = $controller->index($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Missing token', $data['error']);
    }

    public function testInvalidTokenReturns400(): void
    {
        $verifier = $this->createMock(SsoTokenVerifier::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->willThrowException(new InvalidTokenException('Invalid token format'));

        $connManager = $this->createMock(ConnectionManagerInterface::class);

        $controller = $this->createController($verifier, $connManager);
        $request = new Request(['token' => 'bad_token']);
        $response = $controller->index($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Invalid token format', $data['error']);
    }

    public function testBadHmacReturns401(): void
    {
        $verifier = $this->createMock(SsoTokenVerifier::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->willThrowException(new InvalidTokenException('Invalid token signature'));

        $connManager = $this->createMock(ConnectionManagerInterface::class);

        $controller = $this->createController($verifier, $connManager);
        $request = new Request(['token' => 'bad_hmac_token']);
        $response = $controller->index($request);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Invalid token signature', $data['error']);
    }

    public function testExpiredTokenReturns401(): void
    {
        $verifier = $this->createMock(SsoTokenVerifier::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->willThrowException(new ExpiredTokenException('Token expired'));

        $connManager = $this->createMock(ConnectionManagerInterface::class);

        $controller = $this->createController($verifier, $connManager);
        $request = new Request(['token' => 'expired_token']);
        $response = $controller->index($request);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Token expired', $data['error']);
    }

    public function testReplayedNonceReturns401(): void
    {
        $verifier = $this->createMock(SsoTokenVerifier::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->willThrowException(new ReplayedNonceException('Token already used'));

        $connManager = $this->createMock(ConnectionManagerInterface::class);

        $controller = $this->createController($verifier, $connManager);
        $request = new Request(['token' => 'replayed_token']);
        $response = $controller->index($request);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Token already used', $data['error']);
    }

    public function testMisconfiguredSecretReturns500(): void
    {
        $verifier = $this->createMock(SsoTokenVerifier::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->willThrowException(new InvalidTokenException('SSO configuration error'));

        $connManager = $this->createMock(ConnectionManagerInterface::class);

        $controller = $this->createController($verifier, $connManager);
        $request = new Request(['token' => 'some_token']);
        $response = $controller->index($request);

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('SSO configuration error', $data['error']);
    }

    public function testUnknownUserReturns403(): void
    {
        $verifier = $this->createMock(SsoTokenVerifier::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->willReturn([
                'employee_id' => 999,
                'shop_id' => 1,
                'timestamp' => time(),
                'nonce' => 'test_nonce',
            ]);

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                $this->stringContains('SELECT id, name, role_id FROM user'),
                $this->equalTo([999])
            )
            ->willReturn(false);

        $connManager = $this->createMock(ConnectionManagerInterface::class);
        $connManager->expects($this->once())
            ->method('getTpvConnection')
            ->willReturn($conn);

        $controller = $this->createController($verifier, $connManager);
        $request = new Request(['token' => 'valid_token_unknown_user']);
        $response = $controller->index($request);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('User not configured', $data['error']);
    }

    public function testValidTokenRedirectsToApp(): void
    {
        $verifier = $this->createMock(SsoTokenVerifier::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->willReturn([
                'employee_id' => 42,
                'shop_id' => 3,
                'timestamp' => time(),
                'nonce' => 'test_nonce_123',
            ]);

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                $this->stringContains('SELECT id, name, role_id FROM user'),
                $this->equalTo([42])
            )
            ->willReturn([
                'id' => 7,
                'name' => 'Test User',
                'role_id' => 2,
            ]);

        $conn->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE user SET last_login_at'),
                $this->equalTo([7])
            );

        $connManager = $this->createMock(ConnectionManagerInterface::class);
        $connManager->expects($this->once())
            ->method('getTpvConnection')
            ->willReturn($conn);

        $controller = $this->createController($verifier, $connManager);
        $request = new Request(['token' => 'valid_token']);
        $response = $controller->index($request);

        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        $this->assertSame('/tpv/app/pos', $response->headers->get('Location'));
    }
}
