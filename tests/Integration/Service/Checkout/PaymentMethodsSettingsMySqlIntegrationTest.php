<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Checkout;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Checkout\PaymentMethodsSettings;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * Ajustes → Tienda → «Métodos de pago»: which ways of paying the shop offers. All of
 * them until the shop chooses, so an installation that never touched it is unchanged.
 */
final class PaymentMethodsSettingsMySqlIntegrationTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->connection->executeStatement("DELETE FROM app_setting WHERE name = 'payment.methods'");
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->connection->executeStatement("DELETE FROM app_setting WHERE name = 'payment.methods'");
            $this->connection->close();
        }
    }

    public function testEveryMethodIsOnUntilTheShopChooses(): void
    {
        $this->assertSame(['cash', 'card', 'mixed', 'transfer', 'voucher'], (new PaymentMethodsSettings($this->connection))->enabled());
    }

    public function testTheChosenMethodsAreKeptInTheirFixedOrder(): void
    {
        $settings = new PaymentMethodsSettings($this->connection);

        $settings->save(['transfer', 'cash']);

        $this->assertSame(['cash', 'transfer'], $settings->enabled());
        $this->assertTrue($settings->isEnabled('cash'));
        $this->assertFalse($settings->isEnabled('card'));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidChoices(): iterable
    {
        yield 'none' => [[], 'Deja al menos un método de pago activo'];
        yield 'unknown' => [['cash', 'bizum'], 'Método de pago desconocido: bizum'];
        yield 'mixed without card' => [['cash', 'mixed'], 'El pago mixto necesita Efectivo y Tarjeta activos'];
        yield 'not a list' => ['cash', 'Deja al menos un método de pago activo'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidChoices')]
    public function testAnInvalidChoiceIsRefusedAndNothingChanges(mixed $choice, string $message): void
    {
        $settings = new PaymentMethodsSettings($this->connection);
        $settings->save(['cash', 'card']);

        try {
            $settings->save($choice);
            $this->fail('Must be refused');
        } catch (\DomainException $e) {
            $this->assertSame($message, $e->getMessage());
        }
        $this->assertSame(['cash', 'card'], $settings->enabled());
    }
}
