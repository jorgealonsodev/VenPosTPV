<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Controller\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use VenPosTPV\Controller\Api\CustomerController;
use VenPosTPV\Controller\Api\InvoiceController;
use VenPosTPV\Controller\Api\TerminalController;
use VenPosTPV\Service\Customer\CustomerDirectory;
use VenPosTPV\Service\Invoice\InvoiceIssuer;
use VenPosTPV\Service\Invoice\InvoicePdfGenerator;
use VenPosTPV\Service\Invoice\InvoiceRepository;
use VenPosTPV\Service\Mail\MailSettings;
use VenPosTPV\Service\Mail\MailSettingsStore;
use VenPosTPV\Service\Mail\TicketMailer;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\SystemPrintQueue;
use VenPosTPV\Service\Print\TicketPdfGenerator;
use VenPosTPV\Tests\Integration\Service\Invoice\InvoiceFixtures;
use VenPosTPV\Tests\Support\SessionRequest;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * docs/prd/factura-completa.md F10 for phase 1: PUT /api/customers/{id}, POST
 * /api/invoices/from-sale/{orderId}, the A4 PDF and the email with consent
 * (criteria 2-4, 9, 11, 12), through the controllers.
 */
final class InvoiceControllerMySqlIntegrationTest extends TestCase
{
    private const CASHIER = ['user_id' => 1, 'role' => 'cashier', 'shop_id' => 1, 'terminal_id' => InvoiceFixtures::TILL];
    private const ADMIN = ['user_id' => 1, 'role' => 'admin', 'shop_id' => 1];
    private const NEW_TILL = 95102;

    private ?Connection $connection = null;
    private InvoiceFixtures $fixtures;

