<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use VenPosTPV\Controller\Api\FiscalJournalController;
use VenPosTPV\Service\Fiscal\FiscalChainVerifier;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalFlushResult;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalReconciler;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplay;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * fiscal-journal S7, D8.5 on MySQL with a real dump and restore: the Ajustes button
 * (POST /api/fiscal/journal/replay) lists, then recovers, the documents issued after
 * the backup through the same FiscalReplay as tpv:fiscal:replay; a cashier cannot,
 * and once the database is complete it does not run again.
 */
final class FiscalReplayEndpointMySqlIntegrationTest extends TestCase
{
    private const RESTORE_DB = 'venposjournal_replay_endpoint';

    private ?Connection $connection = null;
    private ?Connection $restored = null;
    private JournalPathFixtures $fixtures;
    private JournalHarness $journal;
    private string $dump = '';
    private bool $userAdded = false;

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
            $this->connection->insert('user', ['id' => 1, 'role_id' => (int) $this->connection->fetchOne('SELECT MIN(id) FROM role'), 'name' => 'Replay cashier', 'pin_hash' => 'x', 'active' => 1]);
            $this->userAdded = true;
        }
        $this->journal = new JournalHarness($this->connection);
        $this->journal->writer->createJournal();
        $this->fixtures->guard = new FiscalJournalGuard($this->connection, $this->journal->locations);
    }

    protected function tearDown(): void
    {
        $this->restored?->close();
        $this->restored = null;
        if ($this->connection !== null) {
            JournalRestore::drop(self::RESTORE_DB);
            $this->fixtures->reset();
            if ($this->userAdded) {
                $this->connection->executeStatement('DELETE FROM user WHERE id = 1');
            }
            $this->connection->close();
            $this->journal->remove();
        }
        if ($this->dump !== '' && is_file($this->dump)) {
            unlink($this->dump);
        }
        $this->connection = null;
    }

    private function sell(): void
    {
        $total = JournalPathFixtures::PRICE;
        $this->fixtures->checkout($this->fixtures->recorder($this->journal->intents))
            ->createOrder(1, $this->fixtures->cart() + ['cash_amount' => $total], 'cash', $total);
    }

    /** Two sales, the backup, three sales, everything journaled, then the backup restored. */
    private function restoredBehindTheJournal(): Connection
    {
        $this->sell();
        $this->sell();
        $this->assertSame(FiscalJournalFlushResult::OK, $this->journal->writer->flush()->status);
        $this->dump = JournalRestore::dump($this->connection);
        $this->sell();
        $this->sell();
        $this->sell();
        $this->assertSame(FiscalJournalFlushResult::OK, $this->journal->writer->flush()->status);

        return $this->restored = JournalRestore::restore($this->dump, self::RESTORE_DB);
    }

    private function controller(Connection $connection): FiscalJournalController
    {
        $guard = new FiscalJournalGuard($connection, $this->journal->locations);

        return new FiscalJournalController(
            $this->createMock(FiscalJournalReconciler::class),
            $guard,
            new FiscalReplay($connection, $this->journal->locations, $this->journal->root . '/var/fiscal-replay.lock', guard: $guard),
        );
    }

    private static function post(?string $role, array $body): Request
    {
        $request = Request::create('/api/fiscal/journal/replay', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
        $session = new Session(new MockArraySessionStorage());
        if ($role !== null) {
            $session->set('user_id', 1);
            $session->set('role', $role);
        }
        $request->setSession($session);

        return $request;
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function answer(JsonResponse $response): array
    {
        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    /** @return list<string> */
    private static function numbers(Connection $connection): array
    {
        return array_map('strval', $connection->fetchFirstColumn('SELECT number FROM fiscal_record ORDER BY id'));
    }

    public function testTheButtonListsThenRecoversTheDocumentsAndThenHasNothingToDo(): void
    {
        $restored = $this->restoredBehindTheJournal();
        $live = self::numbers($this->connection);
        $this->assertCount(5, $live);
        $this->assertSame(\array_slice($live, 0, 2), self::numbers($restored));
        $controller = $this->controller($restored);

        [$status, $body] = self::answer($controller->replay(self::post('admin', ['apply' => false])));

        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('pending', $body['data']['status']);
        $this->assertFalse($body['data']['applied']);
        $this->assertSame(\array_slice($live, 2), array_column($body['data']['documents'], 'number'));
        $this->assertSame([3, 4, 5], array_column($body['data']['documents'], 'seq'));
        $this->assertSame(\array_slice($live, 0, 2), self::numbers($restored), 'a dry run writes nothing');
        $this->assertSame(FiscalJournalGuard::BEHIND_JOURNAL, (new FiscalJournalGuard($restored, $this->journal->locations))->check()['state']);

        [$status, $body] = self::answer($controller->replay(self::post('admin', ['apply' => true])));

        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('done', $body['data']['status']);
        $this->assertTrue($body['data']['applied']);
        $this->assertSame(\array_slice($live, 2), array_column($body['data']['documents'], 'number'));
        $this->assertSame($live, self::numbers($restored));
        $this->assertSame(
            $this->connection->fetchAllAssociative('SELECT * FROM fiscal_record ORDER BY id'),
            $restored->fetchAllAssociative('SELECT * FROM fiscal_record ORDER BY id'),
            'fiscal records verbatim, original ids',
        );
        $chain = (new FiscalChainVerifier($restored))->verify();
        $this->assertTrue($chain['ok'], (string) $chain['reason']);
        $this->assertSame(FiscalJournalGuard::OK, (new FiscalJournalGuard($restored, $this->journal->locations))->check()['state'], 'the till may issue again');

        // Nothing to replay any more: the endpoint refuses without running the replay.
        $before = $restored->fetchAllAssociative('SELECT * FROM fiscal_record ORDER BY id');
        [$status, $body] = self::answer($controller->replay(self::post('admin', ['apply' => true])));
        $this->assertSame(409, $status);
        $this->assertSame('nothing_to_replay', $body['code']);
        $this->assertSame($before, $restored->fetchAllAssociative('SELECT * FROM fiscal_record ORDER BY id'));
    }

    public function testACashierCannotRecoverAndNothingIsWritten(): void
    {
        $restored = $this->restoredBehindTheJournal();
        $before = self::numbers($restored);

        [$status, $body] = self::answer($this->controller($restored)->replay(self::post('cashier', ['apply' => true])));

        $this->assertSame(403, $status);
        $this->assertSame(['error' => 'Acceso denegado — solo administradores'], $body);
        $this->assertSame($before, self::numbers($restored));
        $this->assertSame(FiscalJournalGuard::BEHIND_JOURNAL, (new FiscalJournalGuard($restored, $this->journal->locations))->check()['state']);
    }
}
