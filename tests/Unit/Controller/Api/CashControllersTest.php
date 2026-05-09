<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\CashCloseController;
use VenPosTPV\Controller\Api\CashCurrentController;
use VenPosTPV\Controller\Api\CashMovementController;
use VenPosTPV\Controller\Api\CashOpenController;
use VenPosTPV\Controller\Api\CashReportXController;
use VenPosTPV\Service\Cash\CashSessionService;

final class CashControllersTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        session_start();
        $_SESSION = [];
    }

    public function testOpenRequiresAuth(): void
    {
        $controller = new CashOpenController($this->createMock(CashSessionService::class));
        $response = $controller(new Request([], [], [], [], [], [], json_encode(['opening_cash' => 100])));
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testCurrentNoSessionReturnsOpenFalse(): void
    {
        $_SESSION['user_id'] = 1;
        $service = $this->createMock(CashSessionService::class);
        $service->method('getCurrentSession')->willReturn(null);
        $response = (new CashCurrentController($service))();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testMovementValidation(): void
    {
        $_SESSION['user_id'] = 1;
        $controller = new CashMovementController($this->createMock(CashSessionService::class));
        $response = $controller(new Request([], [], [], [], [], [], json_encode(['type' => 'sale', 'amount' => 0])));
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testCloseValidation(): void
    {
        $_SESSION['user_id'] = 1;
        $controller = new CashCloseController($this->createMock(CashSessionService::class));
        $response = $controller(new Request([], [], [], [], [], [], json_encode(['counted_cash' => -1])));
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testReportUnauthorized(): void
    {
        $controller = new CashReportXController($this->createMock(CashSessionService::class));
        $response = $controller();
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }
}
