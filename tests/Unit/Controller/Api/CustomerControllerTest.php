<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use VenPosTPV\Controller\Api\CustomerController;
use VenPosTPV\Service\Customer\CustomerDirectory;
use VenPosTPV\Service\Customer\Exception\CustomerException;

final class CustomerControllerTest extends TestCase
{
    /** @param array<string, mixed> $query @param array<string, mixed>|null $body */
    private function request(?int $userId, array $query = [], ?array $body = null): Request
    {
        $session = new Session(new MockArraySessionStorage());
        if ($userId !== null) {
            $session->set('user_id', $userId);
        }
        $request = new Request($query, [], [], [], [], [], $body === null ? '' : (string) json_encode($body));
        $request->setSession($session);

        return $request;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        return (array) json_decode((string) $response->getContent(), true);
    }

    private const CUSTOMER = ['local_id' => 4, 'id' => null, 'name' => 'Marta Ruiz', 'firstname' => 'Marta', 'lastname' => 'Ruiz', 'email' => 'marta@x.es', 'phone' => '', 'synced' => false];

    public function testBothEndpointsNeedASession(): void
    {
        $directory = $this->createMock(CustomerDirectory::class);
        $directory->expects($this->never())->method('search');
        $directory->expects($this->never())->method('create');
        $controller = new CustomerController($directory);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $controller->search($this->request(null, ['q' => 'a']))->getStatusCode());
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $controller->create($this->request(null, [], ['firstname' => 'A']))->getStatusCode());
    }

    public function testSearchReadsTheLocalCopy(): void
    {
        $directory = $this->createMock(CustomerDirectory::class);
        $directory->expects($this->once())->method('search')->with('ruiz')->willReturn([self::CUSTOMER]);

        $response = (new CustomerController($directory))->search($this->request(1, ['q' => ' ruiz ']));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(['data' => [self::CUSTOMER]], $this->decode($response));
    }

    public function testCreateStoresLocallyAndAnExistingEmailReturnsThatCustomer(): void
    {
        $directory = $this->createMock(CustomerDirectory::class);
        $directory->expects($this->exactly(2))->method('create')
            ->with(['firstname' => 'Marta', 'lastname' => 'Ruiz', 'email' => 'marta@x.es'])
            ->willReturnOnConsecutiveCalls(self::CUSTOMER + ['existing' => false], self::CUSTOMER + ['existing' => true]);
        $controller = new CustomerController($directory);
        $body = ['firstname' => 'Marta', 'lastname' => 'Ruiz', 'email' => 'marta@x.es'];

        $created = $controller->create($this->request(1, [], $body));
        $existing = $controller->create($this->request(1, [], $body));

        $this->assertSame(Response::HTTP_CREATED, $created->getStatusCode());
        $this->assertSame(self::CUSTOMER + ['existing' => false], $this->decode($created)['data']);
        $this->assertSame(Response::HTTP_OK, $existing->getStatusCode());
        $this->assertTrue($this->decode($existing)['data']['existing']);
    }

    public function testCreateAnswersValidationErrorsAs422(): void
    {
        $directory = $this->createMock(CustomerDirectory::class);
        $directory->method('create')->willThrowException(CustomerException::invalid('El email no es válido.'));

        $response = (new CustomerController($directory))->create($this->request(1, [], ['email' => 'x']));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertSame(['error' => 'El email no es válido.'], $this->decode($response));
    }
}
