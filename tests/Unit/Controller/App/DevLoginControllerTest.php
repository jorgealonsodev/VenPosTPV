<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\App;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use VenPosTPV\Controller\App\DevLoginController;

final class DevLoginControllerTest extends TestCase
{
    private function request(): Request
    {
        $request = Request::create('/app/dev-login');
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    public function testProductionAnswersNotFoundAndOpensNoSession(): void
    {
        $request = $this->request();
        try {
            (new DevLoginController('', 'prod'))->index($request);
            self::fail('dev-login must not work in prod.');
        } catch (NotFoundHttpException) {
            self::assertNull($request->getSession()->get('user_id'));
        }
    }

    public function testDevelopmentStillLogsInAndRedirects(): void
    {
        $request = $this->request();
        $response = (new DevLoginController('/tpv', 'dev'))->index($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/tpv/app/pos', $response->headers->get('Location'));
        self::assertSame(1, $request->getSession()->get('user_id'));
    }
}
