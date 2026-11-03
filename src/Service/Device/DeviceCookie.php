<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Device;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * The device token cookie: HttpOnly (page scripts never read it), SameSite=Lax like
 * the session cookie, Secure on HTTPS, valid about ten years.
 */
class DeviceCookie
{
    public const NAME = 'tpv_device';

    public function __construct(
        #[Autowire('%app.base_path%')] private readonly string $basePath = '',
    ) {
    }

    public static function read(Request $request): ?string
    {
        $value = $request->cookies->get(self::NAME);

        return \is_string($value) && DeviceRegistry::isWellFormed($value) ? $value : null;
    }

    public function create(string $token, Request $request): Cookie
    {
        $path = '/' . trim($this->basePath, '/');

        return Cookie::create(
            self::NAME,
            $token,
            new \DateTimeImmutable('+10 years'),
            $path,
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX
        );
    }
}
