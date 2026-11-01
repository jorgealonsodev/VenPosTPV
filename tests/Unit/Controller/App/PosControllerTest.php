<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\App;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use VenPosTPV\Controller\App\PosController;
use VenPosTPV\Tests\Support\SessionRequest;

class PosControllerTest extends TestCase
{
    private function twig(): Environment
    {
        return new Environment(new ArrayLoader(['pos.html.twig' => '<html><body>{{ session_data }}</body></html>']));
    }

    public function testIndexRedirectsWhenNotAuthenticated(): void
    {
        $controller = new PosController($this->twig(), $this->createMock(Connection::class));
        $response = $controller->index(SessionRequest::create());

        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        $this->assertSame('/app/login', $response->headers->get('Location'));
    }

    public function testIndexRendersTwigShellWhenAuthenticated(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchAssociative')->willReturn(false);
        $controller = new PosController($this->twig(), $connection);
        $response = $controller->index(SessionRequest::create([], [
            'user_id' => 1, 'cashier_name' => 'María García', 'store_name' => 'VenPosTPV', 'shop_id' => 1,
        ]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $content = (string) $response->getContent();
        $this->assertStringContainsString('Mar\\u00eda Garc\\u00eda', $content);
        $this->assertStringContainsString('VenPosTPV', $content);
    }
}
