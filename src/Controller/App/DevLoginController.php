<?php
declare(strict_types=1);
namespace VenPosTPV\Controller\App;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class DevLoginController
{
    public function __construct(
        private readonly string $basePath = '',
        private readonly string $environment = 'prod',
    ) {
    }

    #[Route('/dev-login', name: 'dev_login')]
    public function index(Request $request): Response
    {
        // Logs in without credentials: only on a development machine.
        if ($this->environment !== 'dev') {
            throw new NotFoundHttpException();
        }

        // Write to Symfony session (used by PosController)
        $session = $request->getSession();
        $session->set('user_id', 1);
        $session->set('ps_employee_id', 1);
        $session->set('shop_id', 1);
        $session->set('cashier_name', 'María García');
        $session->set('store_name', 'VenPosTPV');
        $session->set('role', 'admin');
        $session->set('authenticated_at', date('c'));

        return new RedirectResponse($this->basePath . '/app/pos');
    }
}
