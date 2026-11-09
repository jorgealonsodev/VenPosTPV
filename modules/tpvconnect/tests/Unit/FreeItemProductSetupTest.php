<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use PHPUnit\Framework\TestCase;

/** An in-memory shop: finds what it created before, like the production gateway. */
final class FakeFreeItemSetupGateway implements \FreeItemSetupGateway
{
    /** @var array<string, int> rate => tax rules group */
    public array $taxGroups = ['21.00' => 1, '10.00' => 2];
    public array $groups = [];
    public array $attributes = [];
    /** @var array<string, array{id: int, name: string, tax: int}> by reference */
    public array $products = [];
    public array $combinations = [];
    public int $created = 0;
    private int $nextId = 100;

    public function findTaxRulesGroup(float $rate): ?int
    {
        return $this->taxGroups[number_format($rate, 2, '.', '')] ?? null;
    }

    public function findOrCreateAttributeGroup(string $name): int
    {
        return $this->groups[$name] ??= $this->create();
    }

    public function findOrCreateAttribute(int $idAttributeGroup, string $name): int
    {
        return $this->attributes[$idAttributeGroup . '/' . $name] ??= $this->create();
    }

    public function findProductByReference(string $reference): ?int
    {
        return $this->products[$reference]['id'] ?? null;
    }

    public function saveProduct(?int $idProduct, string $name, string $reference, int $idTaxRulesGroup): int
    {
        $id = $idProduct ?? $this->create();
        $this->products[$reference] = ['id' => $id, 'name' => $name, 'tax' => $idTaxRulesGroup];

        return $id;
    }

    public function findOrCreateCombination(int $idProduct, int $idAttribute, string $reference, bool $default): int
    {
        return $this->combinations[$idProduct . '/' . $idAttribute] ??= $this->create();
    }

    private function create(): int
    {
        ++$this->created;

        return $this->nextId++;
    }
}

/**
 * POST /module/tpvconnect/freeitem (docs/prd/articulo-libre.md, A5.1-A5.2).
 */
final class FreeItemProductSetupTest extends TestCase
{
    protected function setUp(): void
    {
        \Configuration::$values = ['TPVCONNECT_SSO_SECRET' => 'secret'];
    }

    public function test_the_request_needs_a_list_of_known_rates(): void
    {
        self::assertSame([], \FreeItemProductSetup::validateRequest(['vat_rates' => [21, 10.0]]));
        foreach ([[], ['vat_rates' => []], ['vat_rates' => '21'], ['vat_rates' => ['a' => 21]]] as $data) {
            self::assertSame(['vat_rates' => 'Required non-empty list of VAT rates'], \FreeItemProductSetup::validateRequest($data));
        }
        foreach ([[21, 7], ['21'], [null]] as $rates) {
            self::assertSame(['vat_rates' => 'Each rate must be one of 21, 10, 4, 0'], \FreeItemProductSetup::validateRequest(['vat_rates' => $rates]));
        }
    }

    public function test_one_hidden_product_per_rate_with_twenty_slots_is_created_and_stored(): void
    {
        $gateway = new FakeFreeItemSetupGateway();
        $map = (new \FreeItemProductSetup($gateway))->setup([21, 10]);

        self::assertSame(['21.00', '10.00'], array_keys($map));
        foreach ($map as $entry) {
            self::assertSame(range(1, 20), array_keys($entry['slots']));
            self::assertCount(20, array_unique($entry['slots']));
        }
        self::assertSame(['id' => $map['21.00']['id_product'], 'name' => 'Artículo libre TPV 21 %', 'tax' => 1], $gateway->products['TPV-LIBRE-21']);
        self::assertSame(['id' => $map['10.00']['id_product'], 'name' => 'Artículo libre TPV 10 %', 'tax' => 2], $gateway->products['TPV-LIBRE-10']);
        self::assertSame(['Línea TPV'], array_keys($gateway->groups));
        self::assertCount(20, $gateway->attributes);
        // Both products share the 20 slot attributes but not their combinations.
        self::assertSame([], array_intersect($map['21.00']['slots'], $map['10.00']['slots']));
        self::assertSame($map, \FreeItemCatalog::load());
    }

    public function test_running_it_again_returns_the_same_ids_and_creates_nothing(): void
    {
        $gateway = new FakeFreeItemSetupGateway();
        $first = (new \FreeItemProductSetup($gateway))->setup([21, 10]);
        $created = $gateway->created;

        \Configuration::$values = [];
        $second = (new \FreeItemProductSetup($gateway))->setup([21, 10]);

        self::assertSame($first, $second);
        self::assertSame($created, $gateway->created);
    }

    public function test_adding_a_rate_later_keeps_the_rates_already_stored(): void
    {
        $gateway = new FakeFreeItemSetupGateway();
        $gateway->taxGroups['4.00'] = 3;
        $first = (new \FreeItemProductSetup($gateway))->setup([21]);
        $second = (new \FreeItemProductSetup($gateway))->setup([4]);

        self::assertSame(['21.00', '4.00'], array_keys($second));
        self::assertSame($first['21.00'], $second['21.00']);
        self::assertSame('TPV-LIBRE-4', array_keys($gateway->products)[1]);
    }

    public function test_an_exempt_product_has_no_tax_rules_group(): void
    {
        $gateway = new FakeFreeItemSetupGateway();
        (new \FreeItemProductSetup($gateway))->setup([0]);

        self::assertSame(['id' => 100 + 21, 'name' => 'Artículo libre TPV exento', 'tax' => 0], $gateway->products['TPV-LIBRE-EXENTO']);
    }

    public function test_a_rate_without_tax_rules_group_creates_nothing(): void
    {
        $gateway = new FakeFreeItemSetupGateway();
        try {
            (new \FreeItemProductSetup($gateway))->setup([21, 4]);
            self::fail('Expected FreeItemSetupException');
        } catch (\FreeItemSetupException $e) {
            self::assertSame('No active tax rules group with 4.00 % for the default country', $e->getMessage());
        }
        self::assertSame(0, $gateway->created);
        self::assertSame([], \FreeItemCatalog::load());
    }
}
