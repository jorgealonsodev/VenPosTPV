<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal\Journal;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\Journal\JournalLocation;
use VenPosTPV\Service\Fiscal\Journal\JournalMerge;

/** D3.4 / S2: the lines written to the secondary location while the primary was unwritable go back, in order, once. */
final class JournalMergeTest extends TestCase
{
    private string $root;

    private JournalLocation $primary;

    private JournalLocation $secondary;

    protected function setUp(): void
    {
        $this->root = JournalLines::temporaryDirectory('merge');
        $this->primary = new JournalLocation($this->root . '/backups/fiscal-journal');
        $this->secondary = new JournalLocation($this->root . '/var/fiscal-journal');
        $this->primary->prepare();
        $this->secondary->prepare();
    }

    protected function tearDown(): void
    {
        JournalLines::remove($this->root);
    }

    public function testSecondaryLinesAreAppendedAfterThePrimaryHeadInOrderAndTheSecondaryIsEmptied(): void
    {
        $lines = JournalLines::chain(1, 5);
        foreach (\array_slice($lines, 0, 2) as $line) {
            $this->primary->append($line);
        }
        foreach (\array_slice($lines, 2) as $line) {
            $this->secondary->append($line);
        }
        $this->secondary->writeHead($lines[4]);

        $this->assertSame(3, JournalMerge::secondaryIntoPrimary($this->secondary, $this->primary));

        $this->assertSame($lines, iterator_to_array($this->primary->lines(), false));
        $this->assertSame([], $this->secondary->journalFiles());
        $this->assertNull($this->secondary->readHead());
        $this->assertSame(0, JournalMerge::secondaryIntoPrimary($this->secondary, $this->primary), 'a second merge adds nothing');
        $this->assertSame($lines, iterator_to_array($this->primary->lines(), false));
    }

    public function testLinesAlreadyInThePrimaryAreDroppedNotDuplicated(): void
    {
        $lines = JournalLines::chain(1, 3);
        foreach ($lines as $line) {
            $this->primary->append($line);
        }
        $this->secondary->append($lines[2]);

        $this->assertSame(0, JournalMerge::secondaryIntoPrimary($this->secondary, $this->primary));

        $this->assertSame($lines, iterator_to_array($this->primary->lines(), false));
        $this->assertSame([], $this->secondary->journalFiles());
    }

    public function testASecondaryThatDoesNotChainToThePrimaryIsKeptAndNothingIsAppended(): void
    {
        $lines = JournalLines::chain(1, 2);
        $this->primary->append($lines[0]);
        $other = JournalLines::chain(2, 2, ['line_hash' => str_repeat('0', 64)]);
        $this->secondary->append($other[0]);

        $this->assertSame(0, JournalMerge::secondaryIntoPrimary($this->secondary, $this->primary));

        $this->assertSame([$lines[0]], iterator_to_array($this->primary->lines(), false));
        $this->assertCount(1, $this->secondary->journalFiles(), 'kept for the administrator');
    }
}
