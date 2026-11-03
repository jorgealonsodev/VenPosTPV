<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Controller\Api\TerminalController;
use VenPosTPV\Service\Label\LabelSettings;
use VenPosTPV\Service\Label\LabelSettingsStore;
use VenPosTPV\Service\Print\SystemPrintQueue;
use VenPosTPV\Tests\Support\SessionRequest;

/**
 * docs/prd/agente-impresion.md L5, L6: each till stores its label height (25-60 mm)
 * and whether to cut every label; Ajustes reads and writes them on the till.
 */
final class TerminalControllerLabelSettingsTest extends TestCase
{
    private const ADMIN = ['user_id' => 1, 'role' => 'admin'];

    /** @var list<array{string, array<string, mixed>, array<string, mixed>}> */
    private array $updates = [];

    private function controller(bool $available = true): TerminalController
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(['id' => 4, 'ps_shop_id' => 1, 'printer_config' => null]);
        $conn->method('update')->willReturnCallback(function (string $table, array $fields, array $where): int {
            $this->updates[] = [$table, $fields, $where];

            return 1;
        });
        $store = $this->createMock(LabelSettingsStore::class);
        $store->method('forTerminal')->willReturn(new LabelSettings(45, true));
        $store->method('isAvailable')->willReturn($available);

        return new TerminalController($conn, $this->createMock(SystemPrintQueue::class), $store);
    }

    public function testTheTillShowsItsLabelSettings(): void
    {
        $data = json_decode((string) $this->controller()->get(4, SessionRequest::create([], self::ADMIN))->getContent(), true)['data'];

        self::assertSame(45, $data['label_height_mm']);
        self::assertTrue($data['label_cut_each']);
    }

    public function testTheLabelSettingsAreSavedOnThatTillOnly(): void
    {
        $response = $this->controller()->update(4, SessionRequest::json(['label_height_mm' => 40, 'label_cut_each' => true], self::ADMIN));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([['terminal', ['label_height_mm' => 40, 'label_cut_each' => 1], ['id' => 4]]], $this->updates);
    }

    public function testAHeightOutOfBoundsIsRefused(): void
    {
        foreach ([24, 61] as $mm) {
            $response = $this->controller()->update(4, SessionRequest::json(['label_height_mm' => $mm], self::ADMIN));
            self::assertSame(422, $response->getStatusCode());
            self::assertSame('Alto de etiqueta no válido (25-60 mm)', json_decode((string) $response->getContent(), true)['error']);
        }
        self::assertSame([], $this->updates);
    }

    public function testBeforeTheMigrationTheLabelSettingsCannotBeSaved(): void
    {
        $response = $this->controller(false)->update(4, SessionRequest::json(['label_height_mm' => 40], self::ADMIN));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame([], $this->updates);
    }
}
