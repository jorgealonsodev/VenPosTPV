<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\HealthController;
use VenPosTPV\Service\Db\ConnectionManagerInterface;

class HealthControllerTest extends TestCase
{
    public function testReturnsHealthyWhenDatabaseIsOk(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1');

        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->expects($this->once())
            ->method('getTpvConnection')
            ->willReturn($conn);

        $controller = new HealthController($connectionManager);
        $response = $controller->index();

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('healthy', $data['status']);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayHasKey('checks', $data);
        $this->assertSame('ok', $data['checks']['database']);
    }

    public function testReturnsUnhealthyWhenDatabaseFails(): void
    {
        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->expects($this->once())
            ->method('getTpvConnection')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $controller = new HealthController($connectionManager);
        $response = $controller->index();

        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('unhealthy', $data['status']);
        $this->assertStringContainsString('Connection refused', $data['checks']['database']);
    }

    public function testResponseHasJsonContentType(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery');

        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->method('getTpvConnection')->willReturn($conn);

        $controller = new HealthController($connectionManager);
        $response = $controller->index();

        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function testTimestampIsIso8601Format(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery');

        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->method('getTpvConnection')->willReturn($conn);

        $controller = new HealthController($connectionManager);
        $response = $controller->index();

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // Verify ISO 8601 format (e.g., 2026-05-03T12:34:56+00:00)
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[\+\-]\d{2}:\d{2}$/',
            $data['timestamp']
        );
    }
}
