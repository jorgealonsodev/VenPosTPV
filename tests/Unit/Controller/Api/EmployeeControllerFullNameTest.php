<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\EmployeeController;
use VenPosTPV\Tests\Support\SessionRequest;

/**
 * Ajustes → Empleados: besides the user name, an optional «Nombre y apellidos»
 * (user.full_name, Version00000049) that the ticket prints as the cashier.
 */
final class EmployeeControllerFullNameTest extends TestCase
{
    private const ADMIN = ['user_id' => 1, 'role' => 'admin'];

    /** @var \ArrayObject<int, array<string, mixed>> */
    private \ArrayObject $inserted;

    /** @var \ArrayObject<int, array<string, mixed>> */
    private \ArrayObject $updated;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $listQueries;

    /** @param list<array<string, mixed>> $listRows */
    private function controller(array $listRows = [], bool $columnMissing = false): EmployeeController
    {
        $this->inserted = new \ArrayObject();
        $this->updated = new \ArrayObject();
        $this->listQueries = new \ArrayObject();
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturnCallback(
            static fn (string $sql, array $params = []) => str_contains($sql, 'FROM role') ? ['id' => 2] : ['id' => $params[0] ?? 1]
        );
        $conn->method('fetchOne')->willReturn(false);
        $conn->method('insert')->willReturnCallback(function (string $table, array $data): int {
            $this->inserted->append($data);

            return 1;
        });
        $conn->method('update')->willReturnCallback(function (string $table, array $data, array $criteria): int {
            $this->updated->append($data);

            return 1;
        });
        $conn->method('lastInsertId')->willReturn('7');
        $conn->method('fetchAllAssociative')->willReturnCallback(function (string $sql) use ($listRows, $columnMissing): array {
            $this->listQueries->append($sql);
            if ($columnMissing && str_contains($sql, 'full_name')) {
                throw new InvalidFieldNameException($this->createMock(DriverException::class), null);
            }

            return $listRows;
        });

        return new EmployeeController($conn);
    }

    public function testCreateStoresTheFullNameTrimmedWithSingleSpaces(): void
    {
        $controller = $this->controller();

        $response = $controller->create(SessionRequest::json(['name' => 'ana', 'full_name' => "  Ana   García\tLópez ", 'pin' => '12345678', 'role' => 'cashier'], self::ADMIN));

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertSame('Ana García López', $this->inserted[0]['full_name']);
        self::assertSame('ana', $this->inserted[0]['name']);
        $data = json_decode((string) $response->getContent(), true)['data'];
        self::assertSame('Ana García López', $data['full_name']);
        self::assertSame('ana', $data['name']);
    }

    public function testCreateWithoutAFullNameStoresNoneAsBefore(): void
    {
        $controller = $this->controller();

        $response = $controller->create(SessionRequest::json(['name' => 'ana', 'full_name' => '   ', 'pin' => '12345678'], self::ADMIN));

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertArrayNotHasKey('full_name', $this->inserted[0], 'an empty full name stays NULL');
        self::assertSame(['role_id', 'name', 'pin_hash', 'active'], array_keys($this->inserted[0]));
        self::assertNull(json_decode((string) $response->getContent(), true)['data']['full_name']);
    }

    public function testCreateRejectsAFullNameLongerThan128Characters(): void
    {
        $controller = $this->controller();

        $response = $controller->create(SessionRequest::json(['name' => 'ana', 'full_name' => str_repeat('á', 129), 'pin' => '12345678'], self::ADMIN));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('El nombre y apellidos no puede superar 128 caracteres', json_decode((string) $response->getContent(), true)['error']);
        self::assertCount(0, $this->inserted);
    }

    public function testCreateAcceptsExactly128Characters(): void
    {
        $controller = $this->controller();

        $response = $controller->create(SessionRequest::json(['name' => 'ana', 'full_name' => str_repeat('á', 128), 'pin' => '12345678'], self::ADMIN));

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertSame(str_repeat('á', 128), $this->inserted[0]['full_name']);
    }

    public function testUpdateChangesTheFullName(): void
    {
        $controller = $this->controller();

        $response = $controller->update(5, SessionRequest::json(['full_name' => ' Juan  Pérez '], self::ADMIN));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(['full_name' => 'Juan Pérez'], $this->updated[0]);
    }

    public function testUpdateWithAnEmptyFullNameClearsIt(): void
    {
        $controller = $this->controller();

        $controller->update(5, SessionRequest::json(['name' => 'juan', 'full_name' => ''], self::ADMIN));

        self::assertSame(['name' => 'juan', 'full_name' => null], $this->updated[0]);
    }

    public function testUpdateWithoutTheFullNameLeavesItAlone(): void
    {
        $controller = $this->controller();

        $controller->update(5, SessionRequest::json(['name' => 'juan', 'active' => true], self::ADMIN));

        self::assertSame(['name' => 'juan', 'active' => 1], $this->updated[0]);
    }

    public function testUpdateRejectsAFullNameLongerThan128Characters(): void
    {
        $controller = $this->controller();

        $response = $controller->update(5, SessionRequest::json(['full_name' => str_repeat('x', 129)], self::ADMIN));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('El nombre y apellidos no puede superar 128 caracteres', json_decode((string) $response->getContent(), true)['error']);
        self::assertCount(0, $this->updated);
    }

    public function testListIncludesTheFullName(): void
    {
        $controller = $this->controller([
            ['id' => 5, 'name' => 'juan', 'full_name' => 'Juan Pérez', 'code' => null, 'role' => 'cashier', 'active' => 1, 'login_failures' => 0, 'locked_at' => null],
        ]);

        $response = $controller->list(SessionRequest::create([], self::ADMIN));

        $row = json_decode((string) $response->getContent(), true)['data'][0];
        self::assertStringContainsString('u.full_name', $this->listQueries[0]);
        self::assertSame('Juan Pérez', $row['full_name']);
        self::assertSame('juan', $row['name']);
        self::assertTrue($row['active']);
        self::assertFalse($row['locked']);
    }

    public function testListStillWorksBeforeTheMigration(): void
    {
        $controller = $this->controller([
            ['id' => 5, 'name' => 'juan', 'code' => null, 'role' => 'cashier', 'active' => 1, 'login_failures' => 0, 'locked_at' => null],
        ], columnMissing: true);

        $response = $controller->list(SessionRequest::create([], self::ADMIN));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $row = json_decode((string) $response->getContent(), true)['data'][0];
        self::assertNull($row['full_name']);
        self::assertSame('juan', $row['name']);
    }
}
