<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\TerminalController;
use VenPosTPV\Service\Print\SystemPrintQueue;
use VenPosTPV\Tests\Support\SessionRequest;

/** Ajustes → Cajas: left and right ticket margins (mm) and the logo width (% of the printable width), per till. */
final class TerminalControllerTicketLayoutTest extends TestCase
{
    private const ADMIN = ['user_id' => 1, 'role' => 'admin', 'shop_id' => 1];

    /**
     * @param array<string, mixed>|false|\Throwable $layout what the layout query answers
     *
     * @return array{0: TerminalController, 1: \ArrayObject<int, array<string, mixed>>}
     */
    private function controller(array|false|\Throwable $layout = false): array
    {
        $updates = new \ArrayObject();
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturnCallback(function (string $sql) use ($layout): array|false {
            if (str_contains($sql, 'ticket_margin_left_mm')) {
                if ($layout instanceof \Throwable) {
                    throw $layout;
                }

                return $layout;
            }

            return ['id' => 2, 'ps_shop_id' => 1, 'printer_config' => null, 'ticket_width_px' => 302];
        });
        $conn->method('fetchOne')->willReturn(0);
        $conn->method('update')->willReturnCallback(function (string $table, array $fields) use ($updates): int {
            $updates->append($fields);

            return 1;
        });

        return [new TerminalController($conn, $this->createMock(SystemPrintQueue::class)), $updates];
    }

    public function testTheMarginsAndTheLogoWidthAreSavedOnTheTill(): void
    {
        [$controller, $updates] = $this->controller();

        $response = $controller->update(2, SessionRequest::json([
            'ticket_margin_left_mm' => '3.5', 'ticket_margin_right_mm' => 0, 'ticket_logo_width_pct' => 60,
        ], self::ADMIN));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(['ticket_margin_left_mm' => 3.5, 'ticket_margin_right_mm' => 0.0, 'ticket_logo_width_pct' => 60], $updates[0]);
    }

    public function testEmptyValuesGoBackToTheDefault(): void
    {
        [$controller, $updates] = $this->controller();

        $response = $controller->update(2, SessionRequest::json([
            'ticket_margin_left_mm' => '', 'ticket_margin_right_mm' => null, 'ticket_logo_width_pct' => '',
        ], self::ADMIN));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(['ticket_margin_left_mm' => null, 'ticket_margin_right_mm' => null, 'ticket_logo_width_pct' => null], $updates[0]);
    }

    public function testAMarginIsKeptToOneDecimal(): void
    {
        [$controller, $updates] = $this->controller();

        $controller->update(2, SessionRequest::json(['ticket_margin_right_mm' => 2.26], self::ADMIN));

        $this->assertSame(['ticket_margin_right_mm' => 2.3], $updates[0]);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidMargins(): iterable
    {
        yield 'left above 10' => ['ticket_margin_left_mm', 10.5];
        yield 'left negative' => ['ticket_margin_left_mm', -1];
        yield 'right not a number' => ['ticket_margin_right_mm', 'abc'];
        yield 'right above 10' => ['ticket_margin_right_mm', '11'];
    }

    #[DataProvider('invalidMargins')]
    public function testAMarginOutsideZeroToTenIsRejectedAndNothingIsSaved(string $field, mixed $value): void
    {
        [$controller, $updates] = $this->controller();

        $response = $controller->update(2, SessionRequest::json([$field => $value, 'ticket_width_px' => 302], self::ADMIN));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertSame('El margen debe estar entre 0 y 10 mm', json_decode((string) $response->getContent(), true)['error']);
        $this->assertCount(0, $updates);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidLogoWidths(): iterable
    {
        yield 'below 20' => [19];
        yield 'above 100' => [101];
        yield 'not a number' => ['grande'];
    }

    #[DataProvider('invalidLogoWidths')]
    public function testALogoWidthOutsideTwentyToHundredIsRejectedAndNothingIsSaved(mixed $value): void
    {
        [$controller, $updates] = $this->controller();

        $response = $controller->update(2, SessionRequest::json(['ticket_logo_width_pct' => $value], self::ADMIN));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertSame('El tamaño del logo debe estar entre 20 y 100 %', json_decode((string) $response->getContent(), true)['error']);
        $this->assertCount(0, $updates);
    }

    public function testAnOlderSaveWithoutTheNewFieldsChangesExactlyWhatItChangedBefore(): void
    {
        [$controller, $updates] = $this->controller();

        $response = $controller->update(2, SessionRequest::json([
            'dataphone_mode' => 'independent', 'printer_type' => 'pdf', 'ticket_width_px' => 280,
        ], self::ADMIN));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame([
            'dataphone_mode' => 'independent',
            'printer_type' => 'pdf',
            'printer_config' => '{"enabled":false,"type":"pdf"}',
            'ticket_width_px' => 280,
        ], $updates[0]);
    }

    public function testTheTillReturnsItsMarginsAndLogoWidth(): void
    {
        [$controller] = $this->controller(['ticket_margin_left_mm' => '3.5', 'ticket_margin_right_mm' => null, 'ticket_logo_width_pct' => '60']);

        $data = json_decode((string) $controller->get(2, SessionRequest::json([], self::ADMIN))->getContent(), true)['data'];

        $this->assertSame(3.5, $data['ticket_margin_left_mm']);
        $this->assertNull($data['ticket_margin_right_mm']);
        $this->assertSame(60, $data['ticket_logo_width_pct']);
        $this->assertSame(302, $data['ticket_width_px'], 'the other settings are still returned');
    }

    public function testBeforeTheMigrationTheTillIsReturnedWithoutTheNewFields(): void
    {
        [$controller] = $this->controller(new \RuntimeException("Unknown column 'ticket_margin_left_mm'"));

        $response = $controller->get(2, SessionRequest::json([], self::ADMIN));
        $data = json_decode((string) $response->getContent(), true)['data'];

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertArrayNotHasKey('ticket_margin_left_mm', $data);
        $this->assertSame(302, $data['ticket_width_px']);
    }
}
