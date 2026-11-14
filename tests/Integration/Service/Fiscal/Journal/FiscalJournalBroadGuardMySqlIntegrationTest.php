<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use VenPosTPV\EventListener\FiscalJournalGuardListener;
use VenPosTPV\Kernel;
use VenPosTPV\Service\Device\DeviceCookie;
use VenPosTPV\Service\Device\DeviceRegistry;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalFlushResult;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuardCache;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplay;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * fiscal-journal S5, A3 through the real kernel and its routes, after a real dump and
 * restore: while the database is behind the fiscal journal every state-changing API
 * route the router knows is refused with 423 and leaves the database as it was, or is
 * on FiscalJournalGuardListener::ALLOWED_WHILE_BLOCKED. The routes are read from the
 * router, so a route added later is covered without being listed here. And no false
 * block: an up-to-date journal, an install that never had a journal, an unreadable
 * journal folder and a shop that is down all let the till register a cash movement.
 */
final class FiscalJournalBroadGuardMySqlIntegrationTest extends TestCase
{
    private const RESTORE_DB = 'venposjournal_broad_guard';
    private const HOST = 'https://tpv.tutienda.com';

    private ?Connection $connection = null;
    private ?Connection $restored = null;
    private JournalPathFixtures $fixtures;
    private JournalHarness $journal;
    private string $dump = '';
    private string $cacheDir = '';
    private string $token = '';
    private bool $userAdded = false;
    private ?Kernel $kernel = null;
    /** @var array<string, string|null> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        if (!JournalRestore::available()) {
            $this->markTestSkipped('MySQL test container unavailable for the restore');
        }
        $this->fixtures = new JournalPathFixtures($this->connection, fn (string $class) => $this->createMock($class));
        $this->fixtures->reset();
        $this->fixtures->install();
        if ($this->connection->fetchOne('SELECT 1 FROM user WHERE id = 1') === false) {
            $this->connection->insert('user', ['id' => 1, 'role_id' => (int) $this->connection->fetchOne('SELECT MIN(id) FROM role'), 'name' => 'Guard admin', 'pin_hash' => 'x', 'active' => 1]);
            $this->userAdded = true;
        }
        // This computer's till, seen just now (the device lookup writes last_seen_at
        // only every few minutes), so the till APIs reach the guard.
        $this->token = DeviceRegistry::generateToken();
        $this->connection->insert('terminal_device', ['token_hash' => DeviceRegistry::hashToken($this->token), 'terminal_id' => JournalPathFixtures::TERMINAL, 'linked_at' => '2026-11-14 09:00:00', 'last_seen_at' => date('Y-m-d H:i:s')]);
        $this->journal = new JournalHarness($this->connection);
        $this->cacheDir = sys_get_temp_dir() . '/venpos-broad-guard-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        foreach ($this->savedEnv as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
        $this->savedEnv = [];
        $this->restored?->close();
        $this->restored = null;
        if ($this->connection !== null) {
            JournalRestore::drop(self::RESTORE_DB);
            $this->fixtures->reset();
            if ($this->userAdded) {
                $this->connection->executeStatement('DELETE FROM user WHERE id = 1');
            }
            $this->connection->close();
            @chmod($this->journal->root . '/backups/fiscal-journal', 0700);
            $this->journal->remove();
        }
        if ($this->dump !== '' && is_file($this->dump)) {
            unlink($this->dump);
        }
        (new Filesystem())->remove($this->cacheDir);
        $this->connection = null;
    }

    private function sell(): void
    {
        // The services read the shop and till from $_SESSION, which a kernel request clears.
        $_SESSION = ['shop_id' => 1, 'cash_session_id' => JournalPathFixtures::SESSION, 'user_id' => 1, 'terminal_id' => JournalPathFixtures::TERMINAL];
        $total = JournalPathFixtures::PRICE;
        $this->fixtures->checkout($this->fixtures->recorder($this->journal->intents))
            ->createOrder(1, $this->fixtures->cart() + ['cash_amount' => $total], 'cash', $total);
    }

    /** The real kernel on database $database, the journal folders of the harness and a shop that is down. */
    private function kernel(string $database): Kernel
    {
        $settings = TestDatabase::settings();
        $env = [
            'APP_SECRET' => 'test_secret_for_phpunit_32_chars_min',
            'APP_URL' => self::HOST,
            'APP_ADMIN_USER' => 'admin',
            'APP_ADMIN_PASSWORD' => 'not-used-by-these-tests',
            'TPV_BASE_PATH' => '',
            'TPV_URL' => self::HOST,
            'TPV_STORE_NAME' => 'Test',
            'TPV_STORE_ADDRESS' => '',
            'TPV_DB_HOST' => $settings['host'],
            'TPV_DB_PORT' => (string) $settings['port'],
            'TPV_DB_NAME' => $database,
            'TPV_DB_USER' => $settings['user'],
            'TPV_DB_PASSWORD' => $settings['password'],
            // A shop that is down: nothing answers on port 1.
            'PS_SHOP_URL' => 'http://127.0.0.1:1',
            'PS_API_KEY' => 'none',
            'PS_DEFAULT_CURRENCY' => '1',
            'PS_DEFAULT_SHOP' => '1',
            'SSO_SHARED_SECRET' => 'none',
            'MESSENGER_TRANSPORT_DSN' => 'sync://',
        ];
        foreach ($env as $name => $value) {
            if (!\array_key_exists($name, $this->savedEnv)) {
                $this->savedEnv[$name] = isset($_ENV[$name]) ? (string) $_ENV[$name] : null;
            }
            $_ENV[$name] = $value;
        }
        $this->kernel?->shutdown();

        return $this->kernel = new class ('test', false, $this->cacheDir . '/' . $database, $this->journal->root) extends Kernel {
            public function __construct(string $environment, bool $debug, private readonly string $dir, private readonly string $root)
            {
                parent::__construct($environment, $debug);
            }

            public function getCacheDir(): string
            {
                return $this->dir;
            }

            protected function build(ContainerBuilder $container): void
            {
                $container->addCompilerPass(new class ($this->root) implements CompilerPassInterface {
                    public function __construct(private readonly string $root)
                    {
                    }

                    public function process(ContainerBuilder $container): void
                    {
                        $container->getDefinition(FiscalJournalLocations::class)
                            ->setArgument('$primary', $this->root . '/backups/fiscal-journal')
                            ->setArgument('$secondary', $this->root . '/var/fiscal-journal');
                        $container->getDefinition(FiscalJournalGuardCache::class)
                            ->setArgument('$file', $this->root . '/fiscal-journal-guard.json');
                        $container->getDefinition(FiscalReplay::class)
                            ->setArgument('$lockPath', $this->root . '/fiscal-replay.lock');
                    }
                });
            }
        };
    }