    /** @var list<Email> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->fixtures = new InvoiceFixtures($this->connection);
        $this->fixtures->reset();
        $this->connection->executeStatement('DELETE FROM terminal WHERE id = ?', [self::NEW_TILL]);
        $this->fixtures->till();
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->fixtures->reset();
            $this->connection->executeStatement('DELETE FROM terminal WHERE id = ? OR name = ?', [self::NEW_TILL, 'Caja nueva F']);
            $this->connection->close();
        }
    }

    public function record(Email $email): void
    {
        $this->sent[] = $email;
    }

    private function controller(): InvoiceController
    {
        \assert($this->connection !== null);
        $store = $this->createMock(MailSettingsStore::class);
        $store->method('load')->willReturn(MailSettings::fromArray([
            'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'u', 'from_email' => 'tienda@example.com', 'from_name' => 'Tienda',
        ], 'pw'));
        $test = $this;
        $factory = static fn (string $dsn): AbstractTransport => new class ($test) extends AbstractTransport {
            public function __construct(private readonly InvoiceControllerMySqlIntegrationTest $test)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                $original = $message->getOriginalMessage();
                \assert($original instanceof Email);
                $this->test->record($original);
            }

            public function __toString(): string
            {
                return 'test://';
            }
        };
        $mailer = new TicketMailer($store, $this->createMock(TicketDataResolver::class), $this->createMock(TicketPdfGenerator::class), $factory);

        return new InvoiceController(
            new InvoiceIssuer($this->connection),
            new InvoiceRepository($this->connection),
            new InvoicePdfGenerator(new Environment(new FilesystemLoader(\dirname(__DIR__, 4) . '/templates'))),
            $mailer,
        );
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $body */
    private function jsonRequest(array $body, array $session = self::CASHIER): Request
    {
        $request = SessionRequest::json($body, $session);
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    private function ticket(): int
    {
        return $this->fixtures->sale('A26000123', '2026-11-03 18:30:00', [['name' => 'Gafas de natación', 'qty' => 1, 'price' => 12.10, 'price_net' => 10.00]]);
    }

    // F1 + criterion 2 (the NIF check is the same for an invoice at sale time).
    public function testTheCashierFillsTheCustomersTaxDataAndAnInvalidNifIsRefused(): void
    {
        \assert($this->connection !== null);
        $customerId = $this->fixtures->customer('editable', ['tax_name' => null, 'tax_id' => null, 'address' => null]);
        $customers = new CustomerController(new CustomerDirectory($this->connection));

        $invalid = $customers->update($customerId, $this->jsonRequest(['tax_id' => 'B12345675']));
        self::assertSame([422, 'El NIF no es válido'], [$invalid->getStatusCode(), $this->json($invalid)['error']]);

        $saved = $customers->update($customerId, $this->jsonRequest(['tax_name' => 'Deportes Ejemplo SL', 'tax_id' => 'B12345674', 'address' => 'Calle Mayor 1']));
        self::assertSame(Response::HTTP_OK, $saved->getStatusCode());
        $data = $this->json($saved)['data'];
        self::assertSame(['Deportes Ejemplo SL', 'B12345674', true], [$data['tax_name'], $data['tax_id'], $data['tax_complete']]);

        self::assertSame(401, $customers->update($customerId, $this->jsonRequest([], []))->getStatusCode());
        self::assertSame(404, $customers->update(987654321, $this->jsonRequest(['city' => 'León']))->getStatusCode());
        self::assertSame(415, $customers->update($customerId, SessionRequest::json(['city' => 'León'], self::CASHIER))->getStatusCode(), 'JSON only');
    }

    // Criteria 3 and 4 through the API.
    public function testATicketIsRedeemedOnceThroughTheApi(): void
    {
        \assert($this->connection !== null);
        $orderId = $this->ticket();
        $customerId = $this->fixtures->customer();
        $controller = $this->controller();

        $preview = $controller->preview($orderId, SessionRequest::create(['customer_id' => (string) $customerId], self::CASHIER));
        self::assertSame(200, $preview->getStatusCode());
        self::assertSame('F26000001', $this->json($preview)['data']['next_number']);

        $created = $controller->redeem($orderId, SessionRequest::json(['customer_id' => $customerId, 'email_consent' => true, 'request_id' => 'r-1'], self::CASHIER));
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode());
        $invoice = $this->json($created)['data'];
        self::assertSame(['F3', 'A26000123', '2026-11-03', []], [$invoice['invoice_type'], $invoice['replaced_reference'], $invoice['operation_date'], $invoice['warnings']]);
        self::assertNotNull($invoice['email_consent_at']);

        $replay = $controller->redeem($orderId, SessionRequest::json(['customer_id' => $customerId, 'request_id' => 'r-1'], self::CASHIER));
        self::assertSame([200, $invoice['number']], [$replay->getStatusCode(), $this->json($replay)['data']['number']], 'same request, same invoice');

        $again = $controller->redeem($orderId, SessionRequest::json(['customer_id' => $customerId, 'request_id' => 'r-2'], self::CASHIER));
        self::assertSame(409, $again->getStatusCode());
        self::assertSame(['error' => 'Este ticket ya se canjeó por la factura ' . $invoice['number'], 'invoice_number' => $invoice['number'], 'invoice_id' => $invoice['id']], $this->json($again));
        self::assertSame(1, $this->fixtures->lastInvoiceNumber(), 'no number consumed');

        $bySale = $this->json($controller->bySale($orderId, SessionRequest::create([], self::CASHIER)))['data'];
        self::assertSame($invoice['number'], $bySale['number'], 'the sale detail shows «Sustituido por»');
        self::assertNull($this->json($controller->bySale(987654321, SessionRequest::create([], self::CASHIER)))['data']);
    }

