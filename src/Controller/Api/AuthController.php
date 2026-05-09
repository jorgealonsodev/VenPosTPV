<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class AuthController
{
    private const MAX_FAILURES = 3;
    private const MIN_PIN_LENGTH = 8;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%env(APP_ADMIN_USER)%')] private readonly string $adminUser,
        #[Autowire('%env(APP_ADMIN_PASSWORD)%')] private readonly string $adminPassword,
        #[Autowire('%env(int:PS_DEFAULT_SHOP)%')] private readonly int $defaultShopId,
    ) {
    }

    #[Route('/auth/login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        $type = $body['type'] ?? '';

        if ($type === 'admin') {
            return $this->loginAdmin($request, $body);
        }

        if ($type === 'employee') {
            return $this->loginEmployee($request, $body);
        }

        return new JsonResponse(['error' => 'type must be admin or employee'], Response::HTTP_BAD_REQUEST);
    }

    #[Route('/auth/logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $session = $request->getSession();
        $session->invalidate();

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_regenerate_id(true);
        }

        return new JsonResponse(['data' => ['logged_out' => true]]);
    }

    /**
     * Public endpoint — lookup employee by name or code.
     * Returns basic info (id, name) if found; used to show PIN keypad.
     * No session required.
     */
    #[Route('/auth/employee-lookup', methods: ['POST'])]
    public function employeeLookup(Request $request): JsonResponse
    {
        $body  = json_decode((string) $request->getContent(), true);
        $query = trim((string) ($body['query'] ?? ''));

        if ($query === '') {
            return new JsonResponse(['error' => 'query es obligatorio'], Response::HTTP_BAD_REQUEST);
        }

        $row = $this->connection->fetchAssociative(
            'SELECT u.id, u.name, u.locked_at, r.name AS role_name
             FROM user u JOIN role r ON r.id = u.role_id
             WHERE u.active = 1 AND (LOWER(u.name) = LOWER(?) OR (u.code IS NOT NULL AND LOWER(u.code) = LOWER(?)))',
            [$query, $query]
        );

        if (!$row) {
            return new JsonResponse(['error' => 'Empleado no encontrado'], Response::HTTP_NOT_FOUND);
        }

        $locked = $row['locked_at'] !== null;

        return new JsonResponse([
            'data' => [
                'id'     => (int) $row['id'],
                'name'   => $row['name'],
                'locked' => $locked,
            ],
        ]);
    }

    /**
     * Public endpoint — returns active employees (name + id) for the login screen.
     * No session required.
     */
    #[Route('/auth/employees-public', methods: ['GET'])]
    public function employeesPublic(): JsonResponse
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT u.id, u.name, r.name AS role, u.active
             FROM user u JOIN role r ON r.id = u.role_id
             WHERE u.active = 1
             ORDER BY u.name'
        );

        return new JsonResponse(['data' => $rows]);
    }

    private const MASTER_ADMIN_CODE = 'master-admin';

    private function loginAdmin(Request $request, array $body): JsonResponse
    {
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($username !== $this->adminUser || $password !== $this->adminPassword) {
            return new JsonResponse(['error' => 'Credenciales incorrectas'], Response::HTTP_UNAUTHORIZED);
        }

        // Resolve the synthetic master-admin DB user (creates it if missing, idempotent).
        $adminUserId = $this->resolveMasterAdminUser();

        $shopId = $this->defaultShopId > 0 ? $this->defaultShopId : 1;

        $session = $request->getSession();
        $session->set('user_id', $adminUserId);
        $session->set('cashier_name', 'Admin');
        $session->set('role', 'admin');
        $session->set('is_master_admin', true);
        $session->set('shop_id', $shopId);
        $session->set('authenticated_at', date('c'));

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['user_id']        = $adminUserId;
        $_SESSION['cashier_name']   = 'Admin';
        $_SESSION['role']           = 'admin';
        $_SESSION['is_master_admin'] = true;
        $_SESSION['shop_id']        = $shopId;
        $_SESSION['authenticated_at'] = date('c');

        return new JsonResponse([
            'data' => [
                'user_id' => $adminUserId,
                'name'    => 'Admin',
                'role'    => 'admin',
            ],
        ]);
    }

    /**
     * Returns the id of the synthetic master-admin row in `user`.
     * If it does not exist yet (e.g. migration not run), creates it on the fly.
     * Safe to call on every login — fully idempotent.
     */
    private function resolveMasterAdminUser(): int
    {
        $row = $this->connection->fetchAssociative(
            "SELECT u.id FROM user u WHERE u.code = ?",
            [self::MASTER_ADMIN_CODE]
        );

        if ($row !== false) {
            return (int) $row['id'];
        }

        // Ensure admin role exists
        $this->connection->executeStatement(
            "INSERT IGNORE INTO role (name, permissions) VALUES ('admin', JSON_OBJECT())"
        );

        $roleRow = $this->connection->fetchAssociative(
            "SELECT id FROM role WHERE name = 'admin'"
        );

        // Unusable PIN hash — this user is NEVER authenticated via PIN
        $unusableHash = '$2y$12$unusablePinHashForMasterAdminXXXXXXXXXXXXXXXXXXXXXXXX.';

        $this->connection->executeStatement(
            "INSERT INTO user (ps_employee_id, role_id, name, code, pin_hash, active)
             VALUES (NULL, ?, 'Master Admin', ?, ?, 1)",
            [(int) $roleRow['id'], self::MASTER_ADMIN_CODE, $unusableHash]
        );

        return (int) $this->connection->lastInsertId();
    }

    private function loginEmployee(Request $request, array $body): JsonResponse
    {
        $userId = (int) ($body['user_id'] ?? 0);
        $pin    = (string) ($body['pin'] ?? '');

        if ($userId <= 0 || $pin === '') {
            return new JsonResponse(['error' => 'user_id y pin son obligatorios'], Response::HTTP_BAD_REQUEST);
        }

        // Validate minimum PIN length on backend
        if (strlen($pin) < self::MIN_PIN_LENGTH) {
            return new JsonResponse(['error' => 'PIN incorrecto'], Response::HTTP_UNAUTHORIZED);
        }

        $row = $this->connection->fetchAssociative(
            'SELECT u.id, u.name, u.pin_hash, u.login_failures, u.locked_at, r.name AS role_name
             FROM user u JOIN role r ON r.id = u.role_id
             WHERE u.id = ? AND u.active = 1',
            [$userId]
        );

        if (!$row) {
            return new JsonResponse(['error' => 'Usuario no encontrado o inactivo'], Response::HTTP_UNAUTHORIZED);
        }

        // Locked check
        if ($row['locked_at'] !== null) {
            return new JsonResponse(
                ['error' => 'Cuenta bloqueada. Contacta al administrador para que desbloquee tu usuario.', 'locked' => true],
                Response::HTTP_FORBIDDEN
            );
        }

        if (!password_verify($pin, $row['pin_hash'])) {
            $failures = (int) $row['login_failures'] + 1;

            if ($failures >= self::MAX_FAILURES) {
                $this->connection->executeStatement(
                    'UPDATE user SET login_failures = ?, locked_at = NOW() WHERE id = ?',
                    [$failures, $row['id']]
                );

                return new JsonResponse(
                    ['error' => 'Cuenta bloqueada. Contacta al administrador para que desbloquee tu usuario.', 'locked' => true],
                    Response::HTTP_FORBIDDEN
                );
            }

            $this->connection->executeStatement(
                'UPDATE user SET login_failures = ? WHERE id = ?',
                [$failures, $row['id']]
            );

            return new JsonResponse(['error' => 'PIN incorrecto'], Response::HTTP_UNAUTHORIZED);
        }

        // Success — reset failure counter
        $this->connection->executeStatement(
            'UPDATE user SET last_login_at = NOW(), login_failures = 0, locked_at = NULL WHERE id = ?',
            [$row['id']]
        );

        $role = $row['role_name'] === 'admin' ? 'admin' : 'cashier';

        $session = $request->getSession();
        $session->set('user_id', (int) $row['id']);
        $session->set('cashier_name', $row['name']);
        $session->set('role', $role);
        $session->set('shop_id', $this->defaultShopId > 0 ? $this->defaultShopId : 1);
        $session->set('authenticated_at', date('c'));

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['user_id'] = (int) $row['id'];
        $_SESSION['cashier_name'] = $row['name'];
        $_SESSION['role'] = $role;
        $_SESSION['shop_id'] = $this->defaultShopId > 0 ? $this->defaultShopId : 1;
        $_SESSION['authenticated_at'] = date('c');

        return new JsonResponse([
            'data' => [
                'user_id' => (int) $row['id'],
                'name'    => $row['name'],
                'role'    => $role,
            ],
        ]);
    }
}
