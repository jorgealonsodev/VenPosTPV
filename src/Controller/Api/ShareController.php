<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Coupon\CouponSlipService;
use VenPosTPV\Service\Invoice\InvoiceRepository;
use VenPosTPV\Service\Share\DocumentShareService;

/**
 * «WhatsApp» next to «Enviar por email»: {data: {url, message}}, the expiring link
 * to the document's PDF and the message the till opens WhatsApp with. Each route
 * asks for the same session and roles as that document's email.
 */
#[AsController]
final class ShareController
{
    public const NO_CONSENT_MESSAGE = 'El cliente no ha aceptado recibir la factura en formato electrónico';

    /** CouponController::ROLES */
    private const COUPON_ROLES = ['admin', 'cashier'];

    public function __construct(
        private readonly DocumentShareService $share,
        private readonly InvoiceRepository $invoices,
        private readonly CouponSlipService $slips,
    ) {
    }

    #[Route('/ticket/sale/{id}/whatsapp', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ticket(Request $request, int $id): JsonResponse
    {
        if (!$this->signedIn($request)) {
            return $this->unauthorized();
        }
        $link = $this->share->ticket($id);

        return $link === null ? $this->notFound('No se encuentra la venta.') : new JsonResponse(['data' => $link]);
    }

    /**
     * Body {consent}: like the email (InvoiceController::email), only with the
     * customer's consent, stored with the invoice when it was issued or now.
     */
    #[Route('/invoices/{id}/whatsapp', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function invoice(Request $request, int $id): JsonResponse
    {
        if (!$this->signedIn($request)) {
            return $this->unauthorized();
        }
        $invoice = $this->invoices->find($id);
        if ($invoice === null) {
            return $this->notFound('No se encuentra la factura');
        }
        if ($invoice['email_consent_at'] === null) {
            $body = json_decode((string) $request->getContent(), true);
            if (!\is_array($body) || ($body['consent'] ?? false) !== true) {
                return new JsonResponse(['error' => self::NO_CONSENT_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->invoices->recordEmailConsent($id, new \DateTimeImmutable());
        }

        return new JsonResponse(['data' => $this->share->invoice($invoice)]);
    }

    #[Route('/coupons/{id}/whatsapp', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function coupon(Request $request, int $id): JsonResponse
    {
        $userId = $request->getSession()->get('user_id');
        if (!\is_int($userId) || $userId <= 0) {
            return $this->unauthorized();
        }
        if (!\in_array($request->getSession()->get('role'), self::COUPON_ROLES, true)) {
            return new JsonResponse(['error' => 'Acceso denegado'], Response::HTTP_FORBIDDEN);
        }
        $coupon = $this->slips->find($id);

        return $coupon === null ? $this->notFound('Cupón no encontrado') : new JsonResponse(['data' => $this->share->coupon($coupon)]);
    }

    private function signedIn(Request $request): bool
    {
        return $request->getSession()->get('user_id') !== null;
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
    }

    private function notFound(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_NOT_FOUND);
    }
}
