<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketVerificationService;

final class TicketDataResolverTest extends TestCase
{
    private function createService(
        ?Connection $conn = null,
        ?TicketVerificationService $verificationService = null,
    ): TicketDataResolver {
        return new TicketDataResolver(
            $conn ?? $this->createMock(Connection::class),
            $verificationService ?? $this->createMock(TicketVerificationService::class),
            'Mi Tienda',
            'Calle Falsa 123',
        );
    }

    public function testResolveReturnsNullWhenOrderNotFound(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(false);

        $service = $this->createService(conn: $conn);
        $result = $service->resolve(999);

        self::assertNull($result);
    }

    public function testResolveAssemblesTicketDataFromDbRows(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')
            ->willReturnMap([
                [
                    'SELECT id, ps_order_id, terminal_id, user_id, payment_breakdown, created_at FROM order_meta WHERE ps_order_id = ?',
                    [42],
                    [
                        'ps_order_id' => 42,
                        'terminal_id' => 7,
                        'user_id' => 5,
                        'payment_breakdown' => json_encode([
                            'reference' => 'ORD-042',
                            'total' => 99.99,
                            'payment_method' => 'cash',
                            'amount_tendered' => 100.0,
                            'change_due' => 0.01,
                            'subtotal' => 90.0,
                            'tax' => 9.99,
                            'lines' => [
                                ['name' => 'Café', 'qty' => 2, 'price' => 45.0, 'line_total' => 90.0],
                            ],
                        ]),
                        'created_at' => '2024-01-15 14:30:00',
                    ],
                ],
                [
                    'SELECT full_name, name FROM user WHERE id = ?',
                    [5],
                    ['full_name' => null, 'name' => 'Juan'],
                ],
                [
                    'SELECT store_name, store_address, legal_name, tax_id, ticket_header, ticket_footer, ticket_logo, ticket_width_px FROM terminal WHERE id = ?',
                    [7],
                    [
                        'store_name' => 'Moda Centro',
                        'store_address' => 'Avenida Real 42',
                        'legal_name' => 'Mi Tienda Moda SL',
                        'tax_id' => 'B12345674',
                        'ticket_header' => 'Horario: L-V 09:00-20:00',
                        'ticket_footer' => 'Vuelva pronto',
                        'ticket_logo' => '/uploads/tickets/logo.png',
                        'ticket_width_px' => 576,
                    ],
                ],
            ]);

        $verificationService = $this->createMock(TicketVerificationService::class);
        $verificationService->method('generateToken')->willReturn('test-token');
        $verificationService->method('buildVerificationUrl')->willReturn('https://example.com/verify?token=test-token');

        $service = $this->createService(conn: $conn, verificationService: $verificationService);
        $result = $service->resolve(42);

        self::assertNotNull($result);
        self::assertSame(42, $result->orderId);
        self::assertSame('ORD-042', $result->reference);
        self::assertSame('Moda Centro', $result->storeName, 'the store name set in Settings replaces the .env default');
        self::assertSame('Avenida Real 42', $result->storeAddress);
        self::assertSame('Mi Tienda Moda SL', $result->legalName);
        self::assertSame('B12345674', $result->taxId);
        self::assertSame('Horario: L-V 09:00-20:00', $result->ticketHeader);
        self::assertSame('Vuelva pronto', $result->ticketFooter);
        self::assertSame('/uploads/tickets/logo.png', $result->ticketLogo);
        self::assertSame(576, $result->ticketWidthPx);
        self::assertSame('Juan', $result->cashierName);
        self::assertSame(99.99, $result->total);
        self::assertSame(90.0, $result->subtotal);
        self::assertSame(9.99, $result->tax);
        self::assertCount(1, $result->lines);
        self::assertSame('Café', $result->lines[0]->name);
        self::assertSame(2, $result->lines[0]->quantity);
        self::assertSame(45.0, $result->lines[0]->unitPrice);
        self::assertSame(90.0, $result->lines[0]->lineTotal);
    }

    public function testResolveUsesDefaultCashierWhenUserNotFound(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')
            ->willReturnMap([
                [
                    'SELECT id, ps_order_id, terminal_id, user_id, payment_breakdown, created_at FROM order_meta WHERE ps_order_id = ?',
                    [42],
                    [
                        'ps_order_id' => 42,
                        'terminal_id' => 7,
                        'user_id' => 99,
                        'payment_breakdown' => json_encode([
                            'reference' => 'ORD-042',
                            'total' => 10.0,
                            'payment_method' => 'card',
                            'amount_tendered' => 10.0,
                            'change_due' => 0.0,
                            'lines' => [],
                        ]),
                        'created_at' => '2024-01-15 14:30:00',
                    ],
                ],
                [
                    'SELECT full_name, name FROM user WHERE id = ?',
                    [99],
                    false,
                ],
                [
                    'SELECT store_name, store_address, legal_name, tax_id, ticket_header, ticket_footer, ticket_logo, ticket_width_px FROM terminal WHERE id = ?',
                    [7],
                    false,
                ],
            ]);

        $verificationService = $this->createMock(TicketVerificationService::class);
        $verificationService->method('generateToken')->willReturn('token');
        $verificationService->method('buildVerificationUrl')->willReturn('https://example.com/verify');

        $service = $this->createService(conn: $conn, verificationService: $verificationService);
        $result = $service->resolve(42);

        self::assertNotNull($result);
        self::assertSame('Cajero', $result->cashierName);
    }

