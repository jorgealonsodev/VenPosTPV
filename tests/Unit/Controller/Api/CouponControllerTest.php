<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use VenPosTPV\Controller\Api\CouponController;
use VenPosTPV\Service\PrestaShop\CouponCreationService;

final class CouponControllerTest extends TestCase
{
    public function testCashierCanCreateCouponFromAuthenticatedSession(): void
    {
        $service = $this->createMock(CouponCreationService::class);
        $service->expects(self::exactly(2))->method('create')->with(self::isType('array'), 7)->willReturn([
            'id' => 42, 'code' => 'TPV-ABC', 'type' => 'percentage', 'value' => 10.0, 'expires_at' => '2030-06-30', 'quantity' => 2, 'quantity_per_user' => 1,
        ]);
        $controller = new CouponController($service);

        $response = $controller->create($this->request(['user_id' => 9, 'role' => 'cashier', 'shop_id' => 7]));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('TPV-ABC', json_decode((string) $response->getContent(), true)['data']['code']);
        self::assertSame(201, $controller->create($this->request(['user_id' => 8, 'role' => 'admin', 'shop_id' => 7]))->getStatusCode());
    }

    public function testMissingAuthenticationAndDisallowedRoleDoNotCallService(): void
    {
        $service = $this->createMock(CouponCreationService::class);
        $service->expects(self::never())->method('create');
        $controller = new CouponController($service);

        self::assertSame(401, $controller->create($this->request([]))->getStatusCode());
        self::assertSame(403, $controller->create($this->request(['user_id' => 9, 'role' => 'manager', 'shop_id' => 7]))->getStatusCode());
    }

    /** @param array<string, mixed> $sessionValues */
    private function request(array $sessionValues): Request
    {
        $session = new Session(new MockArraySessionStorage());
        foreach ($sessionValues as $key => $value) {
            $session->set($key, $value);
        }
        $request = new Request([], [], [], [], [], [], json_encode(['discount_type' => 'percentage', 'value' => 10, 'expires_at' => '2030-06-30', 'quantity' => 2, 'quantity_per_user' => 1]));
        $request->setSession($session);
        return $request;
    }
}
