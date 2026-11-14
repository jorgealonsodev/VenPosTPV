<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

/**
 * A journal folder could not be locked, opened, written or synced (permissions, a
 * full disk, a file in the way). The writer then continues in the other folder
 * (fiscal-journal S2); it is never a reason to stop a sale.
 */
final class JournalWriteException extends \RuntimeException
{
}