    public function testTicketCodeComesFromTheTerminalAndDefaultsToBarcode(): void
    {
        $order = ['ps_order_id' => 42, 'terminal_id' => 7, 'user_id' => 5, 'payment_breakdown' => json_encode(['reference' => 'R1', 'total' => 1.0, 'lines' => []]), 'created_at' => '2024-01-15 14:30:00'];
        foreach ([['qr', 'qr'], ['both', 'both'], [false, 'barcode'], ['nonsense', 'barcode']] as [$stored, $expected]) {
            $conn = $this->createMock(Connection::class);
            $conn->method('fetchAssociative')->willReturnCallback(fn (string $sql) => str_contains($sql, 'FROM order_meta') ? $order : false);
            $conn->method('fetchOne')->willReturnCallback(fn (string $sql) => str_contains($sql, 'ticket_code') ? $stored : false);
            $verification = $this->createMock(TicketVerificationService::class);
            $verification->method('buildVerificationUrl')->willReturn('http://v');

            $ticket = (new TicketDataResolver($conn, $verification, 'Tienda', ''))->resolve(42);

            $this->assertSame($expected, $ticket->ticketCode);
        }
    }
    public function testStoreNameFallsBackToTheLegalNameThenToTheEnvironmentDefault(): void
    {
        foreach ([[['store_name' => '', 'legal_name' => 'Moda Centro SL'], 'Moda Centro SL'], [['store_name' => null, 'legal_name' => null], 'Mi Tienda']] as [$terminal, $expected]) {
            $conn = $this->createMock(Connection::class);
            $conn->method('fetchAssociative')->willReturnMap([
                ['SELECT id, ps_order_id, terminal_id, user_id, payment_breakdown, created_at FROM order_meta WHERE ps_order_id = ?', [42], ['ps_order_id' => 42, 'terminal_id' => 7, 'user_id' => 5, 'payment_breakdown' => json_encode(['reference' => 'R', 'total' => 1.0, 'lines' => []]), 'created_at' => '2024-01-15 14:30:00']],
                ['SELECT full_name, name FROM user WHERE id = ?', [5], ['full_name' => null, 'name' => 'Juan']],
                ['SELECT store_name, store_address, legal_name, tax_id, ticket_header, ticket_footer, ticket_logo, ticket_width_px FROM terminal WHERE id = ?', [7], $terminal + ['store_address' => '', 'tax_id' => null, 'ticket_header' => null, 'ticket_footer' => null, 'ticket_logo' => null, 'ticket_width_px' => null]],
            ]);
            $verification = $this->createMock(TicketVerificationService::class);
            $verification->method('generateToken')->willReturn('t');
            $verification->method('buildVerificationUrl')->willReturn('https://example.com/v');

            self::assertSame($expected, $this->createService(conn: $conn, verificationService: $verification)->resolve(42)?->storeName);
        }
    }

    /** PRD S5: a PrestaShop reference other than the ticket code is shown as "Pedido web". */
    public function testADifferentPrestaShopReferenceBecomesTheWebOrderReference(): void
    {
        foreach ([[-15, 'XKQWERTYU', 'XKQWERTYU'], [-15, null, null], [-15, '', null], [-15, 'A26000015', null], [42, 'XKQWERTYU', 'XKQWERTYU']] as [$key, $stored, $expected]) {
            $order = ['ps_order_id' => $key > 0 ? $key : null, 'terminal_id' => 7, 'user_id' => 5, 'payment_breakdown' => json_encode(['reference' => 'A26000015', 'total' => 1.0, 'lines' => []]), 'created_at' => '2024-01-15 14:30:00'];
            $conn = $this->createMock(Connection::class);
            $conn->method('fetchAssociative')->willReturnCallback(fn (string $sql) => str_contains($sql, 'FROM order_meta') ? $order : false);
            $conn->method('fetchOne')->willReturnCallback(fn (string $sql, array $params = []) => str_contains($sql, 'ps_reference FROM order_meta')
                ? ($params === [abs($key)] ? $stored : 'WRONGKEY0')
                : false);
            $verification = $this->createMock(TicketVerificationService::class);
            $verification->method('buildVerificationUrl')->willReturn('http://v');

            $ticket = (new TicketDataResolver($conn, $verification, 'Tienda', ''))->resolve($key);

            $this->assertSame($expected, $ticket->webOrderReference, sprintf('key %d, stored %s', $key, var_export($stored, true)));
        }
    }

