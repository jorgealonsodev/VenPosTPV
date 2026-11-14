<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use VenPosTPV\Service\Fiscal\FiscalHash;
use VenPosTPV\Service\Fiscal\Journal\FiscalDocumentSnapshot;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalAlerts;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIntents;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIdentity;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalShopSync;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalReconciler;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalState;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalWriter;
use VenPosTPV\Service\Fiscal\Journal\JournalLocation;
use VenPosTPV\Service\Mail\BackupAlertMailer;
use VenPosTPV\Service\PrestaShop\PsJournalClient;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The every-minute catch-up of the fiscal journal (fiscal-journal S2, S3, D3.5,
 * D9) on SQLite, with a fake clock and a fake mailer. The document rows are
 * stubbed: what a line stores is FiscalJournalWriter's matter, tested on MySQL.
 */
final class FiscalJournalReconcilerTest extends TestCase
{
    private string $root;
    private Connection $connection;
    private FiscalJournalLocations $locations;
    private FiscalJournalIntents $intents;
    private FiscalJournalWriter $writer;
    /** @var list<array{string, string}> subject and text of every e-mail sent */
    private array $mails = [];
    /** @var list<array{string, string}> level and message of every log record */
    private array $logs = [];
    /** Numbers the stubbed snapshot cannot read (a record in an unknown format). */
    private array $unreadable = [];

