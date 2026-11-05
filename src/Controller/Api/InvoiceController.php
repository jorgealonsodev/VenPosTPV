<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Invoice\InvoiceException;
use VenPosTPV\Service\Invoice\InvoiceIssuer;
use VenPosTPV\Service\Invoice\InvoicePdfGenerator;
use VenPosTPV\Service\Invoice\InvoiceRepository;
use VenPosTPV\Service\Mail\TicketMailer;
use VenPosTPV\Service\Mail\TicketMailException;

/**
 * Full invoices (docs/prd/factura-completa.md F3, F6, F10): a ticket redeemed for
 * an F3 invoice from the sale detail, its A4 PDF and its email, only with the
 * customer's consent (RD 1619/2012 art. 9.2).
 */
#[AsController]
final class InvoiceController
{
    public const NO_CONSENT_MESSAGE = 'El cliente no ha aceptado recibir la factura por email';

    public function __construct(
        private readonly InvoiceIssuer $issuer,
        private readonly InvoiceRepository $invoices,
        private readonly InvoicePdfGenerator $pdf,
        private readonly TicketMailer $mailer,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** What the invoice of a ticket would be, for the summary the cashier confirms (?customer_id=). */
    #[Route('/invoices/from-sale/{orderId}/preview', methods: ['GET'], requirements: ['orderId' => '\d+'])]
    public function preview(int $orderId, Request $request): JsonResponse
    {
        if (!$this->signedIn($request)) {
            return $this->unauthorized();
        }

        return $this->answer(fn (): array => $this->issuer->preview($orderId, (int) $request->query->get('customer_id', '0'), $this->terminalId($request), new \DateTimeImmutable()));
    }

    /** Body {customer_id, email_consent, request_id}: redeems the ticket for an F3 invoice. */
    #[Route('/invoices/from-sale/{orderId}', methods: ['POST'], requirements: ['orderId' => '\d+'])]
    public function redeem(int $orderId, Request $request): JsonResponse
    {
        if (!$this->signedIn($request)) {
            return $this->unauthorized();
        }
        $body = json_decode((string) $request->getContent(), true);
        $body = \is_array($body) ? $body : [];
        $userId = (int) $request->getSession()->get('user_id');

        try {
            $result = $this->issuer->redeem(
                $orderId,
                is_numeric($body['customer_id'] ?? null) ? (int) $body['customer_id'] : 0,
                $this->terminalId($request),
                $userId > 0 ? $userId : null,
                ($body['email_consent'] ?? false) === true,
                \is_string($body['request_id'] ?? null) ? $body['request_id'] : null,
                new \DateTimeImmutable(),
            );
        } catch (InvoiceException $e) {
            return $this->error($e);
        } catch (\Throwable $e) {
            $this->logger->error('Invoice of sale {order} could not be issued', ['order' => $orderId, 'exception' => $e]);

            return new JsonResponse(['error' => 'No se pudo emitir la factura; no se ha guardado nada. Inténtalo otra vez.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['data' => $result['invoice'] + ['warnings' => $result['warnings']]], $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /** The invoice that replaced a sale's ticket, or null («Sustituido por F26000004»). */
    #[Route('/invoices/by-sale/{orderId}', methods: ['GET'], requirements: ['orderId' => '\d+'])]
    public function bySale(int $orderId, Request $request): JsonResponse
    {
        if (!$this->signedIn($request)) {
            return $this->unauthorized();
        }

        return new JsonResponse(['data' => $this->invoices->findBySale($orderId)]);
    }

    #[Route('/invoices/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request): JsonResponse
    {
        if (!$this->signedIn($request)) {
            return $this->unauthorized();
        }
        $invoice = $this->invoices->find($id);

        return $invoice === null ? $this->notFound() : new JsonResponse(['data' => $invoice]);
    }

    /** The A4 PDF, the same document for every copy. */
    #[Route('/invoices/{id}/pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function pdf(int $id, Request $request): Response
    {
        if (!$this->signedIn($request)) {
            return $this->unauthorized();
        }
        $invoice = $this->invoices->find($id);
        if ($invoice === null) {
            return $this->notFound();
        }

        return new Response($this->pdf->generate($invoice), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', InvoicePdfGenerator::fileName($invoice)),
        ]);
    }

    /**
     * Body {email, consent}: sends the PDF. Only with the customer's consent, stored
     * with the invoice when it was issued or now (consent: true, the window's box).
     */
    #[Route('/invoices/{id}/email', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function email(int $id, Request $request): JsonResponse
    {
        if (!$this->signedIn($request)) {
            return $this->unauthorized();
        }
        $invoice = $this->invoices->find($id);
        if ($invoice === null) {
            return $this->notFound();
        }
        $body = json_decode((string) $request->getContent(), true);
        $body = \is_array($body) ? $body : [];
        if ($invoice['email_consent_at'] === null) {
            if (($body['consent'] ?? false) !== true) {
                return new JsonResponse(['error' => self::NO_CONSENT_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->invoices->recordEmailConsent($id, new \DateTimeImmutable());
        }

        try {
            $this->mailer->sendInvoice(
                \is_string($body['email'] ?? null) ? $body['email'] : '',
                $invoice['number'],
                (string) ($invoice['issuer']['name'] ?? ''),
                $this->pdf->generate($invoice),
                InvoicePdfGenerator::fileName($invoice),
            );
        } catch (TicketMailException $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->status);
        }

        return new JsonResponse(['data' => ['sent' => true]]);
    }

    /** @param callable(): array<string, mixed> $read */
    private function answer(callable $read): JsonResponse
    {
        try {
            return new JsonResponse(['data' => $read()]);
        } catch (InvoiceException $e) {
            return $this->error($e);
        }
    }

    private function error(InvoiceException $e): JsonResponse
    {
        return new JsonResponse(['error' => $e->getMessage()] + $e->details, $e->status);
    }

    private function signedIn(Request $request): bool
    {
        return $request->getSession()->get('user_id') !== null;
    }

    /** The till asking (its NIF and address issue the invoice); 0 = the sale's till. */
    private function terminalId(Request $request): int
    {
        $terminal = $request->getSession()->get('terminal_id');

        return is_numeric($terminal) ? (int) $terminal : 0;
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['error' => 'No se encuentra la factura'], Response::HTTP_NOT_FOUND);
    }
}
