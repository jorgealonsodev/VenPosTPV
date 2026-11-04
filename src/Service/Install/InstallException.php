<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Install;

/**
 * A problem the installer explains to the person installing (Spanish message,
 * safe to show). Anything else is unexpected and only goes to the error log.
 */
final class InstallException extends \RuntimeException
{
}
