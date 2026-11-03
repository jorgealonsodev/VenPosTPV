<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\TerminalController;
use VenPosTPV\Service\Print\SystemPrintQueue;
use VenPosTPV\Tests\Support\SessionRequest;

/**
 * S10: shop data (name, NIF, address, header, footer, logo, refund window, ticket
 * code) is the same on every till; name, letter, printer and dataphone are per till.
 */
final class TerminalControllerTillsTest extends TestCase
{
    private const ADMIN = ['user_id' => 1, 'role' => 'admin', 'shop_id' => 1];

    /**
     * @param list<string> $lettersInUse
     *
     * @return array{0: TerminalController, 1: \ArrayObject<int, array{0: array<string, mixed>, 1: array<string, mixed>}>, 2: \ArrayObject<int, array<string, mixed>>}
     */
    private function controller(array $lettersInUse = []): array
    {
        $updates = new \ArrayObject();
        $inserts = new \ArrayObject();
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturnCallback(function (string $sql): array {
            if (str_contains($sql, 'ps_shop_id = ?')) {
                // The till new ones copy the shop data from.
                return ['store_name' => 'Moda Centro', 'store_address' => 'Calle 1', 'legal_name' => 'Moda SL', 'tax_id' => 'B12345674',
                    'ticket_header' => 'Hola', 'ticket_footer' => 'Adiós', 'ticket_logo' => null, 'refund_window_days' => 15, 'ticket_code' => 'qr'];
            }

            return ['id' => 2, 'ps_shop_id' => 1];
        });
        $conn->method('fetchOne')->willReturnCallback(fn (string $sql, array $params = []): int => \in_array($params[0] ?? null, $lettersInUse, true) ? 1 : 0);
        $conn->method('update')->willReturnCallback(function (string $table, array $fields, array $criteria) use ($updates): int {
            $updates->append([$fields, $criteria]);

            return 1;
        });
        $conn->method('insert')->willReturnCallback(function (string $table, array $fields) use ($inserts): int {
            $inserts->append($fields);

            return 1;
        });
        $conn->method('lastInsertId')->willReturn('9');

        return [new TerminalController($conn, $this->createMock(SystemPrintQueue::class)), $updates, $inserts];
    }

    /** @param array<string, mixed> $body @param array<string, mixed> $session */
    private function json(array $body, array $session): Request
    {
        $request = SessionRequest::json($body, $session);
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    public function testShopDataIsSavedOnEveryTillAndTillDataOnlyOnThisOne(): void
    {
        [$controller, $updates] = $this->controller();

        $response = $controller->update(2, $this->json(['store_name' => 'Moda Norte', 'ticket_footer' => 'Gracias', 'refund_window_days' => 20, 'ticket_code' => 'both', 'name' => 'Caja 2', 'dataphone_mode' => 'independent'], self::ADMIN));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $byCriteria = [];
        foreach ($updates as [$fields, $criteria]) {
            $byCriteria[json_encode($criteria)] = $fields;
        }
        $this->assertSame(['store_name' => 'Moda Norte', 'ticket_footer' => 'Gracias', 'ticket_code' => 'both', 'refund_window_days' => 20], $byCriteria['{"ps_shop_id":1}'] ?? null);
        $this->assertSame(['name' => 'Caja 2', 'dataphone_mode' => 'independent'], $byCriteria['{"id":2}'] ?? null);
    }

    public function testATillIsCreatedWithItsLetterAndTheShopData(): void
    {
        [$controller, , $inserts] = $this->controller();

        $response = $controller->create($this->json(['name' => ' Caja 2 ', 'ticket_letter' => 'b'], self::ADMIN));

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $this->assertSame(9, json_decode((string) $response->getContent(), true)['data']['id']);
        $this->assertCount(1, $inserts);
        $this->assertSame('Caja 2', $inserts[0]['name']);
        $this->assertSame('B', $inserts[0]['ticket_letter']);
        $this->assertSame(1, $inserts[0]['ps_shop_id']);
        $this->assertSame('Moda Centro', $inserts[0]['store_name']);
        $this->assertSame('B12345674', $inserts[0]['tax_id']);
        $this->assertSame(15, $inserts[0]['refund_window_days']);
        $this->assertSame('qr', $inserts[0]['ticket_code']);
    }

    public function testCreatingATillValidatesNameAndLetter(): void
    {
        [$controller, , $inserts] = $this->controller(['A']);

        $badLetter = $controller->create($this->json(['name' => 'Caja 2', 'ticket_letter' => '1'], self::ADMIN));
        $usedLetter = $controller->create($this->json(['name' => 'Caja 2', 'ticket_letter' => 'a'], self::ADMIN));
        $noName = $controller->create($this->json(['name' => ' ', 'ticket_letter' => 'C'], self::ADMIN));

        $this->assertSame('La letra de caja debe ser una letra de la A a la Z', json_decode((string) $badLetter->getContent(), true)['error']);
        $this->assertSame('Esa letra ya la usa otra caja', json_decode((string) $usedLetter->getContent(), true)['error']);
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $noName->getStatusCode());
        $this->assertCount(0, $inserts);
    }

    public function testOnlyAdminsCreateTills(): void
    {
        [$controller, , $inserts] = $this->controller();

        $response = $controller->create($this->json(['name' => 'Caja 2', 'ticket_letter' => 'C'], ['user_id' => 5, 'role' => 'cashier']));

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertCount(0, $inserts);
    }
}
