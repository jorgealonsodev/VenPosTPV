<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\TerminalController;
use VenPosTPV\Service\Print\SystemPrintQueue;
use VenPosTPV\Tests\Support\SessionRequest;

final class TerminalControllerPrinterTest extends TestCase
{
    private const ADMIN = ['user_id' => 1, 'role' => 'admin'];

    /** @return array{0: TerminalController, 1: \ArrayObject<int, array<string, mixed>>} */
    private function controllerCapturingUpdates(): array
    {
        $updates = new \ArrayObject();
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(['id' => 1]);
        $conn->method('update')->willReturnCallback(function (string $table, array $fields) use ($updates): int {
            $updates->append($fields);

            return 1;
        });
        $queue = $this->createMock(SystemPrintQueue::class);
        $queue->method('list')->willReturn(['Brother_MFC', 'Epson_TM-T20II']);

        return [new TerminalController($conn, $queue), $updates];
    }

    public function testSystemPrinterStoresTheChosenQueue(): void
    {
        [$controller, $updates] = $this->controllerCapturingUpdates();

        $response = $controller->update(1, SessionRequest::json(['printer_type' => 'system', 'printer_queue' => 'Epson_TM-T20II'], self::ADMIN));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('system', $updates[0]['printer_type']);
        $this->assertSame(['enabled' => true, 'type' => 'system', 'queue' => 'Epson_TM-T20II'], json_decode($updates[0]['printer_config'], true));
    }

    public function testSystemPrinterMustBeAnInstalledQueue(): void
    {
        [$controller, $updates] = $this->controllerCapturingUpdates();

        $response = $controller->update(1, SessionRequest::json(['printer_type' => 'system', 'printer_queue' => 'Nope'], self::ADMIN));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertCount(0, $updates);
    }

    public function testNetworkPrinterStoresHostAndPortThatWereIgnoredBefore(): void
    {
        [$controller, $updates] = $this->controllerCapturingUpdates();

        $controller->update(1, SessionRequest::json(['printer_type' => 'escpos', 'printer_ip' => '192.168.1.50', 'printer_port' => '9100'], self::ADMIN));

        $this->assertSame(['enabled' => true, 'type' => 'network', 'host' => '192.168.1.50', 'port' => 9100], json_decode($updates[0]['printer_config'], true));
    }

    public function testPdfOrNoPrinterDisablesDirectPrinting(): void
    {
        [$controller, $updates] = $this->controllerCapturingUpdates();

        $controller->update(1, SessionRequest::json(['printer_type' => 'pdf'], self::ADMIN));

        $this->assertSame(['enabled' => false, 'type' => 'pdf'], json_decode($updates[0]['printer_config'], true));
    }

    public function testInstalledQueuesAreListedForAdmins(): void
    {
        [$controller] = $this->controllerCapturingUpdates();

        $this->assertSame(Response::HTTP_FORBIDDEN, $controller->systemPrinters(SessionRequest::json([], ['user_id' => 2, 'role' => 'cashier']))->getStatusCode());
        $data = json_decode((string) $controller->systemPrinters(SessionRequest::json([], self::ADMIN))->getContent(), true);
        $this->assertSame(['Brother_MFC', 'Epson_TM-T20II'], $data['data']);
    }

    public function testGetExposesTheStoredPrinterSettingsForTheForm(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(['id' => 1, 'printer_type' => 'system', 'printer_config' => json_encode(['enabled' => true, 'type' => 'system', 'queue' => 'Epson_TM-T20II'])]);

        $data = json_decode((string) (new TerminalController($conn, $this->createMock(SystemPrintQueue::class)))->get(1, SessionRequest::json([], self::ADMIN))->getContent(), true)['data'];

        $this->assertSame('Epson_TM-T20II', $data['printer_queue']);
    }
}
