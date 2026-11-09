<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\App;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use VenPosTPV\Service\Share\DocumentShareService;

/**
 * GET /d/{token} (config/routes.yaml): the PDF behind a link sent by WhatsApp, for
 * the customer's phone, so it needs no session and opens none. It sits outside
 * /api and /app, so no session, device or origin listener applies to it. Anything
 * but a valid, unexpired link to an existing document is a bare 404.
 */
#[AsController]
final class DocumentDownloadController
{
    private const HEADERS = [
        'Cache-Control' => 'private, no-store',
        'X-Robots-Tag' => 'noindex, nofollow',
        // The token is in the URL: never send it on as a Referer.
        'Referrer-Policy' => 'no-referrer',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(private readonly DocumentShareService $documents)
    {
    }

    public function download(Request $request, string $token): Response
    {
        $document = $this->documents->download($token);
        if ($document === null) {
            return new Response('', Response::HTTP_NOT_FOUND, self::HEADERS + ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return new Response($document['pdf'], Response::HTTP_OK, self::HEADERS + [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $document['fileName']),
        ]);
    }
}
