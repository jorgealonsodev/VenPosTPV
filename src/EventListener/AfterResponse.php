<?php

declare(strict_types=1);

namespace VenPosTPV\EventListener;

use Symfony\Component\HttpFoundation\Response;

/**
 * Work that runs on kernel.terminate must not hold the response. Under PHP-FPM the
 * response is closed before kernel.terminate; under the CGI front controller used
 * here (no fastcgi_finish_request) it is flushed with an explicit Content-Length
 * and "Connection: close", so the browser has the whole answer before that work
 * starts. Shared by the listeners that work after the response.
 */
final class AfterResponse
{
    public static function closeConnection(Response $response): void
    {
        $content = $response->getContent();
        if (\is_string($content) && !$response->headers->has('Content-Length') && !$response->headers->has('Transfer-Encoding')) {
            $response->headers->set('Content-Length', (string) \strlen($content));
        }
        $response->headers->set('Connection', 'close');
    }
}
