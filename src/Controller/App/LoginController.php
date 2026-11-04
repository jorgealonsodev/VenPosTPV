<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\App;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use VenPosTPV\EventListener\SecurityHeadersListener;

#[AsController]
class LoginController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly string $basePath = '',
    ) {
    }

    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // If already authenticated, redirect to pos
        $session = $request->getSession();
        if ($session->get('user_id') !== null) {
            return new \Symfony\Component\HttpFoundation\RedirectResponse($this->basePath . '/app/pos');
        }

        return new Response($this->twig->render('login.html.twig', [
            'csp_nonce' => (string) $request->attributes->get(SecurityHeadersListener::NONCE_ATTRIBUTE, ''),
        ]));
    }
}
