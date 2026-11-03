<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\TicketController;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\TicketData;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketLine;
use VenPosTPV\Service\Print\TicketPdfGenerator;
use VenPosTPV\Service\Print\TicketVerificationService;
use VenPosTPV\Tests\Support\SessionRequest;

/**
 * @group integration
 */
class TicketControllerTest extends TestCase
{
    private function createTicketData(): TicketData
    {
        return new TicketData(
            orderId: 42,
            reference: 'ORD-042',
            storeName: 'Mi Tienda',
            storeAddress: 'Calle Falsa 123',
            ticketHeader: 'Gracias por su compra',
            ticketFooter: 'Vuelva pronto',
            ticketLogo: null,
            ticketWidthPx: 576,
            date: new \DateTimeImmutable('2024-01-15 14:30:00'),
            cashierName: 'Juan',
            lines: [new TicketLine('Café', 1, 1.5, 1.5)],
            subtotal: 1.5,
            tax: 0.1,
            total: 1.6,
            paymentMethod: 'cash',
            amountTendered: 2.0,
            changeDue: 0.4,
            verificationUrl: 'https://example.com/verify?token=abc',
        );
    }

    private function createController(
        ?PrinterService $printerService = null,
        ?TicketDataResolver $ticketDataResolver = null,
        ?TicketPdfGenerator $pdfGenerator = null,
        ?TicketVerificationService $verificationService = null,
    ): TicketController {
        return new TicketController(
            $printerService ?? $this->createMock(PrinterService::class),
            $ticketDataResolver ?? $this->createMock(TicketDataResolver::class),
            $pdfGenerator ?? $this->createMock(TicketPdfGenerator::class),
            $verificationService ?? $this->createMock(TicketVerificationService::class),
        );
    }

