<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Numbering;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\NumberingController;
use VenPosTPV\Service\Invoice\InvoiceNumberer;
use VenPosTPV\Tests\Support\SessionRequest;
use VenPosTPV\Tests\Support\TestDatabase;

/** docs/prd/factura-completa.md F4: «Próxima factura», forward only, with a reason, audited. */
final class InvoiceNumberingMySqlIntegrationTest extends TestCase
{
    private const ADMIN = ['user_id' => 1, 'role' => 'admin', 'shop_id' => 1];

    private ?Connection $connection = null;
    private int $year;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->year = (int) (new \DateTimeImmutable())->format('Y');
        $this->reset();
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
        $this->connection->executeStatement('DELETE FROM invoice_sequence');
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function put(array $body, array $session = self::ADMIN): Response
    {
        \assert($this->connection !== null);

        return (new NumberingController($this->connection))->setInvoice(SessionRequest::json($body, $session));
    }

    public function testTheInvoiceSeriesMovesForwardWithAReasonAndIsAudited(): void
    {
        \assert($this->connection !== null);
        $yy = sprintf('%02d', $this->year % 100);
        $overview = $this->json((new NumberingController($this->connection))->overview(SessionRequest::create([], self::ADMIN)))['data'];
        self::assertSame(['series' => 'F' . $yy, 'last_number' => 0, 'next_number' => 1, 'next_code' => 'F' . $yy . '000001'], $overview['invoices']);

        $response = $this->put(['next_number' => 120, 'reason' => 'Restaurado tras reinstalar']);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(['series' => 'F' . $yy, 'last_number' => 119, 'next_number' => 120, 'next_code' => 'F' . $yy . '000120'], $this->json($response)['data']);
        self::assertSame(119, (int) $this->connection->fetchOne("SELECT last_number FROM invoice_sequence WHERE series = 'F' AND year = ?", [$this->year]));
        $change = $this->connection->fetchAssociative('SELECT series, previous_last, new_last, reason, user_id FROM numbering_change');
        self::assertSame(['series' => 'F' . $yy, 'previous_last' => 0, 'new_last' => 119, 'reason' => 'Restaurado tras reinstalar', 'user_id' => 1], array_map(static fn ($v) => is_numeric($v) && !str_starts_with((string) $v, 'F') ? (int) $v : $v, $change ?: []));

        $numberer = new InvoiceNumberer($this->connection);
        self::assertSame('F' . $yy . '000120', $this->connection->transactional(fn () => $numberer->next(new \DateTimeImmutable())['number']));
    }

    public function testItNeverGoesBackNorChangesWithoutAReasonOrAnAdmin(): void
    {
        \assert($this->connection !== null);
        $this->put(['next_number' => 50, 'reason' => 'Continuar la serie']);

        $back = $this->put(['next_number' => 49, 'reason' => 'Volver atrás']);
        self::assertSame([422, 'El próximo número debe ser mayor que el último emitido (49)'], [$back->getStatusCode(), $this->json($back)['error']]);
        $noReason = $this->put(['next_number' => 60, 'reason' => '']);
        self::assertSame([422, 'Indica el motivo del cambio'], [$noReason->getStatusCode(), $this->json($noReason)['error']]);
        $cashier = $this->put(['next_number' => 60, 'reason' => 'Continuar la serie'], ['user_id' => 1, 'role' => 'cashier', 'shop_id' => 1]);
        self::assertSame(403, $cashier->getStatusCode());

        self::assertSame(49, (int) $this->connection->fetchOne("SELECT last_number FROM invoice_sequence WHERE series = 'F' AND year = ?", [$this->year]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM numbering_change'));
    }
}
