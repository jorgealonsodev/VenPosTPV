<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use PHPUnit\Framework\TestCase;

/**
 * In-memory ReferencedOrderGateway: orders by reference and the locks held.
 */
final class FakeReferencedOrderGateway implements \ReferencedOrderGateway
{
    /** @var array<string, array{order_id: int, reference: string, total_paid: float}> */
    public array $orders = [];
    /** @var array<string, true> */
    public array $heldByOthers = [];
    /** @var list<string> */
    public array $events = [];

    public function findOrderByReference(string $reference): ?array
    {
        $this->events[] = 'find:' . $reference;

        return $this->orders[$reference] ?? null;
    }

    public function acquireLock(string $name): bool
    {
        $this->events[] = 'lock:' . $name;

        return !isset($this->heldByOthers[$name]);
    }

    public function releaseLock(string $name): void
    {
        $this->events[] = 'unlock:' . $name;
    }
}

class ReferencedOrderCreatorTest extends TestCase
{
    private FakeReferencedOrderGateway $gateway;
    private \ReferencedOrderCreator $creator;
    private int $creates = 0;

    protected function setUp(): void
    {
        $this->gateway = new FakeReferencedOrderGateway();
        $this->creator = new \ReferencedOrderCreator($this->gateway);
        $this->creates = 0;
        \TpvOrderReference::release();
    }

    /**
     * Simulates validateOrder: PrestaShop asks the hook for a reference
     * (Order::generateReference) and falls back to 9 random letters.
     */
    private function validateOrder(): array
    {
        ++$this->creates;
        $hooked = \TpvOrderReference::claim('order');
        $reference = $hooked !== '' ? $hooked : 'XKBKNABJK';
        $this->gateway->orders[$reference] = ['order_id' => 100 + $this->creates, 'reference' => $reference, 'total_paid' => 12.5];

        return ['order_id' => 100 + $this->creates, 'reference' => $reference, 'total_paid' => 12.5];
    }