    public function testTheVoucherRestOfTheSaleReachesTheTicketAndReprints(): void
    {
        $cases = [
            [['issued_coupon_id' => 31, 'code' => 'ABCD1234-2', 'amount' => 14, 'expires_at' => '2027-06-30 23:59:59'], ['code' => 'ABCD1234-2', 'amount' => 14.0]],
            [null, null],
            [['code' => '', 'amount' => 14], null],
            [['code' => 'ABCD1234-2', 'amount' => 0], null],
        ];
        foreach ($cases as [$stored, $expected]) {
            $breakdown = ['reference' => 'XKBKNABJK', 'total' => 0.0, 'lines' => []] + ($stored !== null ? ['leftover_voucher' => $stored] : []);
            $order = ['ps_order_id' => 42, 'terminal_id' => 7, 'user_id' => 5, 'payment_breakdown' => json_encode($breakdown), 'created_at' => '2026-11-09 12:00:00'];
            $conn = $this->createMock(Connection::class);
            $conn->method('fetchAssociative')->willReturnCallback(fn (string $sql) => str_contains($sql, 'FROM order_meta') ? $order : false);
            $conn->method('fetchOne')->willReturn(false);
            $verification = $this->createMock(TicketVerificationService::class);
            $verification->method('buildVerificationUrl')->willReturn('http://v');

            $ticket = (new TicketDataResolver($conn, $verification, 'Tienda', ''))->resolve(42);

            $this->assertSame($expected, $ticket->leftoverVoucher, json_encode($stored));
        }
    }

    /** Ajustes → Cajas: the till's margins and logo width reach the ticket; before migration V45 they stay unset. */
    public function testTheTillMarginsAndLogoWidthReachTheTicket(): void
    {
        $order = ['ps_order_id' => 42, 'terminal_id' => 7, 'user_id' => 5, 'payment_breakdown' => json_encode(['reference' => 'R1', 'total' => 1.0, 'lines' => []]), 'created_at' => '2024-01-15 14:30:00'];
        $cases = [
            [['ticket_margin_left_mm' => '3.5', 'ticket_margin_right_mm' => '0.0', 'ticket_logo_width_pct' => '60'], [3.5, 0.0, 60]],
            [['ticket_margin_left_mm' => null, 'ticket_margin_right_mm' => null, 'ticket_logo_width_pct' => null], [null, null, null]],
            [new \RuntimeException("Unknown column 'ticket_margin_left_mm'"), [null, null, null]],
        ];
        foreach ($cases as [$layout, $expected]) {
            $conn = $this->createMock(Connection::class);
            $conn->method('fetchAssociative')->willReturnCallback(function (string $sql, array $params = []) use ($order, $layout): array|false {
                if (str_contains($sql, 'FROM order_meta')) {
                    return $order;
                }
                if (str_contains($sql, 'ticket_margin_left_mm')) {
                    self::assertSame([7], $params, 'the layout is read from the till of the sale');
                    if ($layout instanceof \Throwable) {
                        throw $layout;
                    }

                    return $layout;
                }

                return false;
            });
            $verification = $this->createMock(TicketVerificationService::class);
            $verification->method('buildVerificationUrl')->willReturn('http://v');

            $ticket = (new TicketDataResolver($conn, $verification, 'Tienda', ''))->resolve(42);

            self::assertNotNull($ticket);
            self::assertSame($expected, [$ticket->ticketMarginLeftMm, $ticket->ticketMarginRightMm, $ticket->ticketLogoWidthPct]);
        }
    }

    /** Ajustes → Empleados: the ticket prints the cashier's «Nombre y apellidos», else the user name, else «Cajero». */
    public function testTheTicketShowsTheCashierFullNameAndFallsBackToTheUserName(): void
    {
        $order = ['ps_order_id' => 42, 'terminal_id' => 7, 'user_id' => 5, 'payment_breakdown' => json_encode(['reference' => 'R1', 'total' => 1.0, 'lines' => []]), 'created_at' => '2024-01-15 14:30:00'];
        $cases = [
            [['full_name' => 'Juan Pérez García', 'name' => 'juan'], 'Juan Pérez García'],
            [['full_name' => null, 'name' => 'juan'], 'juan'],
            [['full_name' => '  ', 'name' => 'juan'], 'juan'],
            [false, 'Cajero'],
            // Code deployed before Version00000049: the old query still names the cashier.
            [new InvalidFieldNameException($this->createMock(DriverException::class), null), 'juan'],
        ];
        foreach ($cases as [$user, $expected]) {
            $conn = $this->createMock(Connection::class);
            $conn->method('fetchAssociative')->willReturnCallback(function (string $sql, array $params = []) use ($order, $user): array|false {
                if (str_contains($sql, 'FROM order_meta')) {
                    return $order;
                }
                if ($sql === 'SELECT full_name, name FROM user WHERE id = ?') {
                    self::assertSame([5], $params, 'the cashier of the sale');
                    if ($user instanceof \Throwable) {
                        throw $user;
                    }

                    return $user;
                }
                if ($sql === 'SELECT name FROM user WHERE id = ?') {
                    return ['name' => 'juan'];
                }

                return false;
            });
            $verification = $this->createMock(TicketVerificationService::class);
            $verification->method('buildVerificationUrl')->willReturn('http://v');

            $ticket = (new TicketDataResolver($conn, $verification, 'Tienda', ''))->resolve(42);

            self::assertSame($expected, $ticket?->cashierName, var_export($user instanceof \Throwable ? 'exception' : $user, true));
        }
    }
}
