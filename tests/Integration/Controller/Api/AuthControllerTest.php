<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Controller\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use VenPosTPV\Controller\Api\AuthController;
use VenPosTPV\Service\PrintAgent\RateLimiter;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * R5 and R8 (docs/prd-seguridad-strix.md) on MySQL: anonymous callers no longer get
 * the staff directory, the master admin is out of reach of the PIN login, and logins
 * are limited per IP (429 + Retry-After) before any account is touched.
 */
final class AuthControllerTest extends TestCase
{
    private const CODE_PREFIX = 'authtest-';
    private const ROLE = 'authtest-cashier';
    private const PIN = '24681357';
    private const ADMIN_USER = 'admin';
    private const ADMIN_PASSWORD = 'admin-secret-123';
    private const SCOPES = ['auth_lookup', 'auth_login', 'auth_admin'];

    private ?Connection $connection = null;
    private MockClock $clock;
    private string $ip = '';
    private string $suffix = '';

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();
        $this->clock = new MockClock('2026-11-03 10:00:00');
        $this->ip = sprintf('198.51.100.%d', random_int(1, 254));
        $this->suffix = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->clean();
            $this->connection->close();
        }
        foreach (['user_id', 'cashier_name', 'role', 'is_master_admin', 'shop_id', 'authenticated_at'] as $key) {
            unset($_SESSION[$key]);
        }
    }

    private function clean(): void
    {
        $this->connection->executeStatement('DELETE FROM user WHERE code LIKE ?', [self::CODE_PREFIX . '%']);
        $this->connection->executeStatement('DELETE FROM role WHERE name = ?', [self::ROLE]);
        if ($this->ip !== '') {
            foreach (self::SCOPES as $scope) {
                $this->connection->executeStatement('DELETE FROM rate_limit WHERE rate_key = ?', [$scope . ':' . hash('sha256', $this->ip)]);
            }
        }
    }

    private function controller(): AuthController
    {
        return new AuthController($this->connection, new RateLimiter($this->connection, $this->clock), self::ADMIN_USER, self::ADMIN_PASSWORD, 1);
    }

    private function employee(string $name, string $pin = self::PIN): int
    {
        $this->connection->executeStatement('INSERT IGNORE INTO role (name, permissions) VALUES (?, JSON_OBJECT())', [self::ROLE]);
        $roleId = (int) $this->connection->fetchOne('SELECT id FROM role WHERE name = ?', [self::ROLE]);
        $this->connection->insert('user', [
            'role_id' => $roleId,
            'name' => $name . ' ' . $this->suffix,
            'code' => self::CODE_PREFIX . substr(md5($name . $this->suffix), 0, 12),
            'pin_hash' => password_hash($pin, PASSWORD_BCRYPT, ['cost' => 4]),
            'active' => 1,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /** @param array<string, mixed> $body */
    private function request(string $path, array $body): Request
    {
        $request = Request::create($path, 'POST', [], [], [], ['REMOTE_ADDR' => $this->ip], json_encode($body, JSON_THROW_ON_ERROR));
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function lookup(AuthController $controller, string $query): JsonResponse
    {
        return $controller->employeeLookup($this->request('/api/auth/employee-lookup', ['query' => $query]));
    }

    private function employeeLogin(AuthController $controller, int $userId, string $pin): JsonResponse
    {
        return $controller->login($this->request('/api/auth/login', ['type' => 'employee', 'user_id' => $userId, 'pin' => $pin]));
    }

    private function adminLogin(AuthController $controller, string $password): JsonResponse
    {
        return $controller->login($this->request('/api/auth/login', ['type' => 'admin', 'username' => self::ADMIN_USER, 'password' => $password]));
    }

    /** @return array{login_failures: int, locked: bool} */
    private function account(int $userId): array
    {
        $row = $this->connection->fetchAssociative('SELECT login_failures, locked_at FROM user WHERE id = ?', [$userId]);

        return ['login_failures' => (int) $row['login_failures'], 'locked' => $row['locked_at'] !== null];
    }

    /** @return array<string, mixed> */
    private static function json(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function masterAdminId(): int
    {
        // The admin login creates the synthetic row when the migration has not.
        $this->assertSame(200, $this->adminLogin($this->controller(), self::ADMIN_PASSWORD)->getStatusCode());
        $this->clean();

        return (int) $this->connection->fetchOne('SELECT id FROM user WHERE code = ?', ['master-admin']);
    }

    public function testTheEmployeesPublicRouteIsGone(): void
    {
        $routes = (new AttributeRouteControllerLoader())->load(AuthController::class);
        $routes->addPrefix('/api');
        $matcher = new UrlMatcher($routes, new RequestContext('', 'GET'));

        $this->expectException(ResourceNotFoundException::class);
        $matcher->match('/api/auth/employees-public');
    }

    public function testLookupReturnsExactlyIdAndName(): void
    {
        $id = $this->employee('Ana');
        $this->connection->executeStatement('UPDATE user SET locked_at = NOW(), login_failures = 3 WHERE id = ?', [$id]);

        $response = $this->lookup($this->controller(), 'ana ' . $this->suffix);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['data' => ['id' => $id, 'name' => 'Ana ' . $this->suffix]], self::json($response));
    }

    public function testLookupNeverFindsTheMasterAdmin(): void
    {
        $this->masterAdminId();
        $controller = $this->controller();

        $this->assertSame(404, $this->lookup($controller, 'master-admin')->getStatusCode());
        $this->assertSame(404, $this->lookup($controller, 'Master Admin')->getStatusCode());
    }

    public function testEmployeeLoginRejectsTheMasterAdminWithoutTouchingItsCounter(): void
    {
        $adminId = $this->masterAdminId();
        $before = $this->account($adminId);

        $response = $this->employeeLogin($this->controller(), $adminId, '00000000');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame($before, $this->account($adminId));
    }

    public function testShortPinCountsAsFailure(): void
    {
        // R8: a wrong short PIN is verified and counted like any other wrong PIN.
        $id = $this->employee('Bea');
        $this->assertSame(401, $this->employeeLogin($this->controller(), $id, '1234')->getStatusCode());
        $this->assertSame(['login_failures' => 1, 'locked' => false], $this->account($id));

        // D1: a legacy short PIN never logs in, even when it matches; the admin resets it.
        $legacy = $this->employee('Bego', '1234');
        $response = $this->employeeLogin($this->controller(), $legacy, '1234');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['login_failures' => 1, 'locked' => false], $this->account($legacy));
    }

    public function testThreeFailuresLockTheAccount(): void
    {
        $id = $this->employee('Carla');
        $controller = $this->controller();

        $this->assertSame(401, $this->employeeLogin($controller, $id, '00000000')->getStatusCode());
        $this->assertSame(401, $this->employeeLogin($controller, $id, '00000001')->getStatusCode());
        $third = $this->employeeLogin($controller, $id, '00000002');

        $this->assertSame(403, $third->getStatusCode());
        $this->assertTrue(self::json($third)['locked']);
        $this->assertSame(['login_failures' => 3, 'locked' => true], $this->account($id));
        // Even the right PIN is refused once locked, with the same `locked` answer.
        $this->assertSame(403, $this->employeeLogin($controller, $id, self::PIN)->getStatusCode());
    }

    public function testASuccessfulLoginResetsTheFailures(): void
    {
        $id = $this->employee('Dora');
        $controller = $this->controller();
        $this->employeeLogin($controller, $id, '00000000');

        $response = $this->employeeLogin($controller, $id, self::PIN);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($id, self::json($response)['data']['user_id']);
        $this->assertSame(['login_failures' => 0, 'locked' => false], $this->account($id));
    }

    public function testLookupIsRateLimitedPerIp(): void
    {
        $this->employee('Elena');
        $controller = $this->controller();
        for ($i = 0; $i < 20; ++$i) {
            $this->assertSame(200, $this->lookup($controller, 'elena ' . $this->suffix)->getStatusCode(), "lookup $i");
        }

        $response = $this->lookup($controller, 'elena ' . $this->suffix);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('60', $response->headers->get('Retry-After'));
        $this->assertArrayNotHasKey('data', self::json($response));
        // Another IP is not affected.
        $this->clean();
        $this->employee('Elena');
        $this->ip = '203.0.113.' . random_int(1, 254);
        $this->assertSame(200, $this->lookup($controller, 'elena ' . $this->suffix)->getStatusCode());
    }

    public function testEmployeeLoginIsRateLimitedPerMinuteWithoutTouchingTheAccount(): void
    {
        $id = $this->employee('Fina');
        $victim = $this->employee('Gala');
        $controller = $this->controller();
        for ($i = 0; $i < 10; ++$i) {
            $this->employeeLogin($controller, $id, self::PIN);
        }

        $response = $this->employeeLogin($controller, $victim, '00000000');

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('60', $response->headers->get('Retry-After'));
        $this->assertSame(['login_failures' => 0, 'locked' => false], $this->account($victim));
    }

    public function testEmployeeLoginIsRateLimitedPerQuarterHour(): void
    {
        $id = $this->employee('Hada');
        $controller = $this->controller();
        for ($minute = 0; $minute < 3; ++$minute) {
            for ($i = 0; $i < 10; ++$i) {
                $this->assertSame(200, $this->employeeLogin($controller, $id, self::PIN)->getStatusCode(), "minute $minute, login $i");
            }
            $this->clock->sleep(60);
        }

        $response = $this->employeeLogin($controller, $id, self::PIN);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('900', $response->headers->get('Retry-After'));
    }

    public function testAdminLoginIsRateLimited(): void
    {
        $controller = $this->controller();
        for ($i = 0; $i < 5; ++$i) {
            $this->assertSame(401, $this->adminLogin($controller, 'wrong-' . $i)->getStatusCode());
        }

        $response = $this->adminLogin($controller, self::ADMIN_PASSWORD);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('60', $response->headers->get('Retry-After'));
    }
}
