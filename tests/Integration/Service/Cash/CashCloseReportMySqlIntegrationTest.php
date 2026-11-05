<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Cash;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\TerminalService;
use VenPosTPV\Service\Print\CashReport;
use VenPosTPV\Service\Print\CashReportPdf;
use VenPosTPV\Service\Print\CashReportService;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * The Z report of a close is read back from what the close stored (session row and
 * z_report), never recomputed; the closes of the shop are listed newest first.
 */
final class CashCloseReportMySqlIntegrationTest extends TestCase
{
    private const TERMINAL = 9301;
    private const OTHER_SHOP_TERMINAL = 9302;
    private const USER = 9301;
    private const OLD = 9310;
    private const OPEN = 9311;
    private const OTHER_SHOP = 9312;

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();
        $c = $this->connection;
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->insert('terminal', ['id' => self::TERMINAL, 'ps_shop_id' => 1, 'name' => 'Caja Z', 'store_name' => 'Tienda Z', 'legal_name' => 'Tienda Z SL', 'tax_id' => 'B11111111', 'active' => 1]);
        $c->insert('terminal', ['id' => self::OTHER_SHOP_TERMINAL, 'ps_shop_id' => 2, 'name' => 'Otra tienda', 'active' => 1]);
        $c->insert('user', ['id' => self::USER, 'role_id' => 1, 'name' => 'Ana Z', 'pin_hash' => 'x', 'active' => 1]);
        $c->insert('session', ['id' => self::OLD, 'terminal_id' => self::TERMINAL, 'user_id' => self::USER, 'opened_at' => '2026-11-01 09:00:00', 'closed_at' => '2026-11-01 20:00:00',
            'opening_cash' => 10, 'counted_cash' => 10, 'expected_cash' => 10, 'difference' => 0]);
        $c->insert('z_report', ['session_id' => self::OLD, 'generated_at' => '2026-11-01 20:00:00', 'totals' => '{"opening_cash":10,"expected_cash":10}', 'signature_hash' => str_repeat('0', 64)]);
        $c->insert('session', ['id' => self::OTHER_SHOP, 'terminal_id' => self::OTHER_SHOP_TERMINAL, 'user_id' => self::USER, 'opened_at' => '2026-11-02 09:00:00', 'closed_at' => '2026-11-02 20:00:00',
            'opening_cash' => 0, 'counted_cash' => 0, 'expected_cash' => 0, 'difference' => 0]);
        $c->insert('z_report', ['session_id' => self::OTHER_SHOP, 'generated_at' => '2026-11-02 20:00:00', 'totals' => '{}', 'signature_hash' => str_repeat('1', 64)]);
        $c->insert('session', ['id' => self::OPEN, 'terminal_id' => self::TERMINAL, 'user_id' => self::USER, 'opened_at' => '2026-11-05 09:00:00', 'opening_cash' => 50]);
        $c->insert('movement', ['session_id' => self::OPEN, 'type' => 'sale', 'amount' => 30, 'created_at' => '2026-11-05 10:00:00']);
        $c->insert('movement', ['session_id' => self::OPEN, 'type' => 'cash_in', 'amount' => 5, 'created_at' => '2026-11-05 11:00:00']);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $_SESSION = ['shop_id' => 1, 'cash_session_id' => self::OPEN];
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->clean();
            $this->connection->close();
        }
        $_SESSION = [];
    }

    private function clean(): void
    {
        $ids = [self::OLD, self::OPEN, self::OTHER_SHOP];
        $c = $this->connection;
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->executeStatement('DELETE FROM z_report WHERE session_id IN (?, ?, ?)', $ids);
        $c->executeStatement('DELETE FROM movement WHERE session_id IN (?, ?, ?)', $ids);
        $c->executeStatement('DELETE FROM session WHERE id IN (?, ?, ?)', $ids);
        $c->executeStatement('DELETE FROM terminal WHERE id IN (?, ?)', [self::TERMINAL, self::OTHER_SHOP_TERMINAL]);
        $c->executeStatement('DELETE FROM user WHERE id = ?', [self::USER]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function cash(): CashSessionService
    {
        return new CashSessionService($this->connection, $this->createMock(TerminalService::class), new RequestStack());
    }

    private function reports(CashSessionService $cash): CashReportService
    {
        return new CashReportService(
            $this->connection,
            $cash,
            $this->createMock(PrinterService::class),
            new CashReportPdf(new Environment(new FilesystemLoader(__DIR__ . '/../../../../templates'))),
            'Por defecto',
        );
    }

    public function testTheCloseAnswersAsBeforeAndItsZReportIsWhatTheCloseStored(): void
    {
        $cash = $this->cash();
        $x = $this->reports($cash)->currentX(self::USER);
        $this->assertSame(CashReport::X, $x->kind);
        $this->assertSame(85.0, (float) $x->totals['expected_cash']);
        $this->assertSame('Ana Z', $x->cashierName);

        $closed = $cash->closeSession(self::USER, 90.0);

        $this->assertSame(['session_id', 'closed_at', 'expected_cash', 'counted_cash', 'difference'], array_keys($closed));
        $this->assertSame(self::OPEN, $closed['session_id']);
        $this->assertSame(85.0, $closed['expected_cash']);
        $this->assertSame(90.0, $closed['counted_cash']);
        $this->assertSame(5.0, $closed['difference']);

        // A movement booked after the close must not change the stored report.
        $this->connection->insert('movement', ['session_id' => self::OPEN, 'type' => 'sale', 'amount' => 999, 'created_at' => '2026-11-05 22:00:00']);

        $z = $this->reports($cash)->closedZ(self::OPEN);
        $this->assertNotNull($z);
        $this->assertSame(CashReport::Z, $z->kind);
        $this->assertSame(self::TERMINAL, $z->terminalId);
        $this->assertSame('Caja Z', $z->terminalName);
        $this->assertSame('Tienda Z', $z->storeName);
        $this->assertSame('Tienda Z SL', $z->legalName);
        $this->assertSame('B11111111', $z->taxId);
        $this->assertSame('Ana Z', $z->cashierName);
        $this->assertSame($closed['closed_at'], $z->closedAt?->format('Y-m-d H:i:s'));
        $this->assertSame(30.0, (float) $z->totals['sales_cash']);
        $this->assertSame(85.0, (float) $z->totals['expected_cash']);
        $this->assertSame(90.0, $z->countedCash);
        $this->assertSame(5.0, $z->difference);
        $stored = $this->connection->fetchAssociative('SELECT totals, signature_hash FROM z_report WHERE session_id = ?', [self::OPEN]);
        $this->assertSame($stored['signature_hash'], $z->signatureHash);
        $this->assertSame(json_decode((string) $stored['totals'], true), $z->totals);
        $this->assertStringContainsString('Huella: ' . substr((string) $stored['signature_hash'], 0, 16), implode("\n", $z->lines()));
    }

    public function testTheClosesOfThisShopAreListedNewestFirstAndOthersAreNotFound(): void
    {
        $cash = $this->cash();
        $cash->closeSession(self::USER, 80.0);

        $list = $cash->listClosedSessions();
        $ids = array_map(static fn (array $row): int => $row['session_id'], $list);

        $this->assertSame([self::OPEN, self::OLD], \array_slice(array_values(array_intersect($ids, [self::OPEN, self::OLD, self::OTHER_SHOP])), 0, 3));
        $row = $list[array_search(self::OPEN, $ids, true)];
        $this->assertSame('Ana Z', $row['cashier']);
        $this->assertSame('Caja Z', $row['terminal_name']);
        $this->assertSame(80.0, $row['counted_cash']);
        $this->assertSame(85.0, $row['expected_cash']);
        $this->assertSame(-5.0, $row['difference']);
        $this->assertNotEmpty($row['closed_at']);

        $this->assertNull($cash->findClosedSession(self::OTHER_SHOP), 'another shop');
        $this->assertNotNull($cash->findClosedSession(self::OLD));
        $this->assertNull($this->reports($cash)->closedZ(self::OTHER_SHOP));
        $this->assertNull($this->reports($cash)->closedZ(999999));
    }

    public function testAnOpenSessionHasNoZReport(): void
    {
        $this->assertNull($this->cash()->findClosedSession(self::OPEN));
    }
}
