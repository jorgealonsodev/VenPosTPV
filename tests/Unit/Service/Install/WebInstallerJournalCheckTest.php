<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Install;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIdentity;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\Install\WebInstaller;
use VenPosTPV\Service\PrestaShop\PsJournalClient;
use VenPosTPV\Tests\Unit\Service\Fiscal\Journal\FiscalJournalStepTest;
use VenPosTPV\Tests\Unit\Service\Fiscal\Journal\JournalLines;

/**
 * Before the installer starts the fiscal journal it asks the shop for every journal
 * it holds. Only when the shop holds documents this database lacks does the
 * installer stop on its decision page; a shop with no journal lines, one that
 * cannot be asked (down, an older module, an error) and a database that already
 * names its journal leave the install exactly as before. On SQLite.
 */
final class WebInstallerJournalCheckTest extends TestCase
{
    private const PREVIOUS = '00000000-0000-4000-8000-0000000000d1';
    private const OTHER = '00000000-0000-4000-8000-0000000000d2';

    private string $root;
    private Connection $connection;
    /** @var array<string, mixed> what the shop answers */
    private array $answer;
    private int $asked = 0;

    protected function setUp(): void
    {
        $this->root = JournalLines::temporaryDirectory('installer-check');
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->root . '/db.sqlite']);
        $this->connection->executeStatement('CREATE TABLE fiscal_record (id INTEGER PRIMARY KEY AUTOINCREMENT, record_type VARCHAR(10) NOT NULL, invoice_type VARCHAR(2) NOT NULL, issuer_nif VARCHAR(16) NOT NULL, number VARCHAR(60) NOT NULL, issue_date VARCHAR(10) NOT NULL, tax_total VARCHAR(16) NOT NULL, total VARCHAR(16) NOT NULL, previous_hash VARCHAR(64) NULL, hash VARCHAR(64) NOT NULL, generated_at VARCHAR(25) NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE fiscal_chain (id INTEGER PRIMARY KEY, last_record_id INTEGER NULL, last_hash VARCHAR(64) NULL)');
        $this->connection->executeStatement('INSERT INTO fiscal_chain (id, last_record_id, last_hash) VALUES (1, NULL, NULL)');
        $this->connection->executeStatement('CREATE TABLE app_setting (name VARCHAR(100) PRIMARY KEY, value TEXT)');
        $this->answer = self::holding([
            self::PREVIOUS => ['A26' => ['A26000007', 7], 'R2026' => ['R2026-000002', 9]],
            self::OTHER => ['F2026' => ['F2026-000003', 3]],
        ]);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        JournalLines::remove($this->root);
    }

    /**
     * @param array<string, array<string, array{0: string, 1: int}>> $journals per journal, series => [last number, its seq]
     *
     * @return array<string, mixed> an OK heads() answer
     */
    private static function holding(array $journals): array
    {
        $heads = [];
        $series = [];
        foreach ($journals as $id => $bySeries) {
            $last = 0;
            $entries = [];
            foreach ($bySeries as $name => [$number, $seq]) {
                $entries[$name] = ['number' => $number, 'hash' => strtoupper(hash('sha256', $number)), 'seq' => $seq];
                $series[$name] = ['journal_id' => $id] + $entries[$name];
                $last = max($last, $seq);
            }
            $heads[$id] = ['journal_id' => $id, 'last_seq' => $last, 'contiguous_seq' => $last, 'series' => $entries];
        }

        return ['status' => PsJournalClient::OK, 'journals' => $heads, 'series' => $series, 'error' => null];
    }

    private function client(): PsJournalClient
    {
        return new class($this->answer, $this->asked) extends PsJournalClient {
            public function __construct(private array &$answer, private int &$asked)
            {
                parent::__construct('https://tienda.tutienda.com', 'secret', new MockHttpClient());
            }

            public function heads(): array
            {
                ++$this->asked;
                if (($this->answer['throw'] ?? false) === true) {
                    throw new \RuntimeException('boom');
                }

                return $this->answer;
            }
        };
    }

    private function check(): ?array
    {
        return (new WebInstaller($this->root))->shopJournalAhead($this->connection, $this->client());
    }

    public function testAShopHoldingDocumentsAnEmptyDatabaseLacksStopsTheInstallWithWhatItHolds(): void
    {
        $ahead = $this->check();

        $this->assertNotNull($ahead);
        $this->assertSame(2, $ahead['journals']);
        // One line per document: the journals' last positions added up.
        $this->assertSame(12, $ahead['documents']);
        $this->assertSame(['A26' => 'A26000007', 'F2026' => 'F2026-000003', 'R2026' => 'R2026-000002'], $ahead['series']);
        $this->assertContains($ahead['missing'], ['A26000007', 'R2026-000002', 'F2026-000003']);
        $this->assertSame(1, $this->asked);
        // Asking changes nothing: no journal, no setting, no folder.
        $this->assertNull((new FiscalJournalIdentity($this->connection))->journalId());
        $this->assertSame([], (new FiscalJournalIdentity($this->connection))->retired());
        $this->assertDirectoryDoesNotExist($this->root . '/backups/fiscal-journal');
        $this->assertDirectoryDoesNotExist($this->root . '/var/fiscal-journal');
    }

    public function testRetiredJournalsAreLeftOut(): void
    {
        (new FiscalJournalIdentity($this->connection))->retire([self::PREVIOUS]);

        $ahead = $this->check();

        $this->assertNotNull($ahead);
        $this->assertSame(1, $ahead['journals']);
        $this->assertSame(3, $ahead['documents']);
        $this->assertSame(['F2026' => 'F2026-000003'], $ahead['series']);
    }

    public function testAShopWithNoJournalLinesChangesNothing(): void
    {
        $this->answer = self::holding([]);

        $this->assertNull($this->check());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function shopsThatCannotBeAsked(): iterable
    {
        yield 'shop down' => [['status' => PsJournalClient::ERROR, 'journals' => null, 'series' => null, 'error' => 'The shop could not be reached']];
        yield 'older module' => [['status' => PsJournalClient::UNAVAILABLE, 'journals' => null, 'series' => null, 'error' => 'TPV Connect 0.9.0 or later is needed']];
        yield 'client failure' => [['throw' => true]];
    }

    /** @param array<string, mixed> $answer */
    #[\PHPUnit\Framework\Attributes\DataProvider('shopsThatCannotBeAsked')]
    public function testAShopThatCannotBeAskedChangesNothing(array $answer): void
    {
        $this->answer = $answer;

        $this->assertNull($this->check());
    }

    public function testADatabaseThatHoldsEveryDocumentOfTheShopChangesNothing(): void
    {
        FiscalJournalStepTest::issue($this->connection, 1);
        $record = $this->connection->fetchAssociative('SELECT number, hash FROM fiscal_record');
        $this->answer = ['status' => PsJournalClient::OK, 'journals' => [self::PREVIOUS => ['journal_id' => self::PREVIOUS, 'last_seq' => 1, 'contiguous_seq' => 1, 'series' => ['A26' => ['number' => $record['number'], 'hash' => $record['hash'], 'seq' => 1]]]], 'series' => [], 'error' => null];

        $this->assertNull($this->check());
    }

    /** Re-running the installer later: the journal this install started is never offered for retirement. */
    public function testADatabaseThatAlreadyNamesItsJournalIsNeverAsked(): void
    {
        (new FiscalJournalIdentity($this->connection))->store('00000000-0000-4000-8000-0000000000e1');

        $this->assertNull($this->check());
        $this->assertSame(0, $this->asked);
    }

    /**
     * The installer must tell "the shop answered: nothing ahead" from "the shop could
     * not be asked", so a choice already made on the decision page is never dropped.
     */
    public function testTheCheckSaysTheShopIsAheadWithWhatItHolds(): void
    {
        $check = (new WebInstaller($this->root))->shopJournalCheck($this->connection, $this->client());

        $this->assertSame(WebInstaller::SHOP_AHEAD, $check['state']);
        $this->assertSame($this->check(), $check['ahead']);
    }

    public function testTheCheckSaysTheShopAnsweredWithNothingAhead(): void
    {
        $this->answer = self::holding([]);

        $check = (new WebInstaller($this->root))->shopJournalCheck($this->connection, $this->client());

        $this->assertSame(['state' => WebInstaller::SHOP_CLEAR, 'ahead' => null], $check);
    }

    /** @param array<string, mixed> $answer */
    #[\PHPUnit\Framework\Attributes\DataProvider('shopsThatCannotBeAsked')]
    public function testTheCheckSaysTheShopCouldNotBeAsked(array $answer): void
    {
        $this->answer = $answer;

        $check = (new WebInstaller($this->root))->shopJournalCheck($this->connection, $this->client());

        $this->assertSame(['state' => WebInstaller::SHOP_UNREACHABLE, 'ahead' => null], $check);
        // shopJournalAhead keeps its meaning: null, as for a shop that holds nothing.
        $this->assertNull($this->check());
    }

    public function testTheCheckOfADatabaseThatAlreadyNamesItsJournalIsClearWithoutAsking(): void
    {
        (new FiscalJournalIdentity($this->connection))->store('00000000-0000-4000-8000-0000000000e1');

        $check = (new WebInstaller($this->root))->shopJournalCheck($this->connection, $this->client());

        $this->assertSame(['state' => WebInstaller::SHOP_CLEAR, 'ahead' => null], $check);
        $this->assertSame(0, $this->asked);
    }

    public function testFoldersThatAlreadyHoldAJournalAreNeverAsked(): void
    {
        $locations = new FiscalJournalLocations($this->root . '/backups/fiscal-journal', $this->root . '/var/fiscal-journal');
        FiscalJournalStepTest::writer($this->connection, $locations)->createJournal(null, null, 0);

        $this->assertNull($this->check());
        $this->assertSame(0, $this->asked);
    }
}
