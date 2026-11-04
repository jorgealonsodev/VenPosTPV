<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Session;

/**
 * Reads a login value for services that have no Request. The Symfony session
 * (stored under `_sf2_attributes`) is the source of truth, and SSO writes only
 * there; the raw `$_SESSION` key that the PIN/admin logins also write is the
 * fallback.
 */
final class SessionValue
{
    public static function int(string $key): ?int
    {
        $value = $_SESSION['_sf2_attributes'][$key] ?? $_SESSION[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