    private function request(Kernel $kernel, string $method, string $path, array $body = []): Response
    {
        $request = Request::create(self::HOST . $path, $method, [], [DeviceCookie::NAME => $this->token], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
        $session = new Session(new MockArraySessionStorage());
        $session->set('user_id', 1);
        $session->set('role', 'admin');
        $session->set('shop_id', 1);
        $session->set('terminal_id', JournalPathFixtures::TERMINAL);
        $session->set('cash_session_id', JournalPathFixtures::SESSION);
        $request->setSession($session);

        return $kernel->handle($request);
    }

    /** @return array<string, string> table => checksum, every table of the database */
    private static function fingerprint(Connection $connection): array
    {
        $tables = $connection->fetchFirstColumn("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
        $sums = [];
        foreach ($tables as $table) {
            $row = $connection->fetchAssociative(sprintf('CHECKSUM TABLE `%s` EXTENDED', $table));
            $sums[$table] = (string) ($row['Checksum'] ?? '');
        }
        $sums['@auto_increment'] = json_encode($connection->fetchAllKeyValue("SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"));

        return $sums;
    }

    public function testAfterARestoreEveryStateChangingRouteIsRefusedOrAllowedAndNothingChanges(): void
    {
        $this->journal->writer->createJournal();
        $this->sell();
        $this->assertSame(FiscalJournalFlushResult::OK, $this->journal->writer->flush()->status);
        $this->dump = JournalRestore::dump($this->connection);
        $this->sell();
        $this->sell();
        $this->assertSame(FiscalJournalFlushResult::OK, $this->journal->writer->flush()->status);
        $this->restored = JournalRestore::restore($this->dump, self::RESTORE_DB);

        $kernel = $this->kernel(self::RESTORE_DB);
        $kernel->boot();
        /** @var RouterInterface $router */
        $router = $kernel->getContainer()->get('router');
        $before = self::fingerprint($this->restored);

        $refused = $allowed = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/api/')) {
                continue;
            }
            $methods = $route->getMethods() !== [] ? $route->getMethods() : ['POST', 'PUT', 'PATCH', 'DELETE'];
            $controller = (string) $route->getDefault('_controller');
            foreach (array_diff($methods, ['GET', 'HEAD', 'OPTIONS']) as $method) {
                if (\in_array($controller, FiscalJournalGuardListener::ALLOWED_WHILE_BLOCKED, true)) {
                    $allowed[$controller] = true;
                    continue;
                }
                $path = (string) preg_replace('/\{[^}]+\}/', '1', $route->getPath());
                $response = $this->request($kernel, $method, $path, ['type' => 'cash_out', 'amount' => 5, 'concept' => 'Cambio', 'opening_cash' => 100, 'counted_cash' => 100]);
                $this->assertSame(423, $response->getStatusCode(), $method . ' ' . $path . ' (' . $name . '): ' . $response->getContent());
                $this->assertSame('fiscal_journal_behind', json_decode((string) $response->getContent(), true)['code'] ?? null, $method . ' ' . $path);
                $refused[] = $method . ' ' . $path;
            }
        }

        $this->assertGreaterThan(50, \count($refused), 'the router gave the routes');
        $this->assertContains('POST /api/cash/movement', $refused);
        $this->assertContains('POST /api/cash/open', $refused);
        $this->assertContains('POST /api/checkout', $refused);
        $this->assertSame(FiscalJournalGuardListener::ALLOWED_WHILE_BLOCKED, array_values(array_intersect(FiscalJournalGuardListener::ALLOWED_WHILE_BLOCKED, array_keys($allowed))), 'every allowed action is a real route');
        $this->assertSame($before, self::fingerprint($this->restored), 'no refused request changed the database');

        // The way out stays open: the recovery's dry run answers.
        $dryRun = $this->request($kernel, 'POST', '/api/fiscal/journal/replay', ['apply' => false]);
        $this->assertSame(200, $dryRun->getStatusCode(), (string) $dryRun->getContent());
        $this->assertSame('pending', json_decode((string) $dryRun->getContent(), true)['data']['status'] ?? null);
        $this->assertSame(423, $this->request($kernel, 'POST', '/api/cash/movement', ['type' => 'cash_out', 'amount' => 5, 'concept' => 'Cambio'])->getStatusCode());

        // Recovered: the next request is free (a blocking answer is never kept).
        $apply = $this->request($kernel, 'POST', '/api/fiscal/journal/replay', ['apply' => true]);
        $this->assertSame('done', json_decode((string) $apply->getContent(), true)['data']['status'] ?? null, (string) $apply->getContent());
        $movements = (int) $this->restored->fetchOne('SELECT COUNT(*) FROM movement');
        $response = $this->request($kernel, 'POST', '/api/cash/movement', ['type' => 'cash_out', 'amount' => 5, 'concept' => 'Cambio']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame($movements + 1, (int) $this->restored->fetchOne('SELECT COUNT(*) FROM movement'));
    }

    /**
     * No false block: each case registers a cash movement through the kernel, with
     * the kept answer dropped first so the guard checks in full every time.
     */
    public function testNoFalseBlockOnANormalTill(): void
    {
        $database = (string) $this->connection->getDatabase();
        $kernel = $this->kernel($database);
        $cacheFile = $this->journal->root . '/fiscal-journal-guard.json';
        $move = function (string $case) use ($kernel, $cacheFile): void {
            @unlink($cacheFile);
            $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM movement WHERE session_id = ?', [JournalPathFixtures::SESSION]);
            $response = $this->request($kernel, 'POST', '/api/cash/movement', ['type' => 'cash_in', 'amount' => 3, 'concept' => 'Cambio ' . $case]);
            $this->assertSame(200, $response->getStatusCode(), $case . ': ' . $response->getContent());
            $this->assertSame($count + 1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM movement WHERE session_id = ?', [JournalPathFixtures::SESSION]), $case);
        };

        // An install that never had a journal (no folders, no identity).
        $move('never journaled');

        // An up-to-date journal, with the shop down (nothing answers its URL).
        $this->journal->writer->createJournal();
        $this->sell();
        $this->sell();
        $this->assertSame(FiscalJournalFlushResult::OK, $this->journal->writer->flush()->status);
        $move('up to date');

        // A journal behind the database (normal lag): a sale not journaled yet.
        $this->sell();
        $move('journal lagging');

        // A journal folder that cannot be read.
        chmod($this->journal->root . '/backups/fiscal-journal', 0000);
        clearstatcache();
        $move('unreadable folder');
        chmod($this->journal->root . '/backups/fiscal-journal', 0700);
    }
}