    public function testPdfDownloadReturns200ForExistingOrder(): void
    {
        $ticketData = $this->createTicketData();
        $resolver = $this->createMock(TicketDataResolver::class);
        $resolver->method('resolve')->with(42)->willReturn($ticketData);
        $pdfGenerator = $this->createMock(TicketPdfGenerator::class);
        $pdfGenerator->method('generate')->willReturn('%PDF-1.4 test');

        $response = $this->createController(ticketDataResolver: $resolver, pdfGenerator: $pdfGenerator)->pdf(
            SessionRequest::create([], ['user_id' => 1]), 42
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function testPdfDownloadReturns404ForMissingOrder(): void
    {
        $resolver = $this->createMock(TicketDataResolver::class);
        $resolver->method('resolve')->willReturn(null);

        $response = $this->createController(ticketDataResolver: $resolver)->pdf(SessionRequest::create([], ['user_id' => 1]), 999);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testReprintReturns200ForExistingOrder(): void
    {
        $printerService = $this->createMock(PrinterService::class);
        $printerService->method('reprintTicket')->willReturn(['printed' => true]);

        $response = $this->createController(printerService: $printerService)->reprint(
            SessionRequest::json(['ps_order_id' => 42], ['user_id' => 1, 'terminal_id' => 3])
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertTrue($data['data']['printed']);
    }

    public function testReprintReturns404ForMissingOrder(): void
    {
        $printerService = $this->createMock(PrinterService::class);
        $printerService->method('reprintTicket')->willThrowException(new \RuntimeException('Order not found'));

        $response = $this->createController(printerService: $printerService)->reprint(
            SessionRequest::json(['ps_order_id' => 999], ['user_id' => 1, 'terminal_id' => 3])
        );

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testReprintByLocalOrderIdPrintsTheSaleEvenBeforeItIsUploaded(): void
    {
        $printerService = $this->createMock(PrinterService::class);
        $printerService->expects($this->once())->method('reprintTicket')
            ->with(TicketDataResolver::saleKey(7), 3, true)->willReturn(['printed' => true]);

        $response = $this->createController(printerService: $printerService)->reprint(
            SessionRequest::json(['order_id' => 7, 'gift_mode' => true], ['user_id' => 1, 'terminal_id' => 3])
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertTrue(json_decode((string) $response->getContent(), true)['data']['printed']);
    }

    public function testReprintWithoutAnyOrderKeepsItsPrevious422(): void
    {
        $response = $this->createController()->reprint(SessionRequest::json([], ['user_id' => 1, 'terminal_id' => 3]));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame(['error' => 'Missing ps_order_id'], json_decode((string) $response->getContent(), true));
    }

    public function testSalePdfResolvesTheSaleByItsLocalKey(): void
    {
        $resolver = $this->createMock(TicketDataResolver::class);
        $resolver->expects($this->once())->method('resolve')->with(TicketDataResolver::saleKey(7))->willReturn($this->createTicketData());
        $pdfGenerator = $this->createMock(TicketPdfGenerator::class);
        $pdfGenerator->method('generate')->willReturn('%PDF-1.4 test');

        $response = $this->createController(ticketDataResolver: $resolver, pdfGenerator: $pdfGenerator)->salePdf(SessionRequest::create([], ['user_id' => 1]), 7);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('attachment; filename="ticket-ORD-042.pdf"', $response->headers->get('Content-Disposition'));
    }

    public function testSalePdfRequiresASession(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->createController()->salePdf(SessionRequest::create(), 7)->getStatusCode());
    }

    public function testVerifyReturns200ForValidToken(): void
    {
        $verificationService = $this->createMock(TicketVerificationService::class);
        $verificationService->method('verifyToken')->willReturn([
            'order_id' => 42, 'reference' => 'ORD-042', 'total' => 99.99, 'iat' => time(),
        ]);

        $response = $this->createController(verificationService: $verificationService)->verify(SessionRequest::create(['token' => 'valid-jwt-token']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(42, $data['data']['order_id']);
        self::assertSame('ORD-042', $data['data']['reference']);
    }

    public function testVerifyReturns401ForInvalidToken(): void
    {
        $verificationService = $this->createMock(TicketVerificationService::class);
        $verificationService->method('verifyToken')->willThrowException(new \RuntimeException('Invalid token'));

        $response = $this->createController(verificationService: $verificationService)->verify(SessionRequest::create(['token' => 'bad-token']));
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testVerifyReturns400ForMissingToken(): void
    {
        $response = $this->createController()->verify(SessionRequest::create());
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testReprintWithGiftModePassesGiftModeToPrinterService(): void
    {
        $printerService = $this->createMock(PrinterService::class);
        $printerService->expects($this->once())->method('reprintTicket')->with(42, 3, true)->willReturn(['printed' => true]);

        $response = $this->createController(printerService: $printerService)->reprint(
            SessionRequest::json(['ps_order_id' => 42, 'gift_mode' => true], ['user_id' => 1, 'terminal_id' => 3])
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testReprintWithoutGiftModeDefaultsToFalse(): void
    {
        $printerService = $this->createMock(PrinterService::class);
        $printerService->expects($this->once())->method('reprintTicket')->with(42, 3, false)->willReturn(['printed' => true]);

        $response = $this->createController(printerService: $printerService)->reprint(
            SessionRequest::json(['ps_order_id' => 42], ['user_id' => 1, 'terminal_id' => 3])
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testPdfWithGiftModePassesGiftModeToPdfGenerator(): void
    {
        $ticketData = $this->createTicketData();
        $resolver = $this->createMock(TicketDataResolver::class);
        $resolver->method('resolve')->with(42)->willReturn($ticketData);
        $pdfGenerator = $this->createMock(TicketPdfGenerator::class);
        $pdfGenerator->expects($this->once())->method('generate')->with($ticketData, true)->willReturn('%PDF-1.4 test');

        $response = $this->createController(ticketDataResolver: $resolver, pdfGenerator: $pdfGenerator)->pdf(
            SessionRequest::create(['gift_mode' => '1'], ['user_id' => 1]), 42
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testPdfWithoutGiftModeDefaultsToFalse(): void
    {
        $ticketData = $this->createTicketData();
        $resolver = $this->createMock(TicketDataResolver::class);
        $resolver->method('resolve')->with(42)->willReturn($ticketData);
        $pdfGenerator = $this->createMock(TicketPdfGenerator::class);
        $pdfGenerator->expects($this->once())->method('generate')->with($ticketData, false)->willReturn('%PDF-1.4 test');

        $response = $this->createController(ticketDataResolver: $resolver, pdfGenerator: $pdfGenerator)->pdf(
            SessionRequest::create([], ['user_id' => 1]), 42
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }
}
