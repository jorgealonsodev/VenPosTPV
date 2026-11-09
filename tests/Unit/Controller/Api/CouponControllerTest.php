<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use VenPosTPV\Controller\Api\CouponController;
use VenPosTPV\Service\Coupon\CouponSlipService;
use VenPosTPV\Service\Coupon\IssuedCoupon;
use VenPosTPV\Service\Coupon\IssuedCouponStore;
use VenPosTPV\Service\Mail\TicketMailer;
use VenPosTPV\Service\Mail\TicketMailException;
use VenPosTPV\Service\PrestaShop\CouponCreationService;
use VenPosTPV\Service\Print\CouponSlipEscPos;
use VenPosTPV\Service\Print\CouponSlipPdf;
use VenPosTPV\Service\Print\EscPosTicketBuilder;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketPdfGenerator;
use VenPosTPV\Service\PrintAgent\PrintJobQueue;

final class CouponControllerTest extends TestCase
{
    private const CASHIER = ['user_id' => 9, 'role' => 'cashier', 'shop_id' => 7, 'terminal_id' => 3];

    /** @var list<array<int, mixed>> */
    private array $enqueued = [];

    public function testCashierCanCreateCouponFromAuthenticatedSession(): void
    {
        $service = $this->createMock(CouponCreationService::class);
        $service->expects(self::exactly(2))->method('createIssued')->with(self::isType('array'), 7)->willReturn([
            'id' => 42, 'code' => 'TPV-ABC', 'type' => 'percentage', 'value' => 10.0, 'expires_at' => '2030-06-30', 'quantity' => 2, 'quantity_per_user' => 1, 'local_id' => 5, 'printable' => true,
        ]);
        $controller = $this->controller($service);

        $response = $controller->create($this->request(['user_id' => 9, 'role' => 'cashier', 'shop_id' => 7]));

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true)['data'];
        self::assertSame('TPV-ABC', $data['code']);
        self::assertSame(5, $data['local_id']);
        self::assertTrue($data['printable']);
        self::assertSame(201, $controller->create($this->request(['user_id' => 8, 'role' => 'admin', 'shop_id' => 7]))->getStatusCode());
    }

    public function testTheCouponIsRecordedWithTheSessionTillAndCashier(): void
    {
        $service = $this->createMock(CouponCreationService::class);
        $service->expects(self::once())->method('createIssued')->with(self::isType('array'), 7, 3, 9)->willReturn(['id' => 42, 'code' => 'X', 'local_id' => null, 'printable' => false]);

        $response = $this->controller($service)->create($this->request(self::CASHIER));

        self::assertSame(201, $response->getStatusCode());
        self::assertFalse(json_decode((string) $response->getContent(), true)['data']['printable']);
    }

    public function testMissingAuthenticationAndDisallowedRoleDoNotCallService(): void
    {
        $service = $this->createMock(CouponCreationService::class);
        $service->expects(self::never())->method('createIssued');
        $controller = $this->controller($service);

        self::assertSame(401, $controller->create($this->request([]))->getStatusCode());
        self::assertSame(403, $controller->create($this->request(['user_id' => 9, 'role' => 'manager', 'shop_id' => 7]))->getStatusCode());
    }

    public function testPrintPdfAndEmailNeedASessionAndAnAllowedRole(): void
    {
        $controller = $this->controller(coupon: $this->coupon());
        foreach ([[[], 401], [['user_id' => 9, 'role' => 'manager', 'terminal_id' => 3], 403]] as [$session, $status]) {
            self::assertSame($status, $controller->print($this->request($session), 5)->getStatusCode());
            self::assertSame($status, $controller->pdf($this->request($session), 5)->getStatusCode());
            self::assertSame($status, $controller->email($this->request($session, ['email' => 'a@b.es']), 5)->getStatusCode());
        }
        self::assertSame([], $this->enqueued);
    }

    public function testAnUnknownCouponIs404(): void
    {
        $controller = $this->controller(coupon: null);

        self::assertSame(404, $controller->print($this->request(self::CASHIER), 99)->getStatusCode());
        self::assertSame(404, $controller->pdf($this->request(self::CASHIER), 99)->getStatusCode());
        self::assertSame(404, $controller->email($this->request(self::CASHIER, ['email' => 'a@b.es']), 99)->getStatusCode());
        self::assertSame([], $this->enqueued);
    }

    public function testAnAgentTillGetsAPrintJobWithTheCouponSlip(): void
    {
        $response = $this->controller(coupon: $this->coupon(), printerConfig: '{"enabled":true,"type":"agent"}')
            ->print($this->request(self::CASHIER), 5);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['print_status' => 'queued', 'print_job_id' => 70], json_decode((string) $response->getContent(), true)['data']);
        self::assertCount(1, $this->enqueued);
        [$terminalId, $type, $payload, $source, $sourceRef, $userId] = $this->enqueued[0];
        self::assertSame([3, 'ticket', 'coupon', 'ABCD2345', 9], [$terminalId, $type, $source, $sourceRef, $userId]);
        self::assertStringContainsString('{BABCD2345', $payload);
    }

    public function testAnAgentTillIsNeverGuessedWithoutASessionTill(): void
    {
        $session = self::CASHIER;
        unset($session['terminal_id']);
        $response = $this->controller(coupon: $this->coupon(), printerConfig: '{"enabled":true,"type":"agent"}')->print($this->request($session), 5);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->enqueued);
    }

    public function testATillWithoutPrinterIsToldToOpenThePdf(): void
    {
        $response = $this->controller(coupon: $this->coupon(), printerConfig: '{"enabled":false,"type":"pdf"}')->print($this->request(self::CASHIER), 5);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['fallback' => 'pdf'], json_decode((string) $response->getContent(), true)['data']);
    }

    public function testThePdfIsServedInlineWithTheCouponCodeInItsName(): void
    {
        $response = $this->controller(coupon: $this->coupon())->pdf($this->request(self::CASHIER), 5);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('inline; filename="cupon-ABCD2345.pdf"', $response->headers->get('Content-Disposition'));
        self::assertSame('%PDF-fake', $response->getContent());
    }

    public function testTheEmailGoesToTheMailerAndItsRefusalsKeepTheirStatus(): void
    {
        $mailer = $this->createMock(TicketMailer::class);
        $mailer->expects(self::exactly(2))->method('sendCoupon')
            ->with(self::anything(), 'ABCD2345', 'Nada Más', '%PDF-fake')
            ->willReturnCallback(static function (string $to): void {
                if ($to !== 'cliente@example.com') {
                    throw new TicketMailException('El email del cliente no es válido.', 422);
                }
            });
        $controller = $this->controller(coupon: $this->coupon(), mailer: $mailer);

        $sent = $controller->email($this->request(self::CASHIER, ['email' => 'cliente@example.com']), 5);
        self::assertSame(200, $sent->getStatusCode());
        self::assertSame(['sent' => true], json_decode((string) $sent->getContent(), true)['data']);

        $refused = $controller->email($this->request(self::CASHIER, ['email' => 'no-es-email']), 5);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('El email del cliente no es válido.', json_decode((string) $refused->getContent(), true)['error']);
    }

    private function coupon(): IssuedCoupon
    {
        return new IssuedCoupon(5, 42, 'ABCD2345', 'amount', 100.0, new \DateTimeImmutable('2027-06-30'), 1, 1, 3, 9, new \DateTimeImmutable('2026-11-09 10:15:00'));
    }

    private function controller(?CouponCreationService $service = null, ?IssuedCoupon $coupon = null, string $printerConfig = '{"enabled":false}', ?TicketMailer $mailer = null): CouponController
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(static fn (string $sql): mixed => str_contains($sql, 'printer_config') ? $printerConfig : false);
        $connection->method('fetchAssociative')->willReturn(['store_name' => 'Nada Más', 'legal_name' => null, 'tax_id' => null, 'store_address' => '', 'ticket_logo' => null]);
        $queue = $this->createMock(PrintJobQueue::class);
        $queue->method('enqueue')->willReturnCallback(function (...$args): int {
            $this->enqueued[] = $args;

            return 70;
        });
        $printer = new PrinterService(
            $connection,
            $this->createMock(TicketDataResolver::class),
            $this->createMock(EscPosTicketBuilder::class),
            $this->createMock(TicketPdfGenerator::class),
            printJobs: $queue,
        );
        $store = $this->createMock(IssuedCouponStore::class);
        $store->method('find')->willReturn($coupon);
        $pdf = $this->createMock(CouponSlipPdf::class);
        $pdf->method('generate')->willReturn('%PDF-fake');

        return new CouponController(
            $service ?? $this->createMock(CouponCreationService::class),
            new CouponSlipService($connection, $store, $printer, new CouponSlipEscPos(), $pdf, $mailer ?? $this->createMock(TicketMailer::class)),
        );
    }

    /** @param array<string, mixed> $sessionValues @param array<string, mixed>|null $body */
    private function request(array $sessionValues, ?array $body = null): Request
    {
        $session = new Session(new MockArraySessionStorage());
        foreach ($sessionValues as $key => $value) {
            $session->set($key, $value);
        }
        $request = new Request([], [], [], [], [], [], json_encode($body ?? ['discount_type' => 'percentage', 'value' => 10, 'expires_at' => '2030-06-30', 'quantity' => 2, 'quantity_per_user' => 1]));
        $request->setSession($session);
        return $request;
    }
}
