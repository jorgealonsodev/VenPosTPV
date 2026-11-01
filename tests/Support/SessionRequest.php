<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Support;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class SessionRequest
{
    /** @param array<string, mixed> $query @param array<string, mixed> $sessionData */
    public static function create(array $query = [], array $sessionData = [], string $content = ''): Request
    {
        $session = new Session(new MockArraySessionStorage());
        foreach ($sessionData as $key => $value) {
            $session->set($key, $value);
        }

        $request = new Request($query, [], [], [], [], [], $content);
        $request->setSession($session);

        return $request;
    }

    /** @param array<string, mixed> $body @param array<string, mixed> $sessionData */
    public static function json(array $body, array $sessionData = []): Request
    {
        return self::create([], $sessionData, json_encode($body, JSON_THROW_ON_ERROR));
    }
}
