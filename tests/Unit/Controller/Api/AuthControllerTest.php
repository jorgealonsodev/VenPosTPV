<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use VenPosTPV\Controller\Api\AuthController;
use VenPosTPV\Service\PrintAgent\RateLimiter;

/**
 * R6 (docs/prd-seguridad-strix.md): both logins hand out a new session id and keep
 * what the session already held (D2: migrate, so the cart survives). The MySQL cases
 * of R5/R8 are in tests/Integration/Controller/Api/AuthControllerTest.php.
 */
final class AuthControllerTest extends TestCase
{
    private const ADMIN_USER = 'admin';
    private const ADMIN_PASSWORD = 'admin-secret-123';

    private function controller(Connection $connection): AuthController
    {
        return new AuthController($connection, new RateLimiter($connection), self::ADMIN_USER, self::ADMIN_PASSWORD, 1);
    }

    /** @param array<string, mixed> $body */
    private function login(AuthController $controller, Session $session, array $body): int
    {
        $request = Request::create('/api/auth/login', 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.10'], json_encode($body, JSON_THROW_ON_ERROR));
        $request->setSession($session);

        return $controller->login($request)->getStatusCode();
    }

    private function sessionWithCart(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $session->set('cart', ['lines' => [['sku' => 'A1']]]);

        return $session;
    }

    public function testAdminLoginRotatesTheSessionIdAndKeepsTheCart(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['id' => 99]);
        $session = $this->sessionWithCart();
        $before = $session->getId();

        $status = $this->login($this->controller($connection), $session, ['type' => 'admin', 'username' => self::ADMIN_USER, 'password' => self::ADMIN_PASSWORD]);

        $this->assertSame(200, $status);
        $this->assertNotSame($before, $session->getId());
        $this->assertSame(99, $session->get('user_id'));
        $this->assertSame(['lines' => [['sku' => 'A1']]], $session->get('cart'));
    }

    public function testEmployeeLoginRotatesTheSessionIdAndKeepsTheCart(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'id' => 12, 'name' => 'Lucía', 'pin_hash' => password_hash('12345678', PASSWORD_BCRYPT, ['cost' => 4]),
            'login_failures' => 0, 'locked_at' => null, 'role_name' => 'cashier',
        ]);
        $session = $this->sessionWithCart();
        $before = $session->getId();

        $status = $this->login($this->controller($connection), $session, ['type' => 'employee', 'user_id' => 12, 'pin' => '12345678']);

        $this->assertSame(200, $status);
        $this->assertNotSame($before, $session->getId());
        $this->assertSame(12, $session->get('user_id'));
        $this->assertSame(['lines' => [['sku' => 'A1']]], $session->get('cart'));
    }

    public function testAFailedLoginKeepsTheSessionId(): void
    {
        $connection = $this->createMock(Connection::class);
        $session = $this->sessionWithCart();
        $before = $session->getId();

        $status = $this->login($this->controller($connection), $session, ['type' => 'admin', 'username' => self::ADMIN_USER, 'password' => 'wrong']);

        $this->assertSame(401, $status);
        $this->assertSame($before, $session->getId());
        $this->assertFalse($session->has('user_id'));
    }

    public function testAdminLoginIsClosedWhenTheCredentialsAreNotConfigured(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['id' => 99]);
        $limiter = $this->createMock(RateLimiter::class);
        $limiter->method('hit')->willReturn(1);
        $session = $this->sessionWithCart();

        $status = $this->login(new AuthController($connection, $limiter, '', '', 1), $session, ['type' => 'admin', 'username' => '', 'password' => '']);

        $this->assertSame(401, $status);
        $this->assertNull($session->get('user_id'));
    }

    public function testTheLimitsLetLoginsThroughWithoutTheRateLimitTable(): void
    {
        // R5: like the agent API, a missing `rate_limit` table (migration not applied
        // yet) must not lock everybody out.
        $limiter = $this->createMock(RateLimiter::class);
        $limiter->method('hit')->willThrowException(new TableNotFoundException(new class ('Table rate_limit does not exist') extends AbstractException {}, null));
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['id' => 99]);
        $controller = new AuthController($connection, $limiter, self::ADMIN_USER, self::ADMIN_PASSWORD, 1);

        $status = $this->login($controller, $this->sessionWithCart(), ['type' => 'admin', 'username' => self::ADMIN_USER, 'password' => self::ADMIN_PASSWORD]);

        $this->assertSame(200, $status);
    }

    protected function tearDown(): void
    {
        foreach (['user_id', 'cashier_name', 'role', 'is_master_admin', 'shop_id', 'authenticated_at'] as $key) {
            unset($_SESSION[$key]);
        }
    }
}
