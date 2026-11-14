<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\KernelEvents;
use VenPosTPV\Controller\Api\CheckoutController;
use VenPosTPV\EventListener\FiscalJournalGuardListener;
use VenPosTPV\Service\Backup\SqlDumper;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * fiscal-journal S5 on MySQL: after a real dump/restore of a backup taken before
 * the last tickets, the next sale is refused with HTTP 423 before any number is
 * taken and before PrestaShop is called; in normal operation, with tills selling
 * and flushing at once, the guard never refuses (500 sales).
 */
final class FiscalJournalGuardMySqlIntegrationTest extends TestCase
{
    private const RESTORE_DB = 'venposjournal_guard_restore';
    private const WORKERS = 4;
    private const PER_WORKER = 125;

    private ?Connection $connection = null;
    private ?Connection $restored = null;
    private JournalPathFixtures $fixtures;
    private JournalHarness $journal;
    private string $dump = '';

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->fixtures = new JournalPathFixtures($this->connection, fn (string $class) => $this->createMock($class));
        $this->fixtures->reset();
        $this->fixtures->install();
        $this->journal = new JournalHarness($this->connection);
        $this->journal->writer->createJournal();
    }

    protected function tearDown(): void
    {
        $this->restored?->close();
        $this->restored = null;
        if ($this->connection !== null) {
            if (self::containerAvailable()) {
                self::root(sprintf('DROP DATABASE IF EXISTS %s', self::RESTORE_DB));
            }
            $this->fixtures->reset();
            $this->connection->close();
            $this->journal->remove();
        }
        if ($this->dump !== '' && is_file($this->dump)) {
            unlink($this->dump);
        }
        $this->connection = null;
    }

    private static function container(): string
    {
        return getenv('TPV_TEST_MYSQL_CONTAINER') ?: 'venpos-test-mysql';
    }

    private static function containerAvailable(): bool
    {
        exec(sprintf('docker exec %s mysql --version 2>/dev/null', escapeshellarg(self::container())), $out, $code);

        return $code === 0;
    }

    /** @return array{0: int, 1: string} exit code and output */
    private static function root(string $sql): array
    {
        exec(sprintf(
            'docker exec %s mysql -uroot -p%s -e %s 2>&1',
            escapeshellarg(self::container()),
            escapeshellarg(getenv('TPV_TEST_MYSQL_ROOT_PASSWORD') ?: 'testroot'),
            escapeshellarg($sql)
        ), $out, $code);

        return [$code, implode("\n", $out)];
    }

    private function issue(): string
    {
        return JournalIssue::ticket($this->connection, $this->journal->intents, JournalPathFixtures::TERMINAL, JournalPathFixtures::SESSION);
    }

    /** The database as it is now, dumped the way the backup does (SqlDumper). */
    private function dumpNow(): void
    {
        $this->dump = sys_get_temp_dir() . '/venpos-journal-guard-' . bin2hex(random_bytes(4)) . '.sql';
        $handle = fopen($this->dump, 'wb');
        $this->assertNotFalse($handle);
        (new SqlDumper($this->connection))->dump(static function (string $piece) use ($handle): void {
            fwrite($handle, $piece);
        });
        fclose($handle);
    }

    /** Restores the dump with the real `mysql` client into a fresh database. */
    private function restoreDump(): Connection
    {
        $settings = TestDatabase::settings();
        [$code, $output] = self::root(sprintf(
            "DROP DATABASE IF EXISTS %1\$s; CREATE DATABASE %1\$s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON %1\$s.* TO '%2\$s'@'%%'",
            self::RESTORE_DB,
            $settings['user']
        ));
        $this->assertSame(0, $code, $output);
        exec(sprintf(
            'docker exec -i %s mysql -u%s -p%s %s < %s 2>&1',
            escapeshellarg(self::container()),
            escapeshellarg($settings['user']),
            escapeshellarg($settings['password']),
            self::RESTORE_DB,
            escapeshellarg($this->dump)
        ), $out, $code);
        $this->assertSame(0, $code, 'mysql import failed: ' . implode("\n", $out));

        return $this->restored = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => $settings['host'],
            'port' => $settings['port'],
            'user' => $settings['user'],
            'password' => $settings['password'],
            'dbname' => self::RESTORE_DB,
            'charset' => 'utf8mb4',
        ]);
    }

    /** POST /api/checkout through the kernel with the guard listener, against $connection. */
    private function checkout(Connection $connection, PsOrderClient $client, ?FiscalJournalGuard $guard = null): Response
    {
        // One guard for the listener and the recorder, as the container shares it.
        $guard ??= new FiscalJournalGuard($connection, $this->journal->locations);
        $fixtures = new JournalPathFixtures($connection, fn (string $class) => $this->createMock($class));
        $fixtures->guard = $guard;
        $controller = new CheckoutController($fixtures->checkout($fixtures->recorder(), $client), $fixtures->cash());
        $listener = new FiscalJournalGuardListener($guard);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(KernelEvents::CONTROLLER, $listener->onController(...));
        $dispatcher->addListener(KernelEvents::EXCEPTION, $listener->onException(...));
        $dispatcher->addListener(KernelEvents::RESPONSE, $listener->onResponse(...));
        $resolver = new class($controller) implements ControllerResolverInterface {
            public function __construct(private readonly CheckoutController $controller)
            {
            }

            public function getController(Request $request): callable|false
            {
                return $this->controller->checkout(...);
            }
        };
        $arguments = new class implements ArgumentResolverInterface {
            public function getArguments(Request $request, callable $controller, ?\ReflectionFunctionAbstract $reflector = null): array
            {
                return [$request];
            }
        };
        $total = JournalPathFixtures::PRICE;
        $request = Request::create('/api/checkout', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'payment_method' => 'cash',
            'amount_tendered' => $total,
            'cart_data' => $fixtures->cart() + ['cash_amount' => $total],
            'request_id' => 'guard-' . bin2hex(random_bytes(6)),
        ], \JSON_THROW_ON_ERROR));
        $request->attributes->set('_controller', CheckoutController::class . '::checkout');
        $session = new Session(new MockArraySessionStorage());
        $session->set('user_id', 1);
        $request->setSession($session);

        return (new HttpKernel($dispatcher, $resolver, null, $arguments))->handle($request);
    }

    public function testAfterARestoreOfAnOlderDumpTheNextSaleIsRefusedBeforeAnyNumberIsTaken(): void
    {
        if (!self::containerAvailable()) {
            $this->markTestSkipped('MySQL test container unavailable for the restore');
        }
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method($this->anything());

        foreach (range(1, 3) as $ignored) {
            $this->issue();
        }
        $sold = $this->checkout($this->connection, $client);
        $this->assertSame(200, $sold->getStatusCode(), 'normal operation: the till sells through the guard. ' . $sold->getContent());
        $this->journal->writer->flush();
        $this->dumpNow();
        // Sold after the backup, journaled.
        $lost = [$this->issue(), $this->issue()];
        $this->journal->writer->flush();
        $this->assertSame(6, \count($this->journal->lines()));
        $this->assertSame(FiscalJournalGuard::OK, (new FiscalJournalGuard($this->connection, $this->journal->locations))->check()['state'], 'the live database is not behind');

        $restored = $this->restoreDump();
        $records = (int) $restored->fetchOne('SELECT COUNT(*) FROM fiscal_record');
        $counter = $restored->fetchAssociative('SELECT year, last_number FROM ticket_sequence WHERE letter = ?', [JournalPathFixtures::LETTER]);
        $orders = (int) $restored->fetchOne('SELECT COUNT(*) FROM order_meta');
        $this->assertSame(4, $records);

        $response = $this->checkout($restored, $client);

        $this->assertSame(423, $response->getStatusCode());
        $this->assertSame([
            'error' => 'Caja bloqueada: la base de datos se ha restaurado desde una copia anterior a los últimos documentos. Avisa al administrador (Ajustes → VERI*FACTU → Diario fiscal).',
            'code' => 'fiscal_journal_behind',
            'retry' => false,
        ], json_decode((string) $response->getContent(), true));
        $this->assertSame($records, (int) $restored->fetchOne('SELECT COUNT(*) FROM fiscal_record'), 'no fiscal record');
        $this->assertSame($counter, $restored->fetchAssociative('SELECT year, last_number FROM ticket_sequence WHERE letter = ?', [JournalPathFixtures::LETTER]), 'no number taken');
        $this->assertSame($orders, (int) $restored->fetchOne('SELECT COUNT(*) FROM order_meta'), 'no sale');
        $this->assertSame(6, \count($this->journal->lines()), 'the journal is untouched');

        $report = (new FiscalJournalGuard($restored, $this->journal->locations))->report();
        $this->assertSame(['state' => FiscalJournalGuard::BEHIND_JOURNAL, 'missing' => ['number' => $lost[0], 'invoice_type' => 'F2', 'after' => 1]], $report);
    }

    /**
     * The database falls behind between the early check and the issue: the guard
     * under the chain lock (FiscalRecorder, D5) refuses, the sale rolls back, the
     * controller's own error answer becomes the 423.
     */
    public function testARestoreBetweenTheEarlyCheckAndTheIssueIsRefusedUnderTheChainLock(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method($this->anything());
        $this->issue();
        $this->issue();
        $this->journal->writer->flush();
        $this->connection->executeStatement('DELETE FROM fiscal_record ORDER BY id DESC LIMIT 1');
        $head = $this->connection->fetchAssociative('SELECT id, hash FROM fiscal_record ORDER BY id DESC LIMIT 1');
        $this->connection->update('fiscal_chain', ['last_record_id' => $head['id'], 'last_hash' => $head['hash']], ['id' => 1]);
        $counter = $this->connection->fetchAssociative('SELECT year, last_number FROM ticket_sequence WHERE letter = ?', [JournalPathFixtures::LETTER]);
        $orders = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM order_meta');
        $guard = new class($this->connection, $this->journal->locations) extends FiscalJournalGuard {
            private bool $early = true;

            public function assertCanIssue(): void
            {
                if ($this->early) {
                    // The early check ran before the restore finished.
                    $this->early = false;

                    return;
                }
                parent::assertCanIssue();
            }
        };

        $response = $this->checkout($this->connection, $client, $guard);

        $this->assertSame(423, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('fiscal_journal_behind', json_decode((string) $response->getContent(), true)['code']);
        $this->assertSame($counter, $this->connection->fetchAssociative('SELECT year, last_number FROM ticket_sequence WHERE letter = ?', [JournalPathFixtures::LETTER]), 'no number taken');
        $this->assertSame($orders, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM order_meta'), 'no sale');
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fiscal_record'));
    }

    public function testTillsSellingAndFlushingAtOnceAreNeverRefused(): void
    {
        $processes = [];
        foreach (range(1, self::WORKERS) as $worker) {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                __DIR__ . '/fixtures/guard_worker.php',
                $this->journal->root,
                (string) self::PER_WORKER,
                (string) JournalPathFixtures::TERMINAL,
                (string) JournalPathFixtures::SESSION,
            ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        $sold = 0;
        $refused = [];
        foreach ($processes as [$process, $pipes]) {
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $result = json_decode($output, true);
            $this->assertIsArray($result, $output . $errors);
            $this->assertTrue($result['ok'], $output);
            $sold += $result['sold'];
            $refused = [...$refused, ...$result['refused']];
        }

        $this->assertSame([], $refused, 'no false block');
        $this->assertSame(self::WORKERS * self::PER_WORKER, $sold);
        $this->assertSame($sold, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fiscal_record'));
    }
}