    public function testMissingTaxDataAnswerWhichFieldsAreMissing(): void
    {
        $orderId = $this->ticket();
        $customerId = $this->fixtures->customer('incomplete', ['postcode' => null]);

        $response = $this->controller()->redeem($orderId, SessionRequest::json(['customer_id' => $customerId], self::CASHIER));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => 'Faltan datos fiscales del cliente: código postal', 'missing' => ['postcode'], 'customer_id' => $customerId], $this->json($response));
    }

    // Criterion 9: A4, same data as the invoice table.
    public function testThePdfIsTheA4InvoiceAndACopyIsIdentical(): void
    {
        $orderId = $this->ticket();
        $controller = $this->controller();
        $invoice = $this->json($controller->redeem($orderId, SessionRequest::json(['customer_id' => $this->fixtures->customer()], self::CASHIER)))['data'];

        $response = $controller->pdf($invoice['id'], SessionRequest::create([], self::CASHIER));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename="factura-F26000001.pdf"', $response->headers->get('Content-Disposition'));
        self::assertMatchesRegularExpression('#/MediaBox \[0\.0+ 0\.0+ 595\.280* 841\.890*\]#', (string) $response->getContent());
        self::assertSame(401, $controller->pdf($invoice['id'], SessionRequest::create())->getStatusCode());
        self::assertSame(404, $controller->pdf(987654321, SessionRequest::create([], self::CASHIER))->getStatusCode());
    }

    // Criterion 11 and art. 9.2.
    public function testTheInvoiceIsEmailedOnlyWithTheCustomersConsent(): void
    {
        \assert($this->connection !== null);
        $orderId = $this->ticket();
        $controller = $this->controller();
        $invoice = $this->json($controller->redeem($orderId, SessionRequest::json(['customer_id' => $this->fixtures->customer()], self::CASHIER)))['data'];
        self::assertNull($invoice['email_consent_at']);

        $refused = $controller->email($invoice['id'], SessionRequest::json(['email' => 'cliente@example.com'], self::CASHIER));
        self::assertSame([422, 'El cliente no ha aceptado recibir la factura por email'], [$refused->getStatusCode(), $this->json($refused)['error']]);
        self::assertSame([], $this->sent);

        $sent = $controller->email($invoice['id'], SessionRequest::json(['email' => 'cliente@example.com', 'consent' => true], self::CASHIER));
        self::assertSame([200, ['sent' => true]], [$sent->getStatusCode(), $this->json($sent)['data']]);
        self::assertNotNull($this->connection->fetchOne('SELECT email_consent_at FROM invoice WHERE id = ?', [$invoice['id']]), 'the consent is stored with the invoice');
        self::assertCount(1, $this->sent);
        self::assertSame('Tu factura F26000001 - Club Natación Ejemplo', $this->sent[0]->getSubject());
        self::assertSame('cliente@example.com', $this->sent[0]->getTo()[0]->getAddress());
        $attachment = $this->sent[0]->getAttachments()[0];
        \assert($attachment instanceof DataPart);
        self::assertSame('factura-F26000001.pdf', $attachment->getFilename());
        self::assertStringStartsWith('%PDF-', $attachment->getBody());

        $again = $controller->email($invoice['id'], SessionRequest::json(['email' => 'otro@example.com'], self::CASHIER));
        self::assertSame(200, $again->getStatusCode(), 'the stored consent is enough');
        self::assertCount(2, $this->sent);
    }

    // Criterion 12.
    public function testATillCannotTakeTheLetterF(): void
    {
        \assert($this->connection !== null);
        $terminals = new TerminalController($this->connection, $this->createMock(SystemPrintQueue::class));

        $update = $terminals->update(InvoiceFixtures::TILL, SessionRequest::json(['ticket_letter' => 'f'], self::ADMIN));
        self::assertSame([422, 'La letra F está reservada para las facturas'], [$update->getStatusCode(), $this->json($update)['error']]);
        $create = $terminals->create($this->jsonRequest(['name' => 'Caja nueva F', 'ticket_letter' => 'F'], self::ADMIN));
        self::assertSame([422, 'La letra F está reservada para las facturas'], [$create->getStatusCode(), $this->json($create)['error']]);
        self::assertNull($this->connection->fetchOne('SELECT ticket_letter FROM terminal WHERE id = ?', [InvoiceFixtures::TILL]));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM terminal WHERE ticket_letter = 'F'"));
    }

    public function testEveryEndpointNeedsASession(): void
    {
        $controller = $this->controller();
        $guest = SessionRequest::create();

        self::assertSame(
            [401, 401, 401, 401, 401],
            [
                $controller->redeem(1, $guest)->getStatusCode(),
                $controller->preview(1, $guest)->getStatusCode(),
                $controller->bySale(1, $guest)->getStatusCode(),
                $controller->show(1, $guest)->getStatusCode(),
                $controller->email(1, $guest)->getStatusCode(),
            ]
        );
    }
}
