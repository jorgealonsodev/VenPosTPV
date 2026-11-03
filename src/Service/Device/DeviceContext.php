<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Device;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The till of the computer behind the current request.
 *
 * DeviceListener sets ATTRIBUTE on every API request: the till id, or null when the
 * browser has no linked till. A request without the attribute did not go through
 * the kernel (CLI, tests); only then may services fall back to the shop's first till.
 */
final class DeviceContext
{
    public const ATTRIBUTE = '_tpv_device_terminal_id';
    public const NOT_LINKED = 'device_not_linked';

    public static function notLinkedResponse(): JsonResponse
    {
        return new JsonResponse(
            ['code' => self::NOT_LINKED, 'error' => 'Este equipo no tiene caja asignada'],
            Response::HTTP_CONFLICT
        );
    }
}
