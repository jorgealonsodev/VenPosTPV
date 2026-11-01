<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\ParkedTicketController;
use VenPosTPV\Service\Cart\ParkedTicketService;
use VenPosTPV\Tests\Support\SessionRequest;

class ParkedTicketControllerTest extends TestCase
{
    public function testListReturns401WithoutSession(): void
    {
        $controller = new ParkedTicketController($this->createMock(ParkedTicketService::class));
        $response = $controller->list(SessionRequest::create());

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testListReturnsTicketsWithSession(): void
    {
        $service = $this->createMock(ParkedTicketService::class);
        $service->expects($this->once())
            ->method('list')
            ->willReturn([['id' => 1, 'client_ref' => 'parked_1', 'note' => 'Probador 1', 'total' => 25.0]]);

        $controller = new ParkedTicketController($service);
        $response = $controller->list(SessionRequest::create([], ['user_id' => 2]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $payload['data']);
        $this->assertSame('Probador 1', $payload['data'][0]['note']);
    }

    public function testCreateReturns401WithoutSession(): void
    {
        $controller = new ParkedTicketController($this->createMock(ParkedTicketService::class));
        $response = $controller->create(SessionRequest::json(['lines' => [['product_id' => 1, 'price' => 10, 'qty' => 1]]]));

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testCreateReturns422WhenLinesAreEmpty(): void
    {
        $service = $this->createMock(ParkedTicketService::class);
        $service->expects($this->once())
            ->method('park')
            ->willThrowException(new \InvalidArgumentException('Cannot park an empty ticket'));

        $controller = new ParkedTicketController($service);
        $response = $controller->create(SessionRequest::json(['lines' => []], ['user_id' => 2, 'cashier_name' => 'María']));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Cannot park an empty ticket', $payload['error']);
    }

    public function testCreateParksTicketWithSessionMetadata(): void
    {
        $service = $this->createMock(ParkedTicketService::class);
        $service->expects($this->once())
            ->method('park')
            ->with($this->callback(function (array $input): bool {
                return $input['user_id'] === 2
                    && $input['terminal_id'] === 1
                    && $input['cashier_name'] === 'María García'
                    && $input['note'] === 'Probador 2';
            }))
            ->willReturn([
                'id' => 7,
                'client_ref' => 'parked_7',
                'note' => 'Probador 2',
                'total' => 45.0,
                'lines' => [['product_id' => 1, 'price' => 45.0, 'qty' => 1]],
            ]);

        $controller = new ParkedTicketController($service);
        $response = $controller->create(SessionRequest::json(
            [
                'client_ref' => 'parked_7',
                'note' => 'Probador 2',
                'lines' => [['product_id' => 1, 'price' => 45.0, 'qty' => 1]],
            ],
            ['user_id' => 2, 'terminal_id' => 1, 'cashier_name' => 'María García']
        ));

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(7, $payload['data']['id']);
    }

    public function testDeleteReturns401WithoutSession(): void
    {
        $controller = new ParkedTicketController($this->createMock(ParkedTicketService::class));
        $response = $controller->delete(SessionRequest::create(), '7');

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testDeleteRemovesTicketAndReturnsDeletedFlag(): void
    {
        $service = $this->createMock(ParkedTicketService::class);
        $service->expects($this->once())
            ->method('delete')
            ->with('parked_7')
            ->willReturn(true);

        $controller = new ParkedTicketController($service);
        $response = $controller->delete(SessionRequest::create([], ['user_id' => 2]), 'parked_7');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['data']['deleted']);
    }

    public function testClearReturnsDeletedCount(): void
    {
        $service = $this->createMock(ParkedTicketService::class);
        $service->expects($this->once())
            ->method('clearAll')
            ->willReturn(4);

        $controller = new ParkedTicketController($service);
        $response = $controller->clear(SessionRequest::create([], ['user_id' => 2]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(4, $payload['data']['deleted_count']);
    }
}
