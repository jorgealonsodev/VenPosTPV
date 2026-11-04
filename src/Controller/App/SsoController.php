<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\App;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use VenPosTPV\Service\Db\ConnectionManagerInterface;
use VenPosTPV\Service\Sso\Exception\ExpiredTokenException;
use VenPosTPV\Service\Sso\Exception\InvalidTokenException;
use VenPosTPV\Service\Sso\Exception\ReplayedNonceException;
use VenPosTPV\Service\Sso\SsoTokenVerifier;

#[AsController]
class SsoController
{
    public function __construct(
        private readonly SsoTokenVerifier $tokenVerifier,
        private readonly ConnectionManagerInterface $connectionManager,
        private readonly string $basePath = '',
    ) {
    }

    public function index(Request $request): Response
    {
        $token = $request->query->get('token');

        if (!is_string($token) || $token === '') {
            return new JsonResponse(
                ['error' => 'Missing token'],
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            $payload = $this->tokenVerifier->verify($token);
        } catch (InvalidTokenException $e) {
            $message = $e->getMessage();
            $status = match ($message) {
                'SSO configuration error' => Response::HTTP_INTERNAL_SERVER_ERROR,
                'Invalid token signature' => Response::HTTP_UNAUTHORIZED,
                default => Response::HTTP_BAD_REQUEST,
            };

            return new JsonResponse(['error' => $message], $status);
        } catch (ExpiredTokenException $e) {
            return new JsonResponse(
                ['error' => $e->getMessage()],
                Response::HTTP_UNAUTHORIZED
            );
        } catch (ReplayedNonceException $e) {
            return new JsonResponse(
                ['error' => $e->getMessage()],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $conn = $this->connectionManager->getTpvConnection();

        $user = $conn->fetchAssociative(
            'SELECT u.id, u.name, r.name AS role FROM user u JOIN role r ON r.id = u.role_id WHERE u.ps_employee_id = ? AND u.active = 1',
            [$payload['employee_id']]
        );

        if ($user === false) {
            return new JsonResponse(
                ['error' => 'User not configured'],
                Response::HTTP_FORBIDDEN
            );
        }

        // R4: the POS reads the Symfony session (its attribute bag), so the login is
        // written there, with the framework's cookie flags. R6/D2: a fresh session id
        // that keeps the existing data (the cart) instead of starting from scratch.
        $session = $request->getSession();
        $session->start();
        $session->migrate(true);

        $session->set('user_id', (int) $user['id']);
        $session->set('cashier_name', (string) $user['name']);
        $session->set('role', (string) $user['role']);
        $session->set('ps_employee_id', $payload['employee_id']);
        $session->set('shop_id', $payload['shop_id']);
        $session->set('authenticated_at', time());
        $session->set('terminal_id', null);
        $session->set('cash_session_id', null);
        $session->remove('cash_opened_at');
        $session->remove('opening_cash');

        $conn->executeStatement(
            'UPDATE user SET last_login_at = NOW() WHERE id = ?',
            [(int) $user['id']]
        );

        return new RedirectResponse($this->basePath . '/app/pos');
    }
}