    /**
     * @dataProvider invalidReferences
     */
    public function test_a_reference_not_matching_the_ticket_format_is_rejected_with_422(mixed $reference): void
    {
        self::assertSame(['reference' => 'Must match ^[A-Z]\d{8}$ (e.g. A26000123)'], \ReferencedOrderCreator::validateRequest(['reference' => $reference]));

        try {
            $this->creator->create(['reference' => $reference], fn (): array => $this->validateOrder());
            self::fail('Expected a 422');
        } catch (\ReferencedOrderException $e) {
            self::assertSame(422, $e->getHttpCode());
            self::assertSame(['reference' => 'Must match ^[A-Z]\d{8}$ (e.g. A26000123)'], $e->getDetails());
        }
        self::assertSame(0, $this->creates);
        self::assertSame([], $this->gateway->events);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidReferences(): array
    {
        return [
            'lowercase letter' => ['a26000123'],
            'too short' => ['A2600012'],
            'too long' => ['A260001234'],
            'web reference' => ['XKBKNABJK'],
            'trailing newline' => ["A26000123\n"],
            'empty' => [''],
            'null' => [null],
            'integer' => [26000123],
        ];
    }

    public function test_a_valid_reference_passes_validation(): void
    {
        self::assertSame([], \ReferencedOrderCreator::validateRequest(['reference' => 'A26000123']));
    }

    public function test_without_a_reference_the_order_is_created_exactly_as_before(): void
    {
        self::assertSame([], \ReferencedOrderCreator::validateRequest(['id_shop' => 1]));

        $result = $this->creator->create(['id_shop' => 1], fn (): array => $this->validateOrder());

        // Same response as today: no replayed flag, PrestaShop's own reference, no lock or lookup.
        self::assertSame(['order_id' => 101, 'reference' => 'XKBKNABJK', 'total_paid' => 12.5], $result);
        self::assertSame(1, $this->creates);
        self::assertSame([], $this->gateway->events);
    }

    public function test_a_new_reference_creates_one_order_carrying_that_reference(): void
    {
        $result = $this->creator->create(['reference' => 'A26000123'], fn (): array => $this->validateOrder());

        self::assertSame(['order_id' => 101, 'reference' => 'A26000123', 'total_paid' => 12.5, 'replayed' => false], $result);
        self::assertSame(1, $this->creates);
        self::assertSame(
            ['lock:tpvconnect_order_ref_A26000123', 'find:A26000123', 'unlock:tpvconnect_order_ref_A26000123'],
            $this->gateway->events
        );
        // The pending reference never outlives the request's own order.
        self::assertSame('', \TpvOrderReference::claim('order'));
    }

    public function test_a_retry_with_an_existing_reference_returns_that_order_and_creates_nothing(): void
    {
        $first = $this->creator->create(['reference' => 'A26000123'], fn (): array => $this->validateOrder());
        $retry = $this->creator->create(['reference' => 'A26000123'], fn (): array => $this->validateOrder());

        self::assertSame(1, $this->creates);
        self::assertSame(['order_id' => 101, 'reference' => 'A26000123', 'total_paid' => 12.5, 'replayed' => true], $retry);
        self::assertSame(array_keys($first), array_keys($retry));
    }

    public function test_a_concurrent_request_with_the_same_reference_creates_nothing_and_gets_409(): void
    {
        $this->gateway->heldByOthers['tpvconnect_order_ref_A26000123'] = true;

        try {
            $this->creator->create(['reference' => 'A26000123'], fn (): array => $this->validateOrder());
            self::fail('Expected a 409');
        } catch (\ReferencedOrderException $e) {
            self::assertSame(409, $e->getHttpCode());
        }
        self::assertSame(0, $this->creates);
        self::assertSame(['lock:tpvconnect_order_ref_A26000123'], $this->gateway->events);
    }

    public function test_a_failed_creation_releases_the_lock_and_the_pending_reference(): void
    {
        try {
            $this->creator->create(['reference' => 'A26000123'], function (): array {
                throw new \RuntimeException('validateOrder failed');
            });
            self::fail('Expected the creation error');
        } catch (\RuntimeException $e) {
            self::assertSame('validateOrder failed', $e->getMessage());
        }

        self::assertSame('unlock:tpvconnect_order_ref_A26000123', end($this->gateway->events));
        self::assertSame('', \TpvOrderReference::claim('order'));
    }

    public function test_the_hook_gives_the_pending_reference_once_and_only_for_orders(): void
    {
        \TpvOrderReference::hold('A26000123');

        self::assertSame('', \TpvOrderReference::claim('invoice'));
        self::assertSame('A26000123', \TpvOrderReference::claim('order'));
        // PrestaShop retries generateReference while the reference exists; never loop on ours.
        self::assertSame('', \TpvOrderReference::claim('order'));
    }

    public function test_the_hook_leaves_web_orders_alone(): void
    {
        $module = (new \ReflectionClass(\Tpvconnect::class))->newInstanceWithoutConstructor();

        self::assertSame('', $module->hookActionGenerateDocumentReference(['type' => 'order']));
    }

    public function test_the_module_hook_returns_the_reference_of_the_order_being_created(): void
    {
        $module = (new \ReflectionClass(\Tpvconnect::class))->newInstanceWithoutConstructor();
        $seen = null;

        $this->creator->create(['reference' => 'B26000007'], function () use ($module, &$seen): array {
            $seen = $module->hookActionGenerateDocumentReference(['type' => 'order']);

            return ['order_id' => 7, 'reference' => (string) $seen, 'total_paid' => 1.0];
        });

        self::assertSame('B26000007', $seen);
        self::assertSame('', $module->hookActionGenerateDocumentReference(['type' => 'order']));
    }

    public function test_install_registers_the_reference_hook(): void
    {
        $module = new \Module();

        self::assertTrue((new \TpvConnectInstaller($module))->registerHooks());
        self::assertSame(['displayBackOfficeHeader', 'actionAdminControllerSetMedia', 'actionGenerateDocumentReference'], $module->registeredHooks);
    }
}
