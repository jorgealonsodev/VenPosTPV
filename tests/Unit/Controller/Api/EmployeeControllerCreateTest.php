<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\EmployeeController;
use VenPosTPV\Tests\Support\SessionRequest;

final class EmployeeControllerCreateTest extends TestCase
{
    private const ADMIN = ['user_id' => 1, 'role' => 'admin'];

    /** @var \ArrayObject<string, int> role name => id */
    private \ArrayObject $roles;

    /** @var \ArrayObject<int, array<string, mixed>> */
    private \ArrayObject $insertedUsers;

    /** @param array<string, int> $roles */
    private function controller(array $roles): EmployeeController
    {
        $this->roles = new \ArrayObject($roles);
        $this->insertedUsers = new \ArrayObject();
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturnCallback(
            fn (string $sql, array $params = []) => isset($this->roles[$params[0] ?? '']) ? ['id' => $this->roles[$params[0]]] : false
        );
        $conn->method('fetchOne')->willReturn(false);
        $conn->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []): int {
            if (str_contains($sql, 'INSERT IGNORE INTO role') && !isset($this->roles[$params[0]])) {
                $this->roles[$params[0]] = 100 + \count($this->roles);
            }

            return 1;
        });
        $conn->method('insert')->willReturnCallback(function (string $table, array $data): int {
            $this->insertedUsers->append($data);

            return 1;
        });
        $conn->method('lastInsertId')->willReturn('7');

        return new EmployeeController($conn);
    }

    public function testCashierIsCreatedWhenTheShopHasOnlyTheAdminRole(): void
    {
        $controller = $this->controller(['admin' => 1]);

        $response = $controller->create(SessionRequest::json(['name' => 'ana', 'pin' => '12345678', 'role' => 'cashier'], self::ADMIN));

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $this->assertArrayHasKey('cashier', $this->roles->getArrayCopy());
        $this->assertSame($this->roles['cashier'], $this->insertedUsers[0]['role_id']);
    }

    public function testAnExistingRoleIsReusedAsBefore(): void
    {
        $controller = $this->controller(['admin' => 1, 'cashier' => 2]);

        $response = $controller->create(SessionRequest::json(['name' => 'ana', 'pin' => '12345678', 'role' => 'admin'], self::ADMIN));

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $this->assertSame(1, $this->insertedUsers[0]['role_id']);
        $this->assertSame(['admin' => 1, 'cashier' => 2], $this->roles->getArrayCopy());
    }

    public function testAnUnknownRoleIsStillRejected(): void
    {
        $controller = $this->controller(['admin' => 1]);

        $response = $controller->create(SessionRequest::json(['name' => 'ana', 'pin' => '12345678', 'role' => 'boss'], self::ADMIN));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertSame(['admin' => 1], $this->roles->getArrayCopy());
        $this->assertCount(0, $this->insertedUsers);
    }
}
