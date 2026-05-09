<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\TicketController;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\TicketData;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketLine;
use VenPosTPV\Service\Print\TicketPdfGenerator;
use VenPosTPV\Service\Print\TicketVerificationService;

/**
 * @group integration
 */
class TicketControllerTest extends TestCase
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

    private function createTicketData(): TicketData
    {
        return new TicketData(
            orderId: 42,
            reference: 'ORD-042',
            storeName: 'Mi Tienda',
            storeAddress: 'Calle Falsa 123',
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

        $controller = $this->createController(ticketDataResolver: $resolver, pdfGenerator: $pdfGenerator);

        $_SESSION['user_id'] = 1;
        $response = $controller->pdf(42);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function testPdfDownloadReturns404ForMissingOrder(): void
    {
        $resolver = $this->createMock(TicketDataResolver::class);
        $resolver->method('resolve')->willReturn(null);

        $controller = $this->createController(ticketDataResolver: $resolver);

        $_SESSION['user_id'] = 1;
        $response = $controller->pdf(999);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testReprintReturns200ForExistingOrder(): void
    {
        $printerService = $this->createMock(PrinterService::class);
        $printerService->method('reprintTicket')->willReturn(['printed' => true]);

        $controller = $this->createController(printerService: $printerService);

        $_SESSION['user_id'] = 1;
        $_SESSION['terminal_id'] = 3;

        $request = new Request([], [], [], [], [], [], json_encode(['ps_order_id' => 42]));
        $response = $controller->reprint($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertTrue($data['data']['printed']);
    }

    public function testReprintReturns404ForMissingOrder(): void
    {
        $printerService = $this->createMock(PrinterService::class);
        $printerService->method('reprintTicket')->willThrowException(new \RuntimeException('Order not found'));

        $controller = $this->createController(printerService: $printerService);

        $_SESSION['user_id'] = 1;
        $_SESSION['terminal_id'] = 3;

        $request = new Request([], [], [], [], [], [], json_encode(['ps_order_id' => 999]));
        $response = $controller->reprint($request);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testVerifyReturns200ForValidToken(): void
    {
        $verificationService = $this->createMock(TicketVerificationService::class);
        $verificationService->method('verifyToken')->willReturn([
            'order_id' => 42,
            'reference' => 'ORD-042',
            'total' => 99.99,
            'iat' => time(),
        ]);

        $controller = $this->createController(verificationService: $verificationService);

        $request = new Request(['token' => 'valid-jwt-token']);
        $response = $controller->verify($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(42, $data['data']['order_id']);
        self::assertSame('ORD-042', $data['data']['reference']);
    }

    public function testVerifyReturns401ForInvalidToken(): void
    {
        $verificationService = $this->createMock(TicketVerificationService::class);
        $verificationService->method('verifyToken')->willThrowException(new \RuntimeException('Invalid token'));

        $controller = $this->createController(verificationService: $verificationService);

        $request = new Request(['token' => 'bad-token']);
        $response = $controller->verify($request);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testVerifyReturns400ForMissingToken(): void
    {
        $controller = $this->createController();

        $request = new Request();
        $response = $controller->verify($request);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testReprintWithGiftModePassesGiftModeToPrinterService(): void
    {
        $printerService = $this->createMock(PrinterService::class);
        $printerService->expects($this->once())
            ->method('reprintTicket')
            ->with(42, 3, true)
            ->willReturn(['printed' => true]);

        $controller = $this->createController(printerService: $printerService);

        $_SESSION['user_id'] = 1;
        $_SESSION['terminal_id'] = 3;

        $request = new Request([], [], [], [], [], [], json_encode(['ps_order_id' => 42, 'gift_mode' => true]));
        $response = $controller->reprint($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testReprintWithoutGiftModeDefaultsToFalse(): void
    {
        $printerService = $this->createMock(PrinterService::class);
        $printerService->expects($this->once())
            ->method('reprintTicket')
            ->with(42, 3, false)
            ->willReturn(['printed' => true]);

        $controller = $this->createController(printerService: $printerService);

        $_SESSION['user_id'] = 1;
        $_SESSION['terminal_id'] = 3;

        $request = new Request([], [], [], [], [], [], json_encode(['ps_order_id' => 42]));
        $response = $controller->reprint($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testPdfWithGiftModePassesGiftModeToPdfGenerator(): void
    {
        $ticketData = $this->createTicketData();

        $resolver = $this->createMock(TicketDataResolver::class);
        $resolver->method('resolve')->with(42)->willReturn($ticketData);

        $pdfGenerator = $this->createMock(TicketPdfGenerator::class);
        $pdfGenerator->expects($this->once())
            ->method('generate')
            ->with($ticketData, true)
            ->willReturn('%PDF-1.4 test');

        $controller = $this->createController(ticketDataResolver: $resolver, pdfGenerator: $pdfGenerator);

        $_SESSION['user_id'] = 1;
        $request = new Request(['gift_mode' => '1']);
        $response = $controller->pdf($request, 42);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testPdfWithoutGiftModeDefaultsToFalse(): void
    {
        $ticketData = $this->createTicketData();

        $resolver = $this->createMock(TicketDataResolver::class);
        $resolver->method('resolve')->with(42)->willReturn($ticketData);

        $pdfGenerator = $this->createMock(TicketPdfGenerator::class);
        $pdfGenerator->expects($this->once())
            ->method('generate')
            ->with($ticketData, false)
            ->willReturn('%PDF-1.4 test');

        $controller = $this->createController(ticketDataResolver: $resolver, pdfGenerator: $pdfGenerator);

        $_SESSION['user_id'] = 1;
        $request = new Request();
        $response = $controller->pdf($request, 42);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }
}
