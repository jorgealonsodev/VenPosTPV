<?php

declare(strict_types=1);

namespace VenPosTPV\Service\PrintAgent;

/**
 * The connection of a waiting long poll (GET /api/agent/v1/jobs?wait=…).
 *
 * PHP only notices that the client has gone when it writes to it, so each second
 * of the wait writes one space (leading whitespace keeps the JSON body valid) and
 * pushes it out. A stopped agent's last poll then ends without claiming jobs into
 * a dead connection (C1, AC2).
 */
class LongPollLink
{
    /** Keeps the script running when the client goes, so the wait ends cleanly. */
    public function hold(): void
    {
        ignore_user_abort(true);
    }

    /** Writes one space to the client; false when the client has gone. */
    public function alive(): bool
    {
        echo ' ';
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();

        return connection_aborted() === 0;
    }
}
