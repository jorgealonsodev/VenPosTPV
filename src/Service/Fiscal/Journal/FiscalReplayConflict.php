<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

/** A row the replay must insert is taken by a different one (fiscal-journal D8.1, exit code 4). */
final class FiscalReplayConflict extends \RuntimeException
{
}
