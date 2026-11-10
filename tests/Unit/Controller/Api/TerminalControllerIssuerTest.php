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

    public function testTheIbanIsSavedAsShopDataWithoutSpacesAndAWrongOneIsRejected(): void
    {
        [$controller, $updates] = $this->controller();

        $ok = $controller->update(1, SessionRequest::json(['bank_account' => 'es91 2100 0418 4502 0005 1332'], self::ADMIN));
        $bad = $controller->update(1, SessionRequest::json(['bank_account' => 'ES92 2100 0418 4502 0005 1332'], self::ADMIN));
        $controller->update(1, SessionRequest::json(['bank_account' => ' '], self::ADMIN));

        $this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $bad->getStatusCode());
        $this->assertSame('Número de cuenta no válido: revisa el IBAN (ES y 22 cifras)', json_decode((string) $bad->getContent(), true)['error']);
        $this->assertCount(2, $updates, 'the wrong one saved nothing');
        $this->assertSame(['bank_account' => 'ES9121000418450200051332'], $updates[0]);
        $this->assertSame(['bank_account' => null], $updates[1]);
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
