<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\App;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
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
                $this->stringContains('SELECT u.id, u.name, r.name AS role FROM user u JOIN role r'),
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
                $this->stringContains('SELECT u.id, u.name, r.name AS role FROM user u JOIN role r'),
                $this->equalTo([42])
            )
            ->willReturn([
                'id' => 7,
                'name' => 'Test User',
                'role' => 'cashier',
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

        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $session->set('cart', ['lines' => [['sku' => 'A1']]]);
        $session->set('cash_opened_at', '2026-11-03 09:00:00');
        $session->set('opening_cash', 50.0);
        $idBefore = $session->getId();

        $controller = $this->createController($verifier, $connManager);
        $request = new Request(['token' => 'valid_token']);
        $request->setSession($session);
        $response = $controller->index($request);

        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        $this->assertSame('/tpv/app/pos', $response->headers->get('Location'));
        // R4: PosController reads the Symfony session, so the login must land there.
        $this->assertSame(7, $session->get('user_id'));
        $this->assertSame('Test User', $session->get('cashier_name'));
        $this->assertSame('cashier', $session->get('role'));
        $this->assertSame(42, $session->get('ps_employee_id'));
        $this->assertSame(3, $session->get('shop_id'));
        $this->assertIsInt($session->get('authenticated_at'));
        $this->assertTrue($session->has('terminal_id'));
        $this->assertNull($session->get('terminal_id'));
        $this->assertTrue($session->has('cash_session_id'));
        $this->assertNull($session->get('cash_session_id'));
        $this->assertFalse($session->has('cash_opened_at'));
        $this->assertFalse($session->has('opening_cash'));
        // R4/R6: a new session id, and the cart survives (D2: migrate, not invalidate).
        $this->assertNotSame($idBefore, $session->getId());
        $this->assertSame(['lines' => [['sku' => 'A1']]], $session->get('cart'));
        // Nothing is written to the native $_SESSION behind Symfony's back.
        $this->assertArrayNotHasKey('user_id', $_SESSION ?? []);
        $this->assertArrayNotHasKey('role', $_SESSION ?? []);
    }

    protected function setUp(): void
    {
        unset($_SESSION['role'], $_SESSION['user_id'], $_SESSION['shop_id']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['role'], $_SESSION['user_id'], $_SESSION['shop_id']);
    }
}
