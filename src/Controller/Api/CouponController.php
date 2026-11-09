<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Coupon\CouponSlipService;
use VenPosTPV\Service\Mail\TicketMailException;
use VenPosTPV\Service\PrestaShop\CouponCreationService;
use VenPosTPV\Service\PrestaShop\Exception\CouponCreationException;
use VenPosTPV\Service\PrintAgent\TerminalNotResolvedException;

/**
 * «Crear cupón»: creates a PrestaShop cart rule, keeps it in the till, and hands
 * its slip over on the ticket printer, as a PDF, or by email.
 */
#[AsController]
final class CouponController
{
    private const ROLES = ['admin', 'cashier'];

    public function __construct(
        private readonly CouponCreationService $couponCreationService,
        private readonly CouponSlipService $slips,
    ) {
    }

    /** {data: {id, code, type, value, expires_at, quantity, quantity_per_user, local_id, printable}} */
    #[Route('/coupons', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (($denied = $this->denied($request)) !== null) {
            return $denied;
        }
        $session = $request->getSession();
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            $body = [];
        }
        try {
            $coupon = $this->couponCreationService->createIssued(
                $body,
                (int) $session->get('shop_id', 0),
                $this->sessionTerminal($request),
                (int) $session->get('user_id'),
            );

            return new JsonResponse(['data' => $coupon], Response::HTTP_CREATED);
        } catch (CouponCreationException $e) {
            return new JsonResponse(['error' => $e->getMessage()], in_array($e->getCode(), [409, 422, 503], true) ? $e->getCode() : 503);
        }
    }

    /** {data: {print_status: 'queued'|'printed', print_job_id?}} or {data: {fallback: 'pdf'}} */
    #[Route('/coupons/{id}/print', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function print(Request $request, int $id): JsonResponse
    {
        if (($denied = $this->denied($request)) !== null) {
            return $denied;
        }
        $coupon = $this->slips->find($id);
        if ($coupon === null) {
            return $this->notFound();
        }
        try {
            $terminalId = $this->slips->printTerminal($request->getSession()->get('terminal_id'));

            return new JsonResponse(['data' => $this->slips->print($coupon, $terminalId, (int) $request->getSession()->get('user_id'))]);
        } catch (TerminalNotResolvedException $e) {
            return new JsonResponse(['error' => $e->getMessage(), 'code' => TerminalNotResolvedException::CODE], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'No se ha podido imprimir el cupón'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/coupons/{id}/pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function pdf(Request $request, int $id): Response
    {
        if (($denied = $this->denied($request)) !== null) {
            return $denied;
        }
        $coupon = $this->slips->find($id);
        if ($coupon === null) {
            return $this->notFound();
        }

        return new Response($this->slips->pdf($coupon, $this->sessionTerminal($request)), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="cupon-%s.pdf"', $coupon->code),
        ]);
    }

    /** Body {email}; {data: {sent: true}}, or the mailer's refusal with its status (409, 422, 502). */
    #[Route('/coupons/{id}/email', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function email(Request $request, int $id): JsonResponse
    {
        if (($denied = $this->denied($request)) !== null) {
            return $denied;
        }
        $coupon = $this->slips->find($id);
        if ($coupon === null) {
            return $this->notFound();
        }
        $body = json_decode((string) $request->getContent(), true);
        try {
            $this->slips->email($coupon, $this->sessionTerminal($request), \is_array($body) && \is_string($body['email'] ?? null) ? $body['email'] : '');
        } catch (TicketMailException $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->status);
        }

        return new JsonResponse(['data' => ['sent' => true]]);
    }

    /** 401 without a session, 403 for a role that may not create coupons. */
    private function denied(Request $request): ?JsonResponse
    {
        $session = $request->getSession();
        $userId = $session->get('user_id');
        if (!is_int($userId) || $userId <= 0) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        if (!in_array($session->get('role'), self::ROLES, true)) {
            return new JsonResponse(['error' => 'Acceso denegado'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function sessionTerminal(Request $request): ?int
    {
        $terminalId = $request->getSession()->get('terminal_id');

        return is_numeric($terminalId) && (int) $terminalId > 0 ? (int) $terminalId : null;
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['error' => 'Cupón no encontrado'], Response::HTTP_NOT_FOUND);
    }
}
