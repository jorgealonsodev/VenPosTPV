<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Device;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\TerminalService;
use VenPosTPV\Service\Device\DeviceContext;
use VenPosTPV\Service\Device\DeviceNotLinkedException;
use VenPosTPV\Service\Device\DeviceRegistry;
use VenPosTPV\Service\Device\TillHasDeviceException;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Tests\Support\SessionRequest;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * S10: each computer is linked once to a till; the till decides the cash session
 * and the ticket letter. Fixtures live in their own shop so the rest of the suite
 * (terminal 1, other letters) is never touched.
 */
final class DeviceRegistryMySqlIntegrationTest extends TestCase
{
    private const SHOP = 9301;
    private const OTHER_SHOP = 9302;
    private int $adminUser = 0;

    private ?Connection $connection = null;
    private DeviceRegistry $registry;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->registry = new DeviceRegistry($this->connection);
        $this->clean();
        $this->adminUser = $this->anyUser();
    }

    protected function tearDown(): void
    {
        // CashSessionService also caches the drawer in the native session.
        unset($_SESSION['terminal_id'], $_SESSION['cash_session_id'], $_SESSION['cash_opened_at'], $_SESSION['opening_cash']);
        if ($this->connection !== null) {
            $this->clean();
            $this->dropFixtureUser();
            $this->connection->close();
        }
    }

    public function testTheTokenIsNeverStoredRawOnlyItsSha256(): void
    {
        $till = $this->till(self::SHOP, 'Caja 1');

        $token = $this->registry->link($till, $this->adminUser, false, null);

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $token, 'at least 128 random bits, hex');
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM terminal_device WHERE terminal_id = ?', [$till]);
        $this->assertCount(1, $rows);
        $this->assertSame(hash('sha256', $token), $rows[0]['token_hash']);
        $this->assertSame($this->adminUser, (int) $rows[0]['linked_by_user_id']);
        $this->assertStringNotContainsString($token, json_encode($rows, JSON_THROW_ON_ERROR));
        $this->assertSame($till, $this->registry->terminalForToken($token));
        $this->assertNull($this->registry->terminalForToken(str_repeat('0', 64)));
        $this->assertNull($this->registry->terminalForToken('not-a-token'));
    }

    public function testATillHasOneDeviceAndLinkingASecondOneNeedsTheMove(): void
    {
        $till = $this->till(self::SHOP, 'Caja 1');
        $first = $this->registry->link($till, $this->adminUser, false, null);

        try {
            $this->registry->link($till, $this->adminUser, false, null);
            $this->fail('A till already on another device must not be taken silently');
        } catch (TillHasDeviceException) {
        }

        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM terminal_device WHERE terminal_id = ?', [$till]));
        $this->assertSame($till, $this->registry->terminalForToken($first));
    }

    public function testMovingTheTillUnlinksTheOldDevice(): void
    {
        $till = $this->till(self::SHOP, 'Caja 1');
        $old = $this->registry->link($till, $this->adminUser, false, null);

        $new = $this->registry->link($till, $this->adminUser, true, null);

        $this->assertNull($this->registry->terminalForToken($old), 'the old computer shows the link screen again');
        $this->assertSame($till, $this->registry->terminalForToken($new));
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM terminal_device WHERE terminal_id = ?', [$till]));
    }

    public function testRelinkingADeviceToAnotherTillReleasesItsPreviousTill(): void
    {
        $one = $this->till(self::SHOP, 'Caja 1');
        $two = $this->till(self::SHOP, 'Caja 2');
        $token = $this->registry->link($one, $this->adminUser, false, null);

        $newToken = $this->registry->link($two, $this->adminUser, false, $token);

        $this->assertSame($two, $this->registry->terminalForToken($newToken));
        $this->assertNull($this->registry->terminalForToken($token));
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM terminal_device WHERE terminal_id = ?', [$one]));
    }

    public function testTheFirstDeviceAdoptsTheOnlyTillAndNoOtherDoes(): void
    {
        $till = $this->till(self::SHOP, 'Caja única');

        $adopted = $this->registry->adoptOnlyTill(self::SHOP, $this->adminUser);

        $this->assertNotNull($adopted);
        $this->assertSame($till, $adopted['terminal_id']);
        $this->assertSame($till, $this->registry->terminalForToken($adopted['token']));
        $this->assertNull($this->registry->adoptOnlyTill(self::SHOP, $this->adminUser), 'a second computer must be linked by an admin');
    }

    public function testNoTillIsAdoptedWhenTheShopHasSeveral(): void
    {
        $this->till(self::OTHER_SHOP, 'Caja 1');
        $this->till(self::OTHER_SHOP, 'Caja 2');

        $this->assertNull($this->registry->adoptOnlyTill(self::OTHER_SHOP, $this->adminUser));
        $this->assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM terminal_device d JOIN terminal t ON t.id = d.terminal_id WHERE t.ps_shop_id = ?',
            [self::OTHER_SHOP]
        ));
    }

    public function testTwoLinkedDevicesGetTheirOwnCashSessionsAndLetters(): void
    {
        [$letterOne, $letterTwo] = $this->freeLetters();
        $one = $this->till(self::SHOP, 'Caja 1', $letterOne);
        $two = $this->till(self::SHOP, 'Caja 2', $letterTwo);

        $cashOne = $this->cashFor($one);
        $cashTwo = $this->cashFor($two);
        $openOne = $cashOne->openSession($this->adminUser, 50.0);
        $openTwo = $cashTwo->openSession($this->adminUser, 80.0);

        $this->assertSame($one, $openOne['terminal_id']);
        $this->assertSame($two, $openTwo['terminal_id']);
        $this->assertSame($openOne['session_id'], $cashOne->getCurrentSession($this->adminUser)['session_id']);
        $this->assertSame($openTwo['session_id'], $cashTwo->getCurrentSession($this->adminUser)['session_id']);
        $this->assertSame($two, $cashTwo->requireOpenSession($this->adminUser)['terminal_id']);

        $numberer = new TicketNumberer($this->connection);
        $now = new \DateTimeImmutable();
        $numberer->prepareYear($one, $now);
        $numberer->prepareYear($two, $now);
        $this->connection->beginTransaction();
        $refOne = $numberer->next($one, $now);
        $refTwo = $numberer->next($two, $now);
        $this->connection->commit();
        $this->assertSame($letterOne, $refOne[0]);
        $this->assertSame($letterTwo, $refTwo[0]);
    }

    public function testACachedCashSessionOfAnotherTillIsIgnored(): void
    {
        $one = $this->till(self::SHOP, 'Caja 1');
        $two = $this->till(self::SHOP, 'Caja 2');
        $open = $this->cashFor($one)->openSession($this->adminUser, 10.0);

        // Same browser session (e.g. the till was moved), now on till two.
        $cash = $this->cashFor($two, ['cash_session_id' => $open['session_id']]);

        $this->assertNull($cash->getCurrentSession($this->adminUser), 'never another till\'s drawer');
    }

    public function testAnUnlinkedDeviceCannotOpenOrReadACashSession(): void
    {
        $this->till(self::SHOP, 'Caja 1');
        $cash = $this->cashFor(null);

        try {
            $cash->openSession($this->adminUser, 10.0);
            $this->fail('An unlinked browser must not open terminal 1');
        } catch (DeviceNotLinkedException) {
        }
        $this->expectException(DeviceNotLinkedException::class);
        $cash->getCurrentSession($this->adminUser);
    }

    public function testTillsAreListedWithTheirDeviceAndWhichOneIsThisComputer(): void
    {
        $one = $this->till(self::SHOP, 'Caja 1');
        $two = $this->till(self::SHOP, 'Caja 2');
        $token = $this->registry->link($one, $this->adminUser, false, null);

        $tills = $this->registry->listTills(self::SHOP, $token);

        $this->assertSame([$one, $two], array_column($tills, 'id'));
        $this->assertTrue($tills[0]['this_device']);
        $this->assertNotNull($tills[0]['device']);
        $this->assertNotNull($tills[0]['device']['last_seen_at']);
        $this->assertFalse($tills[1]['this_device']);
        $this->assertNull($tills[1]['device']);
        $this->assertArrayNotHasKey('token_hash', $tills[0]['device']);
    }

    /** @param array<string, mixed> $session */
    private function cashFor(?int $terminalId, array $session = []): CashSessionService
    {
        $request = SessionRequest::create([], ['user_id' => $this->adminUser, 'shop_id' => self::SHOP] + $session);
        $request->attributes->set(DeviceContext::ATTRIBUTE, $terminalId);
        $stack = new RequestStack();
        $stack->push($request);

        return new CashSessionService($this->connection, new TerminalService($this->connection), $stack);
    }

    private function till(int $shop, string $name, ?string $letter = null): int
    {
        $this->connection->insert('terminal', ['ps_shop_id' => $shop, 'name' => $name, 'ticket_letter' => $letter, 'active' => 1]);

        return (int) $this->connection->lastInsertId();
    }

    /** @return array{0: string, 1: string} */
    private function freeLetters(): array
    {
        $used = $this->connection->fetchFirstColumn('SELECT ticket_letter FROM terminal WHERE ticket_letter IS NOT NULL');
        $free = array_values(array_diff(array_reverse(range('A', 'Z')), $used));
        if (\count($free) < 2) {
            $this->markTestSkipped('No two free till letters in the test database');
        }

        return [$free[0], $free[1]];
    }

    /** session.user_id references user: use one that exists, or a fixture one removed afterwards. */
    private function anyUser(): int
    {
        $id = $this->connection->fetchOne("SELECT id FROM user WHERE name <> 'Device test admin' ORDER BY id LIMIT 1");
        if ($id !== false) {
            return (int) $id;
        }
        $this->connection->executeStatement("INSERT IGNORE INTO role (name, permissions) VALUES ('device_test_role', '[]')");
        $role = (int) $this->connection->fetchOne("SELECT id FROM role WHERE name = 'device_test_role'");
        $this->connection->insert('user', ['role_id' => $role, 'name' => 'Device test admin', 'pin_hash' => 'x']);

        return (int) $this->connection->lastInsertId();
    }

    private function clean(): void
    {
        $ids = $this->connection->fetchFirstColumn('SELECT id FROM terminal WHERE ps_shop_id IN (?, ?)', [self::SHOP, self::OTHER_SHOP]);
        if ($ids === []) {
            return;
        }
        $in = implode(',', array_map('intval', $ids));
        $letters = $this->connection->fetchFirstColumn("SELECT ticket_letter FROM terminal WHERE id IN ($in) AND ticket_letter IS NOT NULL");
        foreach ($letters as $letter) {
            $this->connection->executeStatement('DELETE FROM ticket_sequence WHERE letter = ?', [$letter]);
        }
        $this->connection->executeStatement("DELETE FROM terminal_device WHERE terminal_id IN ($in)");
        $this->connection->executeStatement("DELETE FROM session WHERE terminal_id IN ($in)");
        $this->connection->executeStatement("DELETE FROM terminal WHERE id IN ($in)");
    }

    private function dropFixtureUser(): void
    {
        $this->connection->executeStatement("DELETE FROM user WHERE name = 'Device test admin'");
        $this->connection->executeStatement("DELETE FROM role WHERE name = 'device_test_role'");
    }
}
