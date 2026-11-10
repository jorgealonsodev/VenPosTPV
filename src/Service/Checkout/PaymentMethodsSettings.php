<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Checkout;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;

/**
 * Ajustes → Tienda → «Métodos de pago»: the ways of paying the shop offers, for every
 * till. All of them until the shop chooses, so an installation that never touched
 * the setting works as before. «voucher» is the PrestaShop coupon («Vale regalo»);
 * «mixed» needs cash and card, the two parts it splits into.
 */
class PaymentMethodsSettings
{
    /** In the order the payment window shows them. */
    public const METHODS = ['cash', 'card', 'mixed', 'transfer', 'voucher'];

    private const NAME = 'payment.methods';

    private const CREATE_TABLE = 'CREATE TABLE IF NOT EXISTS app_setting (
        name VARCHAR(64) NOT NULL PRIMARY KEY,
        value TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return list<string> */
    public function enabled(): array
    {
        try {
            $stored = $this->connection->fetchOne('SELECT value FROM app_setting WHERE name = ?', [self::NAME]);
        } catch (TableNotFoundException) {
            return self::METHODS;
        }
        $list = \is_string($stored) ? json_decode($stored, true) : null;
        if (!\is_array($list)) {
            return self::METHODS;
        }
        $enabled = array_values(array_filter(self::METHODS, static fn (string $m): bool => \in_array($m, $list, true)));

        return $enabled === [] ? self::METHODS : $enabled;
    }

    public function isEnabled(string $method): bool
    {
        return \in_array($method, $this->enabled(), true);
    }

    /**
     * @throws \DomainException with the message the settings window shows
     */
    public function save(mixed $methods): void
    {
        if (!\is_array($methods) || $methods === []) {
            throw new \DomainException('Deja al menos un método de pago activo');
        }
        foreach ($methods as $method) {
            if (!\is_string($method) || !\in_array($method, self::METHODS, true)) {
                throw new \DomainException('Método de pago desconocido: ' . (\is_scalar($method) ? (string) $method : '?'));
            }
        }
        if (\in_array('mixed', $methods, true) && (!\in_array('cash', $methods, true) || !\in_array('card', $methods, true))) {
            throw new \DomainException('El pago mixto necesita Efectivo y Tarjeta activos');
        }
        $ordered = array_values(array_filter(self::METHODS, static fn (string $m): bool => \in_array($m, $methods, true)));

        $this->connection->executeStatement(self::CREATE_TABLE);
        $this->connection->executeStatement(
            'INSERT INTO app_setting (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [self::NAME, json_encode($ordered, JSON_THROW_ON_ERROR)]
        );
    }
}
