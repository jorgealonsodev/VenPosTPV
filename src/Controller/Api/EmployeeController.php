<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class EmployeeController
{
    private const MIN_PIN_LENGTH = 8;
    private const KNOWN_ROLES = ['admin', 'cashier'];
    private const MAX_FULL_NAME_LENGTH = 128;
    private const FULL_NAME_TOO_LONG = 'El nombre y apellidos no puede superar 128 caracteres';

    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('/employees', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return $this->unauthorized();
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT u.id, u.name, u.full_name, u.code, r.name AS role, u.active, u.login_failures, u.locked_at
                 FROM user u JOIN role r ON r.id = u.role_id
                 ORDER BY u.name'
            );
        } catch (InvalidFieldNameException) {
            // Code deployed before Version00000049: list the employees as before.
            $rows = $this->connection->fetchAllAssociative(
                'SELECT u.id, u.name, u.code, r.name AS role, u.active, u.login_failures, u.locked_at
                 FROM user u JOIN role r ON r.id = u.role_id
                 ORDER BY u.name'
            );
        }

        $rows = array_map(static function (array $r): array {
            $r['full_name'] ??= null;
            $r['active'] = (bool) $r['active'];
            $r['locked'] = $r['locked_at'] !== null;
            return $r;
        }, $rows);

        return new JsonResponse(['data' => $rows]);
    }

    #[Route('/employees', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return $this->unauthorized();
        }

        $body = json_decode((string) $request->getContent(), true);
        $name = trim((string) ($body['name'] ?? ''));
        $pin  = (string) ($body['pin'] ?? '');
        $code = isset($body['code']) ? trim((string) $body['code']) : null;
        $role = (string) ($body['role'] ?? 'cashier');
        $fullName = self::fullName($body['full_name'] ?? null);

        if ($name === '') {
            return new JsonResponse(
                ['error' => 'El nombre es obligatorio'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (strlen($pin) < self::MIN_PIN_LENGTH) {
            return new JsonResponse(
                ['error' => 'PIN inválido'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($fullName !== null && mb_strlen($fullName) > self::MAX_FULL_NAME_LENGTH) {
            return new JsonResponse(['error' => self::FULL_NAME_TOO_LONG], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $roleId = $this->roleIdByName($role) ?? $this->createKnownRole($role);
        if (!$roleId) {
            return new JsonResponse(['error' => "Rol '$role' no encontrado"], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Validate unique code if provided
        if ($code !== null && $code !== '') {
            $existing = $this->connection->fetchOne('SELECT id FROM user WHERE code = ?', [$code]);
            if ($existing) {
                return new JsonResponse(['error' => 'El código ya existe'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $data = [
            'role_id'  => $roleId,
            'name'     => $name,
            'pin_hash' => password_hash($pin, PASSWORD_BCRYPT),
            'active'   => 1,
        ];

        if ($code !== null && $code !== '') {
            $data['code'] = $code;
        }

        // Only when given, so a till not migrated yet (Version00000049) still creates employees.
        if ($fullName !== null) {
            $data['full_name'] = $fullName;
        }

        $this->connection->insert('user', $data);

        $id = (int) $this->connection->lastInsertId();

        return new JsonResponse(
            ['data' => ['id' => $id, 'name' => $name, 'full_name' => $fullName, 'code' => $code ?: null, 'role' => $role, 'active' => true]],
            Response::HTTP_CREATED
        );
    }

    #[Route('/employees/{id}', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return $this->unauthorized();
        }

        $row = $this->connection->fetchAssociative('SELECT id FROM user WHERE id = ?', [$id]);
        if (!$row) {
            return new JsonResponse(['error' => 'Usuario no encontrado'], Response::HTTP_NOT_FOUND);
        }

        $body   = json_decode((string) $request->getContent(), true);
        $fields = [];

        if (isset($body['name'])) {
            $fields['name'] = trim((string) $body['name']);
        }

        if (\is_array($body) && array_key_exists('full_name', $body)) {
            $fullName = self::fullName($body['full_name']);
            if ($fullName !== null && mb_strlen($fullName) > self::MAX_FULL_NAME_LENGTH) {
                return new JsonResponse(['error' => self::FULL_NAME_TOO_LONG], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $fields['full_name'] = $fullName;
        }

        if (array_key_exists('code', $body)) {
            $code = trim((string) $body['code']);
            if ($code !== '') {
                $existing = $this->connection->fetchOne('SELECT id FROM user WHERE code = ? AND id != ?', [$code, $id]);
                if ($existing) {
                    return new JsonResponse(['error' => 'El código ya existe'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $fields['code'] = $code;
            } else {
                $fields['code'] = null;
            }
        }

        if (isset($body['pin'])) {
            $pin = (string) $body['pin'];
            if (strlen($pin) < self::MIN_PIN_LENGTH) {
                return new JsonResponse(['error' => 'PIN inválido'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $fields['pin_hash'] = password_hash($pin, PASSWORD_BCRYPT);
        }

        if (isset($body['role'])) {
            $roleId = $this->roleIdByName((string) $body['role']) ?? $this->createKnownRole((string) $body['role']);
            if (!$roleId) {
                return new JsonResponse(['error' => 'Rol no encontrado'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $fields['role_id'] = $roleId;
        }

        if (isset($body['active'])) {
            $fields['active'] = $body['active'] ? 1 : 0;
        }

        if ($fields) {
            $this->connection->update('user', $fields, ['id' => $id]);
        }

        return new JsonResponse(['data' => ['updated' => true]]);
    }

    /**
     * Unlock a locked employee account (admin only).
     */
    #[Route('/employees/{id}/unlock', methods: ['POST'])]
    public function unlock(int $id, Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return $this->unauthorized();
        }

        $row = $this->connection->fetchAssociative('SELECT id FROM user WHERE id = ?', [$id]);
        if (!$row) {
            return new JsonResponse(['error' => 'Usuario no encontrado'], Response::HTTP_NOT_FOUND);
        }

        $this->connection->executeStatement(
            'UPDATE user SET login_failures = 0, locked_at = NULL WHERE id = ?',
            [$id]
        );

        return new JsonResponse(['data' => ['unlocked' => true]]);
    }

    // -------------------------------------------------------------------------

    /** «Nombre y apellidos»: trimmed, inner whitespace collapsed; empty = NULL (the user name is printed). */
    private static function fullName(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }
        $fullName = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $fullName === '' ? null : $fullName;
    }

    private function isAdmin(Request $request): bool
    {
        return $request->getSession()->get('role') === 'admin';
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(['error' => 'Acceso denegado — solo administradores'], Response::HTTP_FORBIDDEN);
    }

    private function roleIdByName(string $name): ?int
    {
        $row = $this->connection->fetchAssociative('SELECT id FROM role WHERE name = ?', [$name]);
        return $row ? (int) $row['id'] : null;
    }

    /**
     * The roles the employee form offers. Shops installed before
     * Version00000041 only have `admin`, so `cashier` is created on first use.
     */
    private function createKnownRole(string $name): ?int
    {
        if (!\in_array($name, self::KNOWN_ROLES, true)) {
            return null;
        }
        $this->connection->executeStatement(
            'INSERT IGNORE INTO role (name, permissions) VALUES (?, JSON_OBJECT())',
            [$name]
        );

        return $this->roleIdByName($name);
    }
}
