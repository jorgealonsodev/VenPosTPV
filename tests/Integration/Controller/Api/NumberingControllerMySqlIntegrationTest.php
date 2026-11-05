<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Controller\Api;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\NumberingController;
use VenPosTPV\Service\Numbering\NumberingSettings;
use VenPosTPV\Service\Refund\CreditNoteNumberer;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Tests\Support\SessionRequest;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * Where the ticket and credit-note series continue after losing the database or
 * installing for another shop: forward only, audited, and safe against a sale
 * taking a number at the same time.
 */
final class NumberingControllerMySqlIntegrationTest extends TestCase
{
    private const SHOP = 94;
    private const TILL_K = 94301;
    private const TILL_M = 94302;
    private const USER = 94311;
    private const ADMIN = ['user_id' => self::USER, 'role' => 'admin', 'shop_id' => self::SHOP];
    private const CASHIER = ['user_id' => self::USER, 'role' => 'cashier', 'shop_id' => self::SHOP];

    private ?Connection $connection = null;
    private int $year;
    private string $yy;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->year = (int) (new \DateTimeImmutable())->format('Y');
        $this->yy = sprintf('%02d', $this->year % 100);
        $this->reset();
        $this->connection->insert('terminal', ['id' => self::TILL_K, 'ps_shop_id' => self::SHOP, 'name' => 'Caja K', 'ticket_letter' => 'K', 'active' => 1]);
        $this->connection->insert('terminal', ['id' => self::TILL_M, 'ps_shop_id' => self::SHOP, 'name' => 'Caja M', 'ticket_letter' => 'M', 'active' => 1]);
        $roleId = (int) $this->connection->fetchOne('SELECT id FROM role ORDER BY id LIMIT 1');
        $this->connection->insert('user', ['id' => self::USER, 'role_id' => $roleId, 'name' => 'Ana Admin', 'pin_hash' => 'x', 'active' => 1]);
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->reset();
            $this->connection->close();
        }
    }

    private function reset(): void
    {
        \assert($this->connection !== null);
        $this->connection->executeStatement('DELETE FROM numbering_change');
        $this->connection->executeStatement("DELETE FROM ticket_sequence WHERE letter IN ('K', 'M')");
        $this->connection->executeStatement('DELETE FROM credit_note_sequence WHERE year = ?', [$this->year]);
        $this->connection->executeStatement('DELETE FROM terminal WHERE id IN (?, ?)', [self::TILL_K, self::TILL_M]);
        $this->connection->executeStatement('DELETE FROM user WHERE id = ?', [self::USER]);
    }

    private function controller(): NumberingController
    {
        \assert($this->connection !== null);

        return new NumberingController($this->connection);
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function overview(): array
    {
        $response = $this->controller()->overview(SessionRequest::create([], self::ADMIN));
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        return $this->json($response)['data'];
    }

    /** @param array<string, mixed> $body @param array<string, mixed> $session */
    private function setTicket(int $terminalId, array $body, array $session = self::ADMIN): JsonResponse
    {
        return $this->controller()->setTicket($terminalId, SessionRequest::json($body, $session));
    }

    /** @param array<string, mixed> $body @param array<string, mixed> $session */
    private function setCreditNote(array $body, array $session = self::ADMIN): JsonResponse
    {
        return $this->controller()->setCreditNote(SessionRequest::json($body, $session));
    }

    private function sale(int $terminalId): string
    {
        \assert($this->connection !== null);
        $numberer = new TicketNumberer($this->connection);
        $now = new \DateTimeImmutable();
        $numberer->prepareYear($terminalId, $now);

        return $this->connection->transactional(static fn (): string => $numberer->next($terminalId, $now));
    }

    private function creditNote(): string
    {
        \assert($this->connection !== null);
        $numberer = new CreditNoteNumberer($this->connection);
        $now = new \DateTimeImmutable();
        $numberer->prepareYear($now);

        return $this->connection->transactional(static fn (): string => $numberer->next($now));
    }

    /** S1: every till of the shop with its series of the year; no row yet = last 0, next 1. */
    public function testTheOverviewShowsEachTillAndTheCreditNoteSeries(): void
    {
        $this->sale(self::TILL_K);
        $this->sale(self::TILL_K);

        $data = $this->overview();

        self::assertSame($this->year, $data['year']);
        self::assertCount(2, $data['tills']);
        [$k, $m] = $data['tills'];
        self::assertSame(['terminal_id' => self::TILL_K, 'name' => 'Caja K', 'letter' => 'K', 'series' => 'K' . $this->yy, 'last_number' => 2, 'next_number' => 3, 'next_code' => 'K' . $this->yy . '000003'], $k);
        self::assertSame(['terminal_id' => self::TILL_M, 'name' => 'Caja M', 'letter' => 'M', 'series' => 'M' . $this->yy, 'last_number' => 0, 'next_number' => 1, 'next_code' => 'M' . $this->yy . '000001'], $m);
        self::assertSame(['series' => 'R' . $this->year, 'last_number' => 0, 'next_number' => 1, 'next_code' => 'R' . $this->year . '-000001'], $data['credit_notes']);
        self::assertSame([], $data['changes']);
    }

    /** S1-S3: only an admin reads or changes the numbering. */
    public function testACashierIsRefused(): void
    {
        self::assertSame(Response::HTTP_FORBIDDEN, $this->controller()->overview(SessionRequest::create([], self::CASHIER))->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->setTicket(self::TILL_K, ['next_number' => 50, 'reason' => 'Restauración'], self::CASHIER)->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->setCreditNote(['next_number' => 50, 'reason' => 'Restauración'], self::CASHIER)->getStatusCode());
        self::assertSame(0, (int) $this->connection?->fetchOne('SELECT COUNT(*) FROM numbering_change'));
    }

    /** S2 + S4: the change is applied and audited; the next sale gets exactly that number. */
    public function testASaleAfterTheChangeGetsTheNextNumberExactly(): void
    {
        $response = $this->setTicket(self::TILL_K, ['next_number' => 124, 'reason' => '  Base de datos restaurada  ']);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(123, $this->json($response)['data']['last_number']);
        self::assertSame(124, $this->json($response)['data']['next_number']);
        self::assertSame('K' . $this->yy . '000124', $this->sale(self::TILL_K));
        self::assertSame('K' . $this->yy . '000125', $this->sale(self::TILL_K));
        self::assertSame('M' . $this->yy . '000001', $this->sale(self::TILL_M), 'the other till keeps its own series');

        $changes = $this->overview()['changes'];
        self::assertCount(1, $changes);
        self::assertSame('K' . $this->yy, $changes[0]['series']);
        self::assertSame(0, $changes[0]['previous_last']);
        self::assertSame(123, $changes[0]['new_last']);
        self::assertSame('Base de datos restaurada', $changes[0]['reason']);
        self::assertSame(self::USER, $changes[0]['user_id']);
        self::assertSame('Ana Admin', $changes[0]['user_name']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $changes[0]['created_at']);
    }

    /** S2: forward only — never at or below the last number issued. */
    public function testTheTicketSeriesCannotGoBackOrRepeat(): void
    {
        $this->setTicket(self::TILL_K, ['next_number' => 11, 'reason' => 'Restauración'])->getStatusCode();

        foreach ([10, 5, 1] as $next) {
            $response = $this->setTicket(self::TILL_K, ['next_number' => $next, 'reason' => 'Restauración']);
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
            self::assertSame('El próximo número debe ser mayor que el último emitido (10)', $this->json($response)['error']);
        }
        self::assertSame('K' . $this->yy . '000011', $this->sale(self::TILL_K));
        self::assertCount(1, $this->overview()['changes'], 'a refused change is not audited');
    }

    /** S2: out of range and missing reason are refused before anything changes. */
    public function testInvalidInputIsRefused(): void
    {
        $cases = [
            [['next_number' => 0, 'reason' => 'Restauración'], 'El próximo número debe estar entre 1 y 999999'],
            [['next_number' => 1000000, 'reason' => 'Restauración'], 'El próximo número debe estar entre 1 y 999999'],
            [['next_number' => 'abc', 'reason' => 'Restauración'], 'El próximo número debe estar entre 1 y 999999'],
            [['next_number' => 50, 'reason' => '   '], 'Indica el motivo del cambio'],
            [['next_number' => 50, 'reason' => 'abcd'], 'Indica el motivo del cambio'],
            [['next_number' => 50], 'Indica el motivo del cambio'],
            [['next_number' => 50, 'reason' => str_repeat('a', 256)], 'El motivo no puede tener más de 255 caracteres'],
        ];
        foreach ($cases as [$body, $message]) {
            $response = $this->setTicket(self::TILL_K, $body);
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), json_encode($body));
            self::assertSame($message, $this->json($response)['error']);
            $response = $this->setCreditNote($body);
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), json_encode($body));
            self::assertSame($message, $this->json($response)['error']);
        }
        self::assertSame(Response::HTTP_OK, $this->setTicket(self::TILL_K, ['next_number' => 999999, 'reason' => str_repeat('a', 255)])->getStatusCode());
        self::assertSame(0, (int) $this->connection?->fetchOne('SELECT COUNT(*) FROM numbering_change WHERE new_last <> 999998'));
    }

    public function testAnUnknownTillOrATillWithoutLetterIsRefused(): void
    {
        self::assertSame(Response::HTTP_NOT_FOUND, $this->setTicket(999999, ['next_number' => 5, 'reason' => 'Restauración'])->getStatusCode());

        $this->connection?->update('terminal', ['ticket_letter' => null], ['id' => self::TILL_M]);
        $response = $this->setTicket(self::TILL_M, ['next_number' => 5, 'reason' => 'Restauración']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('La caja no tiene letra de ticket: asígnala en Ajustes', $this->json($response)['error']);
    }

    /** S3 + S4: the credit-note series follows the same rules. */
    public function testTheCreditNoteSeriesMovesForwardOnly(): void
    {
        $this->creditNote();
        $response = $this->setCreditNote(['next_number' => 13, 'reason' => 'Instalación en otra tienda']);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('R' . $this->year . '-000013', $this->json($response)['data']['next_code']);
        self::assertSame('R' . $this->year . '-000013', $this->creditNote());

        $response = $this->setCreditNote(['next_number' => 13, 'reason' => 'Instalación en otra tienda']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('El próximo número debe ser mayor que el último emitido (13)', $this->json($response)['error']);

        $data = $this->overview();
        self::assertSame(['series' => 'R' . $this->year, 'last_number' => 13, 'next_number' => 14, 'next_code' => 'R' . $this->year . '-000014'], $data['credit_notes']);
        self::assertSame('R' . $this->year, $data['changes'][0]['series']);
        self::assertSame(1, $data['changes'][0]['previous_last']);
        self::assertSame(12, $data['changes'][0]['new_last']);
    }

    /** S4: the last 20 changes, newest first. */
    public function testTheOverviewListsTheLastTwentyChangesNewestFirst(): void
    {
        for ($next = 2; $next <= 23; ++$next) {
            self::assertSame(Response::HTTP_OK, $this->setTicket(self::TILL_K, ['next_number' => $next, 'reason' => 'Cambio ' . $next])->getStatusCode());
        }
        $changes = $this->overview()['changes'];
        self::assertCount(20, $changes);
        self::assertSame('Cambio 23', $changes[0]['reason']);
        self::assertSame('Cambio 4', $changes[19]['reason']);
    }

    /**
     * S2 invariant: the change locks the counter row. While a sale holds it the
     * change waits, and once the sale commits a change to the number that sale took
     * is refused: the check is made again under the lock, not on what the screen showed.
     */
    public function testAChangeCannotGoBelowANumberAConcurrentSaleTook(): void
    {
        \assert($this->connection !== null);
        $this->setTicket(self::TILL_K, ['next_number' => 10, 'reason' => 'Restauración']);

        $sale = TestDatabase::connection();
        \assert($sale !== null);
        try {
            $numberer = new TicketNumberer($sale);
            $now = new \DateTimeImmutable();
            $sale->beginTransaction();
            self::assertSame('K' . $this->yy . '000010', $numberer->next(self::TILL_K, $now));

            $this->connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
            try {
                (new NumberingSettings($this->connection))->setTicketNext(self::TILL_K, 10, 'Restauración', self::USER, $now);
                self::fail('The change must wait for the sale holding the counter');
            } catch (LockWaitTimeoutException) {
                // the counter row is locked by the sale
            }
            $sale->commit();
        } finally {
            if ($sale->isTransactionActive()) {
                $sale->rollBack();
            }
            $sale->close();
            $this->connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $response = $this->setTicket(self::TILL_K, ['next_number' => 10, 'reason' => 'Restauración']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('El próximo número debe ser mayor que el último emitido (10)', $this->json($response)['error']);
        self::assertSame('K' . $this->yy . '000011', $this->sale(self::TILL_K));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM numbering_change'));
    }
}