    protected function setUp(): void
    {
        $this->root = JournalLines::temporaryDirectory('reconciler');
        // A file, not ':memory:': closing an in-memory SQLite connection discards it.
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->root . '/db.sqlite']);
        $this->connection->executeStatement('CREATE TABLE fiscal_record (id INTEGER PRIMARY KEY AUTOINCREMENT, record_type VARCHAR(10) NOT NULL, invoice_type VARCHAR(2) NOT NULL, issuer_nif VARCHAR(16) NOT NULL, number VARCHAR(60) NOT NULL, issue_date VARCHAR(10) NOT NULL, tax_total VARCHAR(16) NOT NULL, total VARCHAR(16) NOT NULL, previous_hash VARCHAR(64) NULL, hash VARCHAR(64) NOT NULL, generated_at VARCHAR(25) NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE fiscal_chain (id INTEGER PRIMARY KEY, last_record_id INTEGER NULL, last_hash VARCHAR(64) NULL)');
        $this->connection->executeStatement('INSERT INTO fiscal_chain (id, last_record_id, last_hash) VALUES (1, NULL, NULL)');
        $this->connection->executeStatement('CREATE TABLE app_setting (name VARCHAR(100) PRIMARY KEY, value TEXT)');
        $this->locations = new FiscalJournalLocations($this->root . '/backups/fiscal-journal', $this->root . '/var/fiscal-journal');
        $this->intents = new FiscalJournalIntents($this->locations);
        $this->writer = new FiscalJournalWriter($this->connection, $this->locations, $this->intents, null, $this->snapshot());
    }

    private function snapshot(): FiscalDocumentSnapshot
    {
        $unreadable = &$this->unreadable;

        return new class($this->connection, $unreadable) extends FiscalDocumentSnapshot {
            /** @param list<string> $unreadable */
            public function __construct(\Doctrine\DBAL\Connection $connection, private array &$unreadable)
            {
                parent::__construct($connection);
            }

            public function build(array $record, ?int $refundMovementId = null): array
            {
                if (\in_array($record['number'], $this->unreadable, true)) {
                    throw new \UnexpectedValueException(sprintf('Unknown fiscal document number %s', $record['number']));
                }

                return ['kind' => (string) $record['invoice_type'], 'series' => 'A26', 'counter' => null, 'rows' => []];
            }
        };
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        JournalLines::remove($this->root);
    }

    private function reconciler(): FiscalJournalReconciler
    {
        $mailer = $this->createMock(BackupAlertMailer::class);
        $mailer->method('send')->willReturnCallback(function (string $subject, string $text): bool {
            $this->mails[] = [$subject, $text];

            return true;
        });

        $logger = new class($this->logs) extends AbstractLogger {
            /** @param list<array{string, string}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logs[] = [(string) $level, (string) $message];
            }
        };

        return new FiscalJournalReconciler($this->connection, $this->locations, $this->intents, new FiscalJournalAlerts($this->connection, $mailer), $logger, $this->snapshot());
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('Europe/Madrid'));
    }

    private function chainHead(): ?string
    {
        $hash = $this->connection->fetchOne('SELECT last_hash FROM fiscal_chain WHERE id = 1');

        return \is_string($hash) ? $hash : null;
    }

    /**
     * A fiscal record as FiscalRecorder builds it (not inserted).
     *
     * @return array<string, mixed>
     */
    private function record(string $number, string $generatedAt, string $total = '12.10'): array
    {
        $record = [
            'record_type' => 'alta',
            'invoice_type' => 'F2',
            'issuer_nif' => 'B00000000',
            'number' => $number,
            'issue_date' => self::at($generatedAt)->format('d-m-Y'),
            'tax_total' => '2.10',
            'total' => $total,
            'previous_hash' => $this->chainHead(),
            'generated_at' => self::at($generatedAt)->format('Y-m-d\TH:i:sP'),
        ];
        $record['hash'] = FiscalHash::alta($record['issuer_nif'], $number, $record['issue_date'], 'F2', $record['tax_total'], $total, (string) $record['previous_hash'], $record['generated_at']);

        return $record;
    }

    private function journalFile(): string
    {
        return $this->root . '/backups/fiscal-journal/journal-' . (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->format('Y-m') . '.jsonl';
    }

    /**
     * Replaces line $number (1-based) of the journal file with what $change makes of it.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    private function changeLine(int $number, callable $change): void
    {
        $lines = explode("\n", rtrim((string) file_get_contents($this->journalFile()), "\n"));
        $line = json_decode($lines[$number - 1], true);
        $lines[$number - 1] = json_encode($change($line), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
        file_put_contents($this->journalFile(), implode("\n", $lines) . "\n");
    }

    /** Five documents, journaled and checked by one run at 18:10. */
    private function fiveJournaled(): void
    {
        $this->writer->createJournal();
        foreach (range(1, 5) as $n) {
            $this->issue(sprintf('A26%06d', $n), sprintf('2026-11-14 18:0%d:00', $n));
        }
        $this->assertSame(FiscalJournalState::OK, $this->reconciler()->run(self::at('2026-11-14 18:10:00'))->status());
    }

    /** @param array<string, mixed> $record */
    private function commit(array $record): void
    {
        $this->connection->insert('fiscal_record', $record);
        $this->connection->update('fiscal_chain', ['last_record_id' => (int) $this->connection->lastInsertId(), 'last_hash' => $record['hash']], ['id' => 1]);
    }

    private function issue(string $number, string $generatedAt): void
    {
        $this->commit($this->record($number, $generatedAt));
    }

    /** @return list<array<string, mixed>> */
    private function lines(?JournalLocation $location = null): array
    {
        return array_values(iterator_to_array(($location ?? $this->locations->primary())->lines(), false));
    }

    private function readOnly(): void
    {
        chmod($this->root . '/backups/fiscal-journal', 0500);
        chmod($this->root . '/var/fiscal-journal', 0500);
    }

    private function writable(): void
    {
        chmod($this->root . '/backups/fiscal-journal', 0700);
        chmod($this->root . '/var/fiscal-journal', 0700);
    }

    /** @param array<string, mixed> $intent */
    private function writeIntent(array $intent): void
    {
        $this->locations->primary()->writeIntent($intent['fiscal_record']['hash'], $intent);
    }

    public function testNothingIsDoneWhileNoJournalExists(): void
    {
        $this->issue('A26000001', '2026-11-14 10:00:00');

        $state = $this->reconciler()->run(self::at('2026-11-14 12:00:00'));

        $this->assertSame(FiscalJournalState::NOT_STARTED, $state->status());
        $this->assertSame(0, $state->exitCode);
        $this->assertSame([], $this->mails, 'no alert while the journal is not started');
        $this->assertDirectoryDoesNotExist($this->root . '/backups/fiscal-journal', 'no folder is created before the provisioning step');
        $this->assertDirectoryDoesNotExist($this->root . '/var/fiscal-journal');
        $this->assertSame(FiscalJournalState::NOT_STARTED, $this->reconciler()->current(self::at('2026-11-14 12:00:00'))->status());
    }

    /**
     * Second correction round, recheck case (iii): a database from before the journal
     * (no setting) on a server whose folders were lost. The every-minute task still asks
     * the shop for every journal; when the shop holds a document the database lacks the
     * guard blocks, when it holds nothing no folder is made.
     */
    public function testWithoutAJournalTheShopIsAskedForEveryJournalAndAShopAheadBlocks(): void
    {
        $this->issue('A26000001', '2026-11-14 10:00:00');
        $answer = ['status' => PsJournalClient::OK, 'journals' => [], 'series' => [], 'error' => null];
        $client = new class($answer) extends PsJournalClient {
            public function __construct(public array $answer)
            {
                parent::__construct('https://tienda.tutienda.com', 'secret', new MockHttpClient());
            }

            public function heads(): array
            {
                return $this->answer;
            }
        };
        $shop = new FiscalJournalShopSync($this->connection, $this->locations, $client, new FiscalJournalIdentity($this->connection));
        $reconciler = new FiscalJournalReconciler($this->connection, $this->locations, $this->intents, new FiscalJournalAlerts($this->connection), null, $this->snapshot(), $shop);

        $this->assertSame(FiscalJournalState::NOT_STARTED, $reconciler->run(self::at('2026-11-14 12:00:00'))->status());
        $this->assertDirectoryDoesNotExist($this->root . '/backups/fiscal-journal', 'a shop holding nothing: nothing to keep');

        $journal = '00000000-0000-4000-8000-0000000000d1';
        $client->answer['journals'] = [$journal => ['journal_id' => $journal, 'last_seq' => 2, 'contiguous_seq' => 2, 'series' => ['A26' => ['number' => 'A26000002', 'hash' => str_repeat('E', 64), 'seq' => 2]]]];
        $reconciler->run(self::at('2026-11-14 12:01:00'));

        $this->assertSame(FiscalJournalGuard::BEHIND_SHOP, (new FiscalJournalGuard($this->connection, $this->locations))->check()['state']);
        $this->assertNull($this->locations->primary()->journalId(), 'no journal is created');
    }

    public function testMissingDocumentsAreAppendedAndTheStateSaysUpToDate(): void
    {
        $this->writer->createJournal();
        $this->issue('A26000001', '2026-11-14 18:00:00');
        $this->issue('A26000002', '2026-11-14 18:02:00');

        $state = $this->reconciler()->run(self::at('2026-11-14 18:03:00'));

        $this->assertSame(FiscalJournalState::OK, $state->status());
        $this->assertSame(0, $state->exitCode);
        $this->assertSame(['A26000001', 'A26000002'], array_column(array_column($this->lines(), 'fiscal_record'), 'number'));
        $this->assertSame(['number' => 'A26000002', 'invoice_type' => 'F2', 'generated_at' => '2026-11-14T18:02:00+01:00'], $state->data['last_document']);
        $this->assertSame(0, $state->data['pending']['count']);
        $stored = $this->locations->primary()->readState();
        $this->assertSame(FiscalJournalState::OK, $stored['status']);
        $this->assertSame('2026-11-14T18:03:00+01:00', $stored['checked_at']);
    }

    public function testTheLagIsReadFromTheDatabaseAndNeitherFolderWritableExitsOne(): void
    {
        $this->writer->createJournal();
        $this->issue('A26000001', '2026-11-14 18:00:00');
        $this->reconciler()->run(self::at('2026-11-14 18:00:30'));
        $this->readOnly();
        $this->issue('A26000002', '2026-11-14 18:01:00');
        $this->issue('A26000003', '2026-11-14 18:02:00');

        $state = $this->reconciler()->run(self::at('2026-11-14 18:03:00'));

        $this->assertSame(1, $state->exitCode, 'neither folder can be written');
        $this->assertSame(FiscalJournalState::PENDING, $state->status(), 'under 5 minutes it is only pending');
        $this->assertSame(['count' => 2, 'oldest_number' => 'A26000002', 'oldest_at' => '2026-11-14T18:01:00+01:00'], $state->data['pending']);
        $this->assertFalse($state->data['writable']);
        $this->assertSame([], $this->mails);

        $late = $this->reconciler()->run(self::at('2026-11-14 18:06:01'));
        $this->assertSame(FiscalJournalState::LAGGING, $late->status(), 'more than 5 minutes behind');
        $this->assertSame(0, $late->exitCode, 'the same incident: reported once');

        // The stored state could not be written: what Ajustes reads comes from the database.
        $current = $this->reconciler()->current(self::at('2026-11-14 18:10:00'));
        $this->assertSame(FiscalJournalState::LAGGING, $current->status());
        $this->assertSame(2, $current->data['pending']['count']);

        $this->writable();
        $recovered = $this->reconciler()->run(self::at('2026-11-14 18:11:00'));
        $this->assertSame(FiscalJournalState::OK, $recovered->status());
        $this->assertSame(0, $recovered->exitCode);
        $this->assertCount(3, $this->lines());
    }

    public function testTheAlertIsSentOncePerIncidentAgainAfterADayAndForANewIncident(): void
    {
        $this->writer->createJournal();
        $this->readOnly();
        $this->issue('A26000001', '2026-11-14 18:00:00');

        $this->reconciler()->run(self::at('2026-11-14 18:04:00'));
        $this->assertSame([], $this->mails, 'not yet 5 minutes');

        $this->reconciler()->run(self::at('2026-11-14 18:05:01'));
        $this->assertCount(1, $this->mails);
        $this->assertSame('VenPosTPV: el diario fiscal no se puede anotar', $this->mails[0][0]);
        $this->assertStringContainsString('El diario fiscal lleva más de 5 minutos sin poder anotarse. Revisa los permisos de la carpeta backups/fiscal-journal.', $this->mails[0][1]);
        $this->assertStringContainsString('A26000001', $this->mails[0][1]);

        foreach (['2026-11-14 18:06:00', '2026-11-14 19:00:00', '2026-11-15 18:04:00'] as $minute) {
            $this->reconciler()->run(self::at($minute));
        }
        $this->assertCount(1, $this->mails, 'not again while the same incident lasts');

        $this->reconciler()->run(self::at('2026-11-15 18:05:01'));
        $this->assertCount(2, $this->mails, 'again after 24 hours if it persists (S3)');

        $this->writable();
        $this->reconciler()->run(self::at('2026-11-15 18:06:00'));
        $this->assertCount(2, $this->mails, 'no e-mail for the recovery');
        $this->assertNull((new FiscalJournalAlerts($this->connection))->read(), 'the recovery ends the incident');

        $this->readOnly();
        $this->issue('A26000002', '2026-11-15 18:10:00');
        $this->reconciler()->run(self::at('2026-11-15 18:16:00'));
        $this->assertCount(3, $this->mails, 'a new incident is announced again');
    }

    public function testADamagedMiddleLineIsReportedWithExitThreeOnceAndNothingIsRewritten(): void
    {
        $this->writer->createJournal();
        foreach (range(1, 5) as $n) {
            $this->issue(sprintf('A26%06d', $n), sprintf('2026-11-14 18:0%d:00', $n));
        }
        $this->writer->flush();
        $this->changeLine(3, static function (array $line): array {
            $line['fiscal_record']['total'] = '99.99';

            return $line;
        });
        $damaged = file_get_contents($this->journalFile());

        $state = $this->reconciler()->run(self::at('2026-11-14 18:10:00'));

        $this->assertSame(3, $state->exitCode);
        $this->assertSame(FiscalJournalState::DAMAGED, $state->status());
        $this->assertSame(['folder' => 'primary', 'file' => basename($this->journalFile()), 'line' => 3, 'reason' => 'line'], $state->data['damaged']);
        $this->assertSame($damaged, file_get_contents($this->journalFile()), 'nothing rewritten');
        $this->assertCount(1, $this->mails);
        $this->assertSame('VenPosTPV: diario fiscal dañado', $this->mails[0][0]);
        $this->assertStringContainsString('El diario fiscal tiene una línea dañada en ' . basename($this->journalFile()) . ' (línea 3). Ejecuta tpv:fiscal:journal --verify-all.', $this->mails[0][1]);

        // Still damaged one minute later: the same incident, exit 0 and no second e-mail.
        $again = $this->reconciler()->run(self::at('2026-11-14 18:11:00'));
        $this->assertSame(FiscalJournalState::DAMAGED, $again->status());
        $this->assertSame(0, $again->exitCode);
        $this->assertCount(1, $this->mails);
        $this->assertSame(FiscalJournalState::DAMAGED, $this->reconciler()->current(self::at('2026-11-14 18:11:30'))->status());
        // A daily reminder.
        $this->assertSame(3, $this->reconciler()->run(self::at('2026-11-15 18:10:00'))->exitCode);
    }

    public function testAnOldLineChangedAfterItWasCheckedIsFoundByTheFullCheck(): void
    {
        $this->fiveJournaled();
        $this->changeLine(2, static function (array $line): array {
            $line['fiscal_record']['total'] = '99.99';

            return $line;
        });

        $this->assertSame(FiscalJournalState::OK, $this->reconciler()->run(self::at('2026-11-14 18:11:00'))->status(), 'each minute only the new lines are read');
        $onDemand = $this->reconciler()->run(self::at('2026-11-14 18:12:00'), true);
        $this->assertSame(FiscalJournalState::DAMAGED, $onDemand->status());
        $this->assertSame(2, $onDemand->data['damaged']['line']);
        $this->assertSame(3, $onDemand->exitCode);
    }

    public function testTheWholeJournalIsCheckedOnceADay(): void
    {
        $this->fiveJournaled();
        $this->changeLine(2, static function (array $line): array {
            $line['fiscal_record']['total'] = '99.99';

            return $line;
        });

        $this->assertSame(FiscalJournalState::OK, $this->reconciler()->run(self::at('2026-11-15 18:09:00'))->status());
        $this->assertSame(FiscalJournalState::DAMAGED, $this->reconciler()->run(self::at('2026-11-15 18:10:00'))->status(), '24 hours after the last full check');
    }

    public function testALineWhoseLineHashWasRecomputedIsCaughtByItsFiscalHash(): void
    {
        $this->fiveJournaled();
        $this->changeLine(5, static function (array $line): array {
            $line['fiscal_record']['total'] = '1.00';
            $line['line_hash'] = \VenPosTPV\Service\Fiscal\Journal\CanonicalJson::lineHash($line);

            return $line;
        });

        $state = $this->reconciler()->run(self::at('2026-11-14 18:11:00'), true);

        $this->assertSame(FiscalJournalState::DAMAGED, $state->status());
        $this->assertSame(['folder' => 'primary', 'file' => basename($this->journalFile()), 'line' => 5, 'reason' => 'fiscal_hash'], $state->data['damaged']);
    }

    public function testRemovedLastLinesRebuiltFromTheDatabaseAreReportedAsDamage(): void
    {
        $this->fiveJournaled();
        $lines = explode("\n", rtrim((string) file_get_contents($this->journalFile()), "\n"));
        file_put_contents($this->journalFile(), implode("\n", \array_slice($lines, 0, 3)) . "\n");
        sleep(1); // the rewritten lines carry another journaled_at

        $state = $this->reconciler()->run(self::at('2026-11-14 18:11:00'));

        $this->assertSame(FiscalJournalState::DAMAGED, $state->status(), 'the writer rewrote them; the journal had changed');
        $this->assertSame('removed', $state->data['damaged']['reason']);
        $this->assertSame(3, $state->exitCode);
        $this->assertStringContainsString('Faltan líneas o archivos del diario fiscal', $this->mails[0][1]);
    }

    public function testARemovedMonthFileIsReportedAsDamage(): void
    {
        $this->fiveJournaled();
        unlink($this->journalFile());
        sleep(1);

        $state = $this->reconciler()->run(self::at('2026-11-14 18:11:00'));

        $this->assertSame(FiscalJournalState::DAMAGED, $state->status());
        $this->assertSame('removed', $state->data['damaged']['reason']);
    }

    public function testAHeadThatDisagreesWithTheFilesIsReportedAsDamage(): void
    {
        $this->fiveJournaled();
        $head = $this->locations->primary()->readHead();
        $head['seq'] = 4;
        file_put_contents($this->root . '/backups/fiscal-journal/head.json', json_encode($head));

        $state = $this->reconciler()->run(self::at('2026-11-14 18:11:00'));

        $this->assertSame(FiscalJournalState::DAMAGED, $state->status());
        $this->assertSame(['folder' => 'primary', 'file' => 'head.json', 'line' => null, 'reason' => 'head'], $state->data['damaged']);
    }

    public function testADocumentThatCannotBeJournaledBlocksTheLaterOnesVisiblyWithoutStoppingTheJob(): void
    {
        $this->writer->createJournal();
        $this->issue('A26000001', '2026-11-14 18:00:00');
        $this->issue('X-1', '2026-11-14 18:01:00');
        $this->issue('A26000003', '2026-11-14 18:02:00');
        $this->unreadable = ['X-1'];

        $state = $this->reconciler()->run(self::at('2026-11-14 18:03:00'));

        $this->assertSame(FiscalJournalState::BLOCKED, $state->status());
        $this->assertSame(['number' => 'X-1', 'reason' => 'Unknown fiscal document number X-1'], $state->data['blocked']);
        $this->assertSame(1, $state->exitCode);
        $this->assertSame(2, $state->data['pending']['count']);
        $this->assertSame(['A26000001'], array_column(array_column($this->lines(), 'fiscal_record'), 'number'), 'never skipped: seq stays gapless');
        $this->assertCount(1, $this->mails);
        $this->assertStringContainsString('El diario fiscal no puede anotar el documento X-1', $this->mails[0][1]);

        $again = $this->reconciler()->run(self::at('2026-11-14 18:04:00'));
        $this->assertSame(FiscalJournalState::BLOCKED, $again->status());
        $this->assertSame(0, $again->exitCode);
        $this->assertCount(1, $this->mails);

        $this->unreadable = [];
        $fixed = $this->reconciler()->run(self::at('2026-11-14 18:05:00'));
        $this->assertSame(FiscalJournalState::OK, $fixed->status());
        $this->assertSame([1, 2, 3], array_column($this->lines(), 'seq'));
    }

    public function testALastingUnwritableFolderFailsTheJobOnlyWhenFirstSeenAndOnceADayAndLogsErrorsOnlyThen(): void
    {
        $this->writer->createJournal();
        $this->readOnly();

        $first = $this->reconciler()->run(self::at('2026-11-14 18:00:00'));
        $this->assertSame(1, $first->exitCode);
        $this->assertContains('error', array_column($this->logs, 0), 'the writer\'s error goes out once');

        $this->logs = [];
        foreach (['2026-11-14 18:01:00', '2026-11-14 23:00:00', '2026-11-15 17:59:00'] as $minute) {
            $this->assertSame(0, $this->reconciler()->run(self::at($minute))->exitCode, $minute);
        }
        $this->assertSame(['debug'], array_values(array_unique(array_column($this->logs, 0))), 'nothing above debug while it lasts');

        $this->assertSame(1, $this->reconciler()->run(self::at('2026-11-15 18:00:00'))->exitCode, 'a daily reminder');

        $this->writable();
        $this->assertSame(0, $this->reconciler()->run(self::at('2026-11-15 18:01:00'))->exitCode);
        $this->readOnly();
        $this->assertSame(1, $this->reconciler()->run(self::at('2026-11-15 18:02:00'))->exitCode, 'a new incident');
    }

    public function testALeftoverConfirmedIntentOfAJournaledDocumentIsDeletedNeverReportedAsBehind(): void
    {
        $this->writer->createJournal();
        $record = $this->record('A26000001', '2026-11-14 18:00:00');
        $this->commit($record);
        $this->writer->flush();
        // Killed between the append and the intent's removal.
        $this->writeIntent(['fiscal_record' => $record, 'created_at' => '2026-11-14T18:00:00+01:00', 'state' => FiscalJournalIntents::STATE_CONFIRMED]);

        $state = $this->reconciler()->run(self::at('2026-11-14 18:03:00'));

        $this->assertSame([], $state->data['confirmed_missing']);
        $this->assertSame([], $this->locations->primary()->intents());

        // Journaled but no longer in the database (a restore): still spent, the guard reads the head.
        $other = $this->record('A26000002', '2026-11-14 18:04:00');
        $this->commit($other);
        $this->writer->flush();
        $this->writeIntent(['fiscal_record' => $other, 'created_at' => '2026-11-14T18:04:00+01:00', 'state' => FiscalJournalIntents::STATE_CONFIRMED]);
        $this->connection->executeStatement('DELETE FROM fiscal_record WHERE number = ?', ['A26000002']);

        $restored = $this->reconciler()->run(self::at('2026-11-14 18:07:00'));
        $this->assertSame([], $restored->data['confirmed_missing']);
        $this->assertSame([], $this->locations->primary()->intents());
        $this->assertSame(FiscalJournalState::HEAD_MISSING, $restored->status());
    }

    public function testADatabaseBehindTheJournalFailsTheJobWithTheGuardCodeOncePerIncident(): void
    {
        $this->fiveJournaled();
        $this->connection->executeStatement('DELETE FROM fiscal_record WHERE number IN (?, ?)', ['A26000004', 'A26000005']);
        $head = $this->connection->fetchAssociative("SELECT id, hash FROM fiscal_record WHERE number = 'A26000003'");
        $this->connection->update('fiscal_chain', ['last_record_id' => $head['id'], 'last_hash' => $head['hash']], ['id' => 1]);

        $first = $this->reconciler()->run(self::at('2026-11-14 18:11:00'));
        $this->assertSame(FiscalJournalState::HEAD_MISSING, $first->status());
        $this->assertSame(FiscalJournalState::EXIT_GUARD, $first->exitCode, 'the run that detects it fails the job');
        $this->assertSame(0, $this->reconciler()->run(self::at('2026-11-14 18:12:00'))->exitCode, 'not every minute');
        $this->assertSame(FiscalJournalState::EXIT_GUARD, $this->reconciler()->run(self::at('2026-11-15 18:12:00'))->exitCode, 'again after 24 hours');
        $this->assertSame(5, \count($this->lines()), 'nothing is written while the database is behind');
    }

    /**
     * Final verification gap 5: a lost journal the shop has confirmed lets the till
     * sell, but nothing is journaled until tpv:fiscal:journal:reset: it raises the
     * journal alert once per incident (again after a day) and fails the job then.
     */
    public function testALostJournalRaisesTheAlertAndFailsTheJobOncePerIncident(): void
    {
        $this->issue('A26000001', '2026-11-14 10:00:00');
        (new FiscalJournalIdentity($this->connection))->store('0b4e8d0e-5d0c-4d6a-9f00-1a2b3c4d5e6f');

        $first = $this->reconciler()->run(self::at('2026-11-14 18:11:00'));

        $this->assertSame(FiscalJournalState::JOURNAL_MISSING, $first->status());
        $this->assertSame(FiscalJournalState::EXIT_DAMAGED, $first->exitCode);
        $this->assertCount(1, $this->mails);
        [$subject, $text] = $this->mails[0];
        $this->assertSame('VenPosTPV: el diario fiscal se ha perdido', $subject);
        $this->assertStringContainsString('0b4e8d0e-5d0c-4d6a-9f00-1a2b3c4d5e6f', $text);
        $this->assertStringContainsString('no se anotan', $text);
        $this->assertStringContainsString('tpv:fiscal:journal:reset --confirm', $text);
        $this->assertSame('journal_missing', $first->data['alert']['incident'] ?? null);

        $second = $this->reconciler()->run(self::at('2026-11-14 18:12:00'));
        $this->assertSame(FiscalJournalState::EXIT_OK, $second->exitCode, 'not every minute');
        $this->assertCount(1, $this->mails, 'one e-mail per incident');
        $this->assertSame('journal_missing', $second->data['alert']['incident'] ?? null, 'Ajustes keeps showing it');

        $later = $this->reconciler()->run(self::at('2026-11-15 18:12:00'));
        $this->assertSame(FiscalJournalState::EXIT_DAMAGED, $later->exitCode, 'again after 24 hours');
        $this->assertCount(2, $this->mails);
    }

    public function testAPrimaryThatLostItsJournalIdRaisesTheAlert(): void
    {
        $this->fiveJournaled();
        unlink($this->root . '/backups/fiscal-journal/journal.id');

        $state = $this->reconciler()->run(self::at('2026-11-14 18:11:00'));

        $this->assertSame(FiscalJournalState::JOURNAL_MISSING, $state->status());
        $this->assertSame(FiscalJournalState::EXIT_DAMAGED, $state->exitCode);
        $this->assertSame(['VenPosTPV: el diario fiscal se ha perdido'], array_column($this->mails, 0));
    }

    public function testOnlyNewLinesAreCheckedEachMinute(): void
    {
        $this->writer->createJournal();
        $this->issue('A26000001', '2026-11-14 18:00:00');
        $first = $this->reconciler()->run(self::at('2026-11-14 18:01:00'));
        $this->assertSame(1, $first->data['verified']['primary']['seq']);

        $this->issue('A26000002', '2026-11-14 18:01:30');
        $second = $this->reconciler()->run(self::at('2026-11-14 18:02:00'));

        $this->assertSame(FiscalJournalState::OK, $second->status());
        $this->assertSame(2, $second->data['verified']['primary']['seq']);
        $this->assertSame(2, $second->data['verified']['primary']['line']);
        $this->assertArrayNotHasKey('verified', $second->toArray(), 'checkpoints are not shown to the administrator');
    }

    public function testTheStateIsReplacedAtomicallyAndAMissingOrCorruptStateIsTolerated(): void
    {
        $this->writer->createJournal();
        $this->issue('A26000001', '2026-11-14 18:00:00');
        $this->reconciler()->run(self::at('2026-11-14 18:01:00'));
        $path = $this->root . '/backups/fiscal-journal/state.json';
        $inode = fileinode($path);
        $this->assertSame(0600, fileperms($path) & 0777);

        $this->reconciler()->run(self::at('2026-11-14 18:02:00'));
        clearstatcache();
        $this->assertNotSame($inode, fileinode($path), 'written to a temporary file and renamed over the old one');
        $this->assertSame([], glob($this->root . '/backups/fiscal-journal/*.tmp'), 'no temporary file left');
        $this->assertSame([], glob($this->root . '/var/fiscal-journal/*.tmp'));

        file_put_contents($path, '{"status": "ok", "checked_');
        file_put_contents($this->root . '/var/fiscal-journal/state.json', 'not json');
        $current = $this->reconciler()->current(self::at('2026-11-14 18:02:30'));
        $this->assertSame(FiscalJournalState::OK, $current->status(), 'computed from the database instead');
        $this->assertTrue($current->data['stale']);

        unlink($path);
        unlink($this->root . '/var/fiscal-journal/state.json');
        $this->issue('A26000002', '2026-11-14 18:02:40');
        $this->assertSame(FiscalJournalState::PENDING, $this->reconciler()->current(self::at('2026-11-14 18:02:50'))->status());

        $state = $this->reconciler()->run(self::at('2026-11-14 18:03:00'));
        $this->assertSame(FiscalJournalState::OK, $state->status());
        $this->assertSame(FiscalJournalState::OK, json_decode((string) file_get_contents($path), true)['status']);
    }

    public function testAnIntentWhoseDocumentIsInTheDatabaseIsJournaled(): void
    {
        $this->writer->createJournal();
        // The process committed and died before its flush (S4 b).
        $record = $this->record('A26000001', '2026-11-14 18:00:00');
        $this->writeIntent(['fiscal_record' => $record, 'created_at' => '2026-11-14T18:00:00+01:00', 'state' => FiscalJournalIntents::STATE_PREPARED]);
        $this->commit($record);

        $state = $this->reconciler()->run(self::at('2026-11-14 18:03:00'));

        $this->assertSame(FiscalJournalState::OK, $state->status());
        $this->assertSame([$record['hash']], array_column(array_column($this->lines(), 'fiscal_record'), 'hash'));
        $this->assertSame([], $this->locations->primary()->intents(), 'its intent is spent');
    }

    public function testAnIntentNeverIssuedIsDiscarded(): void
    {
        $this->writer->createJournal();
        // Rolled back (S4 a); since then the chain moved on.
        $neverIssued = $this->record('A26000001', '2026-11-14 18:00:00');
        $this->writeIntent(['fiscal_record' => $neverIssued, 'created_at' => '2026-11-14T18:00:00+01:00', 'state' => FiscalJournalIntents::STATE_PREPARED]);
        $this->issue('A26000001', '2026-11-14 18:00:20');
        // The same number now holding another hash.
        $sameNumber = $this->record('A26000002', '2026-11-14 18:00:30');
        $this->writeIntent(['fiscal_record' => $sameNumber, 'created_at' => '2026-11-14T18:00:30+01:00', 'state' => FiscalJournalIntents::STATE_PREPARED]);
        $this->issue('A26000002', '2026-11-14 18:00:40');

        $state = $this->reconciler()->run(self::at('2026-11-14 18:03:00'));

        $this->assertSame(FiscalJournalState::OK, $state->status());
        $this->assertSame([], $this->locations->primary()->intents());
        $discarded = $this->root . '/backups/fiscal-journal/intents/discarded/';
        $this->assertFileExists($discarded . $neverIssued['hash'] . '.json', 'kept aside, never deleted');
        $this->assertFileExists($discarded . $sameNumber['hash'] . '.json');
        $this->assertSame([], $state->data['unconfirmed_intents']);
        $this->assertCount(2, $this->lines(), 'only the documents in the database are journaled');
    }

    public function testAnIntentWhoseSlotIsStillFreeIsKeptWithoutBlockingAndShownAfterTenMinutes(): void
    {
        $this->writer->createJournal();
        $this->issue('A26000001', '2026-11-14 17:00:00');
        $record = $this->record('A26000002', '2026-11-14 18:00:00');
        $this->writeIntent(['fiscal_record' => $record, 'created_at' => '2026-11-14T18:00:00+01:00', 'state' => FiscalJournalIntents::STATE_PREPARED]);
        $young = $this->record('A26000002', '2026-11-14 18:09:00');
        $this->writeIntent(['fiscal_record' => $young, 'created_at' => '2026-11-14T18:09:00+01:00', 'state' => FiscalJournalIntents::STATE_PREPARED]);

        $early = $this->reconciler()->run(self::at('2026-11-14 18:03:00'));
        $this->assertSame(0, $early->exitCode);
        $this->assertSame(FiscalJournalState::OK, $early->status(), 'never blocking');
        $this->assertSame([], $early->data['unconfirmed_intents'], 'not shown before 10 minutes');
        $this->assertArrayHasKey($record['hash'], $this->locations->primary()->intents(), 'kept');

        $late = $this->reconciler()->run(self::at('2026-11-14 18:10:30'));
        $this->assertSame(0, $late->exitCode);
        $this->assertSame(FiscalJournalState::OK, $late->status());
        $this->assertSame([['number' => 'A26000002', 'created_at' => '2026-11-14T18:00:00+01:00']], $late->data['unconfirmed_intents']);
        $this->assertCount(2, $this->locations->primary()->intents(), 'both kept: an intent under 2 minutes is not even looked at');
    }

    public function testAConfirmedIntentMissingFromTheDatabaseIsReported(): void
    {
        $this->writer->createJournal();
        $record = $this->record('A26000001', '2026-11-14 18:00:00');
        $this->writeIntent(['fiscal_record' => $record, 'created_at' => '2026-11-14T18:00:00+01:00', 'state' => FiscalJournalIntents::STATE_CONFIRMED]);

        $state = $this->reconciler()->run(self::at('2026-11-14 18:03:00'));

        $this->assertSame(['A26000001'], $state->data['confirmed_missing'], 'the database is behind (the guard decides)');
        $this->assertArrayHasKey($record['hash'], $this->locations->primary()->intents(), 'never discarded');
    }
}
