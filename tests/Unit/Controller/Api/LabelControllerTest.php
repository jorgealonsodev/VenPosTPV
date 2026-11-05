<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use VenPosTPV\Controller\Api\LabelController;
use VenPosTPV\Service\Label\BarcodeSelector;
use VenPosTPV\Service\Label\Label;
use VenPosTPV\Service\Label\LabelCatalog;
use VenPosTPV\Service\Label\LabelPreviewRenderer;
use VenPosTPV\Service\Label\LabelPrintException;
use VenPosTPV\Service\Label\LabelPrintService;
use VenPosTPV\Service\Label\LabelSettings;
use VenPosTPV\Service\Label\LabelSettingsStore;
use VenPosTPV\Service\Print\PrinterConfig;
use VenPosTPV\Service\PrintAgent\AgentRegistry;
use VenPosTPV\Tests\Support\SessionRequest;

/**
 * docs/prd/agente-impresion.md L1, L2, L8, L9, T9: the label endpoints of the POS.
 * Any logged user prints labels; writes accept JSON only (415); the till is the
 * one of the session and is never assumed (400 terminal_not_resolved).
 */
final class LabelControllerTest extends TestCase
{
    private const CASHIER = ['user_id' => 5, 'role' => 'cashier', 'terminal_id' => 3];
    private const ITEMS = ['items' => [['product_id' => 7, 'id_product_attribute' => 71, 'quantity' => 10]]];

    /** @var list<array<int, mixed>> */
    private array $printed = [];
    /** @var list<string> */
    private array $calls = [];

    private function controller(bool $connected = true, string $printer = '{"enabled":true,"type":"agent"}'): LabelController
    {
        $service = $this->createMock(LabelPrintService::class);
        $service->method('print')->willReturnCallback(function (...$args): array {
            $this->printed[] = $args;

            return ['jobs' => [41], 'print_status' => 'queued', 'labels' => 10, 'errors' => []];
        });
        $service->method('lineLabels')->willReturn([
            ['product_id' => 7, 'id_product_attribute' => 71, 'quantity' => 10, 'label' => new Label('Camiseta', 'M · Azul', 23.14, (new BarcodeSelector())->select('8412345678905', null, null)), 'reason' => null],
            ['product_id' => 8, 'id_product_attribute' => 0, 'quantity' => 1, 'label' => null, 'reason' => 'Sin EAN ni referencia: no se puede imprimir la etiqueta'],
        ]);
        $service->method('pending')->willReturn(['jobs' => 2, 'labels' => 37]);
        $service->method('releasePending')->willReturnCallback(function (int $terminal): int {
            $this->calls[] = 'release:' . $terminal;

            return 37;
        });
        $service->method('discardPending')->willReturnCallback(function (int $terminal): int {
            $this->calls[] = 'discard:' . $terminal;

            return 37;
        });
        $service->method('printerConfig')->willReturn(PrinterConfig::fromJson($printer));
        $service->method('printTest')->willReturn(['jobs' => [42], 'print_status' => 'queued']);

        $settings = $this->createMock(LabelSettingsStore::class);
        $settings->method('forTerminal')->willReturn(LabelSettings::defaults());
        $agents = $this->createMock(AgentRegistry::class);
        $agents->method('statusOf')->willReturn(['paired' => true, 'connected' => $connected]);
        $catalog = $this->createMock(LabelCatalog::class);
        $catalog->method('findByCode')->willReturnCallback(static fn (string $code): ?array => $code === '8412345678905' ? ['product_id' => 7, 'id_product_attribute' => 71] : null);
        $catalog->method('productData')->willReturn(['id' => 7, 'name' => 'Camiseta', 'stock' => 0, 'image_url' => 'https://shop.example.com/img/p/7/7-home_default.jpg', 'combinations' => [
            ['id' => 71, 'stock' => 4, 'attributes' => [['group_name' => 'Talla', 'attribute_name' => 'M'], ['group_name' => 'Color', 'attribute_name' => 'Azul']]],
        ]]);
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(3);

        return new LabelController($service, new LabelPreviewRenderer(), $settings, $catalog, $agents, $conn);
    }

