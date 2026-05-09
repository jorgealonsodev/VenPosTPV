<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\App;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use VenPosTPV\Controller\App\PosController;

class PosControllerTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        session_start();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function testIndexRedirectsWhenNotAuthenticated(): void
    {
        $loader = new ArrayLoader([
            'pos.html.twig' => '<html><body>{{ session_data }}</body></html>',
        ]);
        $twig = new Environment($loader);
        $controller = new PosController($twig);
        $response = $controller->index();

        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        $this->assertSame('/', $response->headers->get('Location'));
    }

    public function testIndexRendersTwigShellWhenAuthenticated(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['cashier_name'] = 'María García';
        $_SESSION['store_name'] = 'VenPosTPV';
        $_SESSION['shop_id'] = 1;

        $loader = new ArrayLoader([
            'pos.html.twig' => '<html><body>{{ session_data }}</body></html>',
        ]);
        $twig = new Environment($loader);
        $controller = new PosController($twig);
        $response = $controller->index();

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $content = (string) $response->getContent();
        $this->assertStringContainsString('Mar\u00eda Garc\u00eda', $content);
        $this->assertStringContainsString('VenPosTPV', $content);
    }
}
