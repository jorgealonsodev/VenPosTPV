<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\TerminalController;
use VenPosTPV\Service\Print\SystemPrintQueue;
use VenPosTPV\Tests\Support\SessionRequest;

final class TerminalControllerTicketLetterTest extends TestCase
{
    private const ADMIN = ['user_id' => 1, 'role' => 'admin'];

    /**
     * @param list<string> $lettersInUseByOthers
     *
     * @return array{0: TerminalController, 1: \ArrayObject<int, array<string, mixed>>, 2: \ArrayObject<int, list<mixed>>}
     */
    private function controller(array $lettersInUseByOthers = []): array
    {
        $updates = new \ArrayObject();
        $lookups = new \ArrayObject();
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(['id' => 1]);
        $conn->method('fetchOne')->willReturnCallback(function (string $sql, array $params = []) use ($lettersInUseByOthers, $lookups): int {
            $lookups->append($params);

            return \in_array($params[0] ?? null, $lettersInUseByOthers, true) ? 1 : 0;
        });
        $conn->method('update')->willReturnCallback(function (string $table, array $fields) use ($updates): int {
            $updates->append($fields);

            return 1;
        });

        return [new TerminalController($conn, $this->createMock(SystemPrintQueue::class)), $updates, $lookups];
    }

    public function testLetterIsUppercasedAndSaved(): void
    {
        [$controller, $updates, $lookups] = $this->controller();

        $response = $controller->update(3, SessionRequest::json(['ticket_letter' => ' b '], self::ADMIN));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('B', $updates[0]['ticket_letter']);
        $this->assertSame(['B', 3], $lookups[0], 'uniqueness is checked against the other terminals');
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidLetters(): iterable
    {
        yield 'empty' => [''];
        yield 'two letters' => ['AB'];
        yield 'digit' => ['1'];
        yield 'accented' => ['Ñ'];
        yield 'null' => [null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLetters')]
    public function testInvalidLetterIsRejectedAndNothingIsSaved(mixed $letter): void
    {
        [$controller, $updates] = $this->controller();

        $response = $controller->update(1, SessionRequest::json(['ticket_letter' => $letter, 'store_name' => 'Moda'], self::ADMIN));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertSame('La letra de caja debe ser una letra de la A a la Z', json_decode((string) $response->getContent(), true)['error']);
        $this->assertCount(0, $updates);
    }

    public function testLetterUsedByAnotherTerminalIsRejected(): void
    {
        [$controller, $updates] = $this->controller(['A']);

        $response = $controller->update(2, SessionRequest::json(['ticket_letter' => 'a'], self::ADMIN));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertSame('Esa letra ya la usa otra caja', json_decode((string) $response->getContent(), true)['error']);
        $this->assertCount(0, $updates);
    }

    public function testUpdateWithoutLetterLeavesItUntouched(): void
    {
        [$controller, $updates] = $this->controller();

        $controller->update(1, SessionRequest::json(['store_name' => 'Moda'], self::ADMIN));

        $this->assertArrayNotHasKey('ticket_letter', $updates[0]);
    }

    public function testGetExposesTheLetter(): void
    {
        $queries = new \ArrayObject();
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturnCallback(function (string $sql) use ($queries): array {
            $queries->append($sql);

            return ['id' => 1, 'printer_config' => null, 'ticket_letter' => 'C'];
        });
        $controller = new TerminalController($conn, $this->createMock(SystemPrintQueue::class));

        $response = $controller->get(1, SessionRequest::create([], self::ADMIN));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringContainsString('ticket_letter', $queries[0]);
        $this->assertSame('C', json_decode((string) $response->getContent(), true)['data']['ticket_letter']);
    }
}
