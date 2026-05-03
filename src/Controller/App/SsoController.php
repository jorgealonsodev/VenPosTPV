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
            'SELECT id, name, role_id FROM user WHERE ps_employee_id = ? AND active = 1',
            [$payload['employee_id']]
        );

        if ($user === false) {
            return new JsonResponse(
                ['error' => 'User not configured'],
                Response::HTTP_FORBIDDEN
            );
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['ps_employee_id'] = $payload['employee_id'];
        $_SESSION['shop_id'] = $payload['shop_id'];
        $_SESSION['authenticated_at'] = time();

        $conn->executeStatement(
            'UPDATE user SET last_login_at = NOW() WHERE id = ?',
            [(int) $user['id']]
        );

        return new RedirectResponse('/app/');
    }
}