    /** @param array<string, mixed> $body @param array<string, mixed> $session */
    private static function json(array $body, array $session = self::CASHIER): Request
    {
        $request = SessionRequest::json($body, $session);
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    /** @return array<string, mixed> */
    private static function body(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testACashierPrintsLabelsOnTheTillOfTheSession(): void
    {
        $response = $this->controller()->print(self::json(self::ITEMS));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['jobs' => [41], 'print_status' => 'queued', 'labels' => 10, 'errors' => []], self::body($response));
        self::assertSame(3, $this->printed[0][0]);
        self::assertSame(self::ITEMS['items'], $this->printed[0][1]);
        self::assertSame(5, $this->printed[0][2]);
    }

    public function testPrintingWithoutLoginIs401AndWithoutJsonIs415(): void
    {
        self::assertSame(401, $this->controller()->print(self::json(self::ITEMS, []))->getStatusCode());
        $form = SessionRequest::create([], self::CASHIER, 'items=1');
        $form->headers->set('Content-Type', 'application/x-www-form-urlencoded');
        self::assertSame(415, $this->controller()->print($form)->getStatusCode());
        self::assertSame([], $this->printed);
    }

    public function testWithoutATillInTheSessionNothingIsPrinted(): void
    {
        $response = $this->controller()->print(self::json(self::ITEMS, ['user_id' => 5, 'role' => 'cashier']));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('terminal_not_resolved', self::body($response)['code']);
        self::assertSame([], $this->printed);
    }

    public function testARefusedRequestAnswersItsStatusAndReason(): void
    {
        $service = $this->createMock(LabelPrintService::class);
        $service->method('print')->willThrowException(new LabelPrintException('Máximo 100 etiquetas por línea'));
        $controller = new LabelController($service, new LabelPreviewRenderer(), $this->createMock(LabelSettingsStore::class), $this->createMock(LabelCatalog::class), $this->createMock(AgentRegistry::class), $this->createMock(Connection::class));

        $response = $controller->print(self::json(self::ITEMS));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => 'Máximo 100 etiquetas por línea', 'code' => 'invalid_labels'], self::body($response));
    }

    public function testThePreviewHasAnImagePerLineAndTheReasonForTheOthers(): void
    {
        $body = self::body($this->controller()->preview(self::json(self::ITEMS)));

        self::assertCount(1, $body['previews']);
        $preview = $body['previews'][0];
        self::assertSame(['Camiseta', 'M · Azul', '23,14 €', 'EAN13', '8412345678905', 10], [$preview['name'], $preview['combination'], $preview['price_text'], $preview['barcode']['type'], $preview['barcode']['text'], $preview['quantity']]);
        self::assertStringStartsWith('data:image/png;base64,', $preview['image']);
        self::assertSame([['product_id' => 8, 'id_product_attribute' => 0, 'reason' => 'Sin EAN ni referencia: no se puede imprimir la etiqueta']], $body['errors']);
        self::assertSame(10, $body['labels']);
    }

    public function testPendingLabelsAreAskedAboutOnlyWhenTheAgentIsBack(): void
    {
        $online = self::body($this->controller(true)->pending(SessionRequest::create([], self::CASHIER)));
        self::assertSame(['jobs' => 2, 'labels' => 37, 'connected' => true, 'ask' => true, 'message' => 'Hay 37 etiquetas pendientes. ¿Imprimirlas?'], $online);

        $offline = self::body($this->controller(false)->pending(SessionRequest::create([], self::CASHIER)));
        self::assertFalse($offline['ask']);
    }

    public function testACashierOnlySeesThePendingLabelsOfTheirTill(): void
    {
        $response = $this->controller()->pending(SessionRequest::create(['terminal_id' => '9'], self::CASHIER));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testPendingLabelsArePrintedOrDiscardedOnRequest(): void
    {
        self::assertSame(['labels' => 37], self::body($this->controller()->printPending(self::json([]))));
        self::assertSame(['labels' => 37], self::body($this->controller()->discardPending(self::json([]))));
        self::assertSame(['release:3', 'discard:3'], $this->calls);
    }

    public function testAScannedCodeFindsTheCombinationWithItsStock(): void
    {
        $found = self::body($this->controller()->lookup(SessionRequest::create(['code' => '8412345678905'], self::CASHIER)));
        self::assertSame(['product_id' => 7, 'id_product_attribute' => 71, 'name' => 'Camiseta', 'combination' => 'M · Azul', 'stock' => 4, 'image_url' => 'https://shop.example.com/img/p/7/7-home_default.jpg'], $found);

        self::assertSame(404, $this->controller()->lookup(SessionRequest::create(['code' => '000'], self::CASHIER))->getStatusCode());
    }

    public function testTheTestLabelIsForAdministrators(): void
    {
        self::assertSame(403, $this->controller()->testLabel(self::json([]), 3)->getStatusCode());

        $response = $this->controller()->testLabel(self::json([], ['user_id' => 1, 'role' => 'admin']), 3);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('queued', self::body($response)['print_status']);
    }
}
