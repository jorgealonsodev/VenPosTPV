<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use VenPosTPV\Controller\Api\CatalogEditController;
use VenPosTPV\Service\Catalog\CatalogEditService;
use VenPosTPV\Service\Catalog\Exception\CatalogEditException;
use VenPosTPV\Tests\Support\SessionRequest;

/**
 * «Modo edición» endpoints: administrators only (the server decides, whatever the
 * screen shows), JSON writes, refusals answered with their reason.
 */
final class CatalogEditControllerTest extends TestCase
{
    private const ADMIN = ['user_id' => 1, 'role' => 'admin', 'terminal_id' => 3];
    private const CASHIER = ['user_id' => 5, 'role' => 'cashier', 'terminal_id' => 3];

    /** @var list<array<int, mixed>> */
    private array $calls = [];

    private function controller(?\Throwable $failure = null): CatalogEditController
    {
        $service = $this->createMock(CatalogEditService::class);
        foreach (['item', 'saveOverride', 'resetOverride', 'setStock'] as $method) {
            $service->method($method)->willReturnCallback(function (...$args) use ($method, $failure): array {
                $this->calls[] = [$method, ...$args];
                if ($failure !== null) {
                    throw $failure;
                }

                return ['id_product' => $args[0], 'id_product_attribute' => $args[1]];
            });
        }

        return new CatalogEditController($service);
    }

    /** @return array<string, mixed> */
    private static function json(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }

    /** @param array<string, mixed> $body @param array<string, mixed> $session */
    private static function jsonRequest(array $body, array $session): Request
    {
        $request = SessionRequest::json($body, $session);
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    public function testAnAdministratorReadsTheItemToEdit(): void
    {
        $response = $this->controller()->item(SessionRequest::create([], self::ADMIN), 7, 71);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['data' => ['id_product' => 7, 'id_product_attribute' => 71]], self::json($response));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testACashierIsRefusedEveryEditEndpointAndNothingRuns(): void
    {
        $controller = $this->controller();
        $responses = [
            $controller->item(SessionRequest::create([], self::CASHIER), 7, 0),
            $controller->saveOverride(self::jsonRequest(['ean13' => '4006381333931'], self::CASHIER), 7, 0),
            $controller->resetOverride(SessionRequest::create([], self::CASHIER), 7, 0),
            $controller->setStock(self::jsonRequest(['quantity' => 3], self::CASHIER), 7, 0),
        ];

        foreach ($responses as $response) {
            self::assertSame(403, $response->getStatusCode());
            self::assertSame('Acceso denegado — solo administradores', self::json($response)['error']);
        }
        self::assertSame([], $this->calls);
    }

    public function testWithoutASessionTheAnswerIs401(): void
    {
        self::assertSame(401, $this->controller()->item(SessionRequest::create(), 7, 0)->getStatusCode());
        self::assertSame([], $this->calls);
    }

    public function testWritesAcceptJsonOnly(): void
    {
        $request = SessionRequest::create([], self::ADMIN, 'quantity=3');

        self::assertSame(415, $this->controller()->setStock($request, 7, 0)->getStatusCode());
        self::assertSame([], $this->calls);
    }

    public function testSavingPassesTheValuesAndWhoMadeTheChange(): void
    {
        $request = self::jsonRequest(['ean13' => '4006381333931', 'reference' => 'REF-L'], self::ADMIN);

        $response = $this->controller()->saveOverride($request, 7, 71);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['saveOverride', 7, 71, '4006381333931', 'REF-L', 1, 3, null], $this->calls[0]);
    }

    public function testResetAndStockReachTheService(): void
    {
        $controller = $this->controller();

        self::assertSame(200, $controller->resetOverride(SessionRequest::create([], self::ADMIN), 7, 0)->getStatusCode());
        self::assertSame(200, $controller->setStock(self::jsonRequest(['quantity' => 12], self::ADMIN), 7, 71)->getStatusCode());

        self::assertSame(['resetOverride', 7, 0, 1, 3, null], $this->calls[0]);
        self::assertSame(['setStock', 7, 71, 12, 1, 3, null], $this->calls[1]);
    }

    public function testARefusedEditAnswersItsStatusAndReason(): void
    {
        $failure = CatalogEditException::invalid('El EAN debe tener 8 o 13 cifras', 'invalid_ean');

        $response = $this->controller($failure)->saveOverride(self::jsonRequest(['ean13' => '123'], self::ADMIN), 7, 0);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => 'El EAN debe tener 8 o 13 cifras', 'code' => 'invalid_ean'], self::json($response));
    }

    public function testAPrestaShopFailureOnStockIsABadGateway(): void
    {
        $failure = new CatalogEditException('PrestaShop no ha aceptado el cambio de stock; no se ha cambiado nada', 502, 'prestashop_stock_failed');

        $response = $this->controller($failure)->setStock(self::jsonRequest(['quantity' => 2], self::ADMIN), 7, 0);

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('prestashop_stock_failed', self::json($response)['code']);
    }
}
