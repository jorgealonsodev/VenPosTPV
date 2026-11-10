<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\PaymentMethodsController;
use VenPosTPV\Service\Checkout\PaymentMethodsSettings;
use VenPosTPV\Tests\Support\SessionRequest;

/** Ajustes → Tienda → «Métodos de pago»: anyone reads them, only an admin changes them. */
final class PaymentMethodsControllerTest extends TestCase
{
    public function testACashierReadsTheMethodsButCannotChangeThem(): void
    {
        $settings = $this->createMock(PaymentMethodsSettings::class);
        $settings->method('enabled')->willReturn(['cash', 'card']);
        $settings->expects($this->never())->method('save');
        $controller = new PaymentMethodsController($settings);
        $cashier = ['user_id' => 5, 'role' => 'cashier'];

        $read = $controller->show(SessionRequest::json([], $cashier));
        self::assertSame(['data' => ['enabled' => ['cash', 'card'], 'available' => PaymentMethodsSettings::METHODS]], json_decode((string) $read->getContent(), true));
        self::assertSame(Response::HTTP_FORBIDDEN, $controller->save(SessionRequest::json(['enabled' => ['cash']], $cashier))->getStatusCode());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $controller->show(SessionRequest::json([], []))->getStatusCode());
    }

    public function testAnAdminSavesAndARefusedChoiceSaysWhy(): void
    {
        $settings = $this->createMock(PaymentMethodsSettings::class);
        $settings->method('save')->willReturnCallback(static function (mixed $methods): void {
            if ($methods === []) {
                throw new \DomainException('Deja al menos un método de pago activo');
            }
        });
        $settings->method('enabled')->willReturn(['cash', 'transfer']);
        $controller = new PaymentMethodsController($settings);
        $admin = ['user_id' => 1, 'role' => 'admin'];

        $ok = $controller->save(SessionRequest::json(['enabled' => ['cash', 'transfer']], $admin));
        self::assertSame(['cash', 'transfer'], json_decode((string) $ok->getContent(), true)['data']['enabled']);

        $bad = $controller->save(SessionRequest::json(['enabled' => []], $admin));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $bad->getStatusCode());
        self::assertSame('Deja al menos un método de pago activo', json_decode((string) $bad->getContent(), true)['error']);
    }
}
