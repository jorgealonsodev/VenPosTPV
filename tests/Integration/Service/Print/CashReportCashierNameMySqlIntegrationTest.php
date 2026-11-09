<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Print;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\TerminalService;
use VenPosTPV\Service\Print\CashierName;
use VenPosTPV\Service\Print\CashReportPdf;
use VenPosTPV\Service\Print\CashReportService;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * The X and Z reports name the cashier like the ticket: «Nombre y apellidos»
 * (user.full_name) when set, else the user name.
 */
final class CashReportCashierNameMySqlIntegrationTest extends TestCase
{
    private const TERMINAL = 9401;
    private const WITH_FULL_NAME = 9401;
    private const WITHOUT_FULL_NAME = 9402;
    private const OPEN = 9410;
    private const CLOSED = 9411;

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
        $c->insert('terminal', ['id' => self::TERMINAL, 'ps_shop_id' => 1, 'name' => 'Caja N', 'active' => 1]);
        $c->insert('user', ['id' => self::WITH_FULL_NAME, 'role_id' => 1, 'name' => 'ana', 'full_name' => 'Ana García López', 'pin_hash' => 'x', 'active' => 1]);
        $c->insert('user', ['id' => self::WITHOUT_FULL_NAME, 'role_id' => 1, 'name' => 'luis', 'pin_hash' => 'x', 'active' => 1]);
        $c->insert('session', ['id' => self::CLOSED, 'terminal_id' => self::TERMINAL, 'user_id' => self::WITHOUT_FULL_NAME, 'opened_at' => '2026-11-01 09:00:00', 'closed_at' => '2026-11-01 20:00:00',
            'opening_cash' => 10, 'counted_cash' => 10, 'expected_cash' => 10, 'difference' => 0]);
        $c->insert('z_report', ['session_id' => self::CLOSED, 'generated_at' => '2026-11-01 20:00:00', 'totals' => '{}', 'signature_hash' => str_repeat('0', 64)]);
        $c->insert('session', ['id' => self::OPEN, 'terminal_id' => self::TERMINAL, 'user_id' => self::WITH_FULL_NAME, 'opened_at' => '2026-11-09 09:00:00', 'opening_cash' => 50]);
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
        $c = $this->connection;
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->executeStatement('DELETE FROM z_report WHERE session_id IN (?, ?)', [self::OPEN, self::CLOSED]);
        $c->executeStatement('DELETE FROM movement WHERE session_id IN (?, ?)', [self::OPEN, self::CLOSED]);
        $c->executeStatement('DELETE FROM session WHERE id IN (?, ?)', [self::OPEN, self::CLOSED]);
        $c->executeStatement('DELETE FROM terminal WHERE id = ?', [self::TERMINAL]);
        $c->executeStatement('DELETE FROM user WHERE id IN (?, ?)', [self::WITH_FULL_NAME, self::WITHOUT_FULL_NAME]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function reports(): CashReportService
    {
        return new CashReportService(
            $this->connection,
            new CashSessionService($this->connection, $this->createMock(TerminalService::class), new RequestStack()),
            $this->createMock(PrinterService::class),
            new CashReportPdf(new Environment(new FilesystemLoader(__DIR__ . '/../../../../templates'))),
            'Por defecto',
        );
    }

    public function testTheXReportNamesTheCashierByTheFullName(): void
    {
        $x = $this->reports()->currentX(self::WITH_FULL_NAME);

        self::assertSame('Ana García López', $x->cashierName);
        self::assertStringContainsString('Cajero: Ana García López', implode("\n", $x->lines()));
    }

    public function testTheZReportFallsBackToTheUserName(): void
    {
        $z = $this->reports()->closedZ(self::CLOSED);

        self::assertNotNull($z);
        self::assertSame('luis', $z->cashierName);
    }

    public function testTheHelperReadsTheFullNameThenTheUserNameThenTheFallback(): void
    {
        \assert($this->connection !== null);

        self::assertSame('Ana García López', CashierName::of($this->connection, self::WITH_FULL_NAME));
        self::assertSame('luis', CashierName::of($this->connection, self::WITHOUT_FULL_NAME));
        self::assertSame('Cajero', CashierName::of($this->connection, 999999));
        self::assertSame('', CashierName::of($this->connection, 999999, ''));
    }
}
