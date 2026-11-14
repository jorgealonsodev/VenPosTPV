<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal\Journal;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIntents;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;

/** D3.1: the intent written inside the issuing transaction. */
final class FiscalJournalIntentsTest extends TestCase
{
    private string $root;
    /** @var list<array{string, string}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->root = JournalLines::temporaryDirectory('intents');
    }

    protected function tearDown(): void
    {
        JournalLines::remove($this->root);
    }

    private function intents(): FiscalJournalIntents
    {
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

        return new FiscalJournalIntents(new FiscalJournalLocations($this->root . '/backups/fiscal-journal', $this->root . '/var/fiscal-journal'), $logger);
    }

    /** @return array<string, mixed> */
    private static function record(int $n): array
    {
        return ['invoice_type' => 'F2', 'number' => sprintf('A26%06d', $n), 'hash' => strtoupper(hash('sha256', 'doc' . $n)), 'order_id' => $n];
    }

    public function testTheIntentIsWrittenWithoutTakingTheJournalLock(): void
    {
        // The intent is written under the fiscal_chain lock: it must never wait for a flush.
        $this->intents()->prepared(self::record(1));

        $this->assertFileExists($this->root . '/backups/fiscal-journal/intents/' . self::record(1)['hash'] . '.json');
        $this->assertFileDoesNotExist($this->root . '/backups/fiscal-journal/journal.lock');
        $this->assertFileDoesNotExist($this->root . '/var/fiscal-journal/journal.lock');
    }

    public function testAnUnwritableJournalWarnsOncePerProcessAndNeverLogsAnError(): void
    {
        mkdir($this->root . '/backups', 0500);
        mkdir($this->root . '/var', 0500);
        $intents = $this->intents();

        $intents->prepared(self::record(1));
        $intents->prepared(self::record(2));

        chmod($this->root . '/backups', 0700);
        chmod($this->root . '/var', 0700);
        $this->assertSame(['warning', 'debug'], array_column($this->logs, 0));
        $this->assertTrue($intents->issuedHere(), 'the flush after the response still runs');
    }
}
