<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

/**
 * Brings back the lines written to the secondary location while the primary could
 * not be written (fiscal-journal D3.4, S2): those after the primary's last line are
 * appended to it in order, each checked by its line_hash, `prev` and `seq`; those
 * the primary already holds are dropped. The secondary is emptied only when all of
 * it was accounted for, so a secondary that does not chain to the primary is kept
 * (the flush then journals those documents from the database).
 */
final class JournalMerge
{
    /** @return int the lines appended to the primary. Call with both locations locked. */
    public static function secondaryIntoPrimary(JournalLocation $secondary, JournalLocation $primary): int
    {
        if ($secondary->journalFiles() === []) {
            return 0;
        }
        $head = $primary->lastLine();
        if ($head === false) {
            return 0;
        }
        $seq = (int) ($head['seq'] ?? 0);
        $hash = (string) ($head['line_hash'] ?? '');

        $appended = 0;
        $last = null;
        $complete = true;
        foreach ($secondary->lines() as $line) {
            if (!JournalLocation::selfValid($line)) {
                $complete = false;
                break;
            }
            if ($line['seq'] <= $seq) {
                continue;
            }
            if ($line['seq'] !== $seq + 1 || ($line['prev'] ?? null) !== $hash) {
                $complete = false;
                break;
            }
            $primary->append($line);
            $seq = $line['seq'];
            $hash = $line['line_hash'];
            $last = $line;
            ++$appended;
        }
        if ($last !== null) {
            $primary->writeHead($last);
        }
        if ($complete) {
            $secondary->clearLines();
        }

        return $appended;
    }
}
