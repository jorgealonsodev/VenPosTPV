<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\TerminalController;
use VenPosTPV\Service\Print\SystemPrintQueue;
use VenPosTPV\Tests\Support\SessionRequest;

final class TerminalControllerIssuerTest extends TestCase
{
    private const ADMIN = ['user_id' => 1, 'role' => 'admin'];

    /** @return array{0: TerminalController, 1: \ArrayObject<int, array<string, mixed>>} */
    private function controller(): array
    {
        $updates = new \ArrayObject();
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(['id' => 1]);
        $conn->method('update')->willReturnCallback(function (string $table, array $fields) use ($updates): int {
            $updates->append($fields);

            return 1;
        });

        return [new TerminalController($conn, $this->createMock(SystemPrintQueue::class)), $updates];
    }

    public function testTaxIdIsNormalizedAndSavedWithTheLegalName(): void
    {
        [$controller, $updates] = $this->controller();

        $response = $controller->update(1, SessionRequest::json(['tax_id' => ' b-12345674 ', 'legal_name' => ' Tienda Moda SL '], self::ADMIN));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('B12345674', $updates[0]['tax_id']);
        $this->assertSame('Tienda Moda SL', $updates[0]['legal_name']);
    }

    public function testInvalidTaxIdIsRejectedAndNothingIsSaved(): void
    {
        [$controller, $updates] = $this->controller();

        $response = $controller->update(1, SessionRequest::json(['tax_id' => 'B12345675', 'legal_name' => 'Tienda Moda SL'], self::ADMIN));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertSame('NIF no válido: revisa las cifras y la letra de control', json_decode((string) $response->getContent(), true)['error']);
        $this->assertCount(0, $updates);
    }

    public function testEmptyValuesClearTheFields(): void
    {
        [$controller, $updates] = $this->controller();

        $controller->update(1, SessionRequest::json(['tax_id' => '', 'legal_name' => '  '], self::ADMIN));

        $this->assertNull($updates[0]['tax_id']);
        $this->assertNull($updates[0]['legal_name']);
    }

    public function testStoreNameIsSavedTrimmedAndClearedWhenEmpty(): void
    {
        [$controller, $updates] = $this->controller();

        $controller->update(1, SessionRequest::json(['store_name' => ' Moda Centro '], self::ADMIN));
        $controller->update(1, SessionRequest::json(['store_name' => ''], self::ADMIN));

        $this->assertSame('Moda Centro', $updates[0]['store_name']);
        $this->assertNull($updates[1]['store_name']);
    }
}
