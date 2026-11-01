<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\CashCloseController;
use VenPosTPV\Controller\Api\CashCurrentController;
use VenPosTPV\Controller\Api\CashMovementController;
use VenPosTPV\Controller\Api\CashOpenController;
use VenPosTPV\Controller\Api\CashReportXController;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Tests\Support\SessionRequest;

final class CashControllersTest extends TestCase
{
    public function testOpenRequiresAuth(): void
    {
        $response = (new CashOpenController($this->createMock(CashSessionService::class)))(SessionRequest::json(['opening_cash' => 100]));
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testCurrentNoSessionReturnsOpenFalse(): void
    {
        $service = $this->createMock(CashSessionService::class);
        $service->method('getCurrentSession')->with(1)->willReturn(null);
        $response = (new CashCurrentController($service))(SessionRequest::create([], ['user_id' => 1]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testMovementValidation(): void
    {
        $response = (new CashMovementController($this->createMock(CashSessionService::class)))(
            SessionRequest::json(['type' => 'sale', 'amount' => 0], ['user_id' => 1])
        );
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testCloseValidation(): void
    {
        $response = (new CashCloseController($this->createMock(CashSessionService::class)))(
            SessionRequest::json(['counted_cash' => -1], ['user_id' => 1])
        );
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testReportUnauthorized(): void
    {
        $response = (new CashReportXController($this->createMock(CashSessionService::class)))(SessionRequest::create());
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }
}
