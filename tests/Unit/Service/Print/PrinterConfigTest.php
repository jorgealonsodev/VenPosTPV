<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Print\PrinterConfig;

final class PrinterConfigTest extends TestCase
{
    public function testFromJsonValidConfig(): void
    {
        $json = json_encode([
            'type' => 'network',
            'host' => '192.168.1.100',
            'port' => 9100,
            'paper_width' => 80,
            'enabled' => true,
        ]);

        $config = PrinterConfig::fromJson($json);

        self::assertSame('network', $config->type);
        self::assertSame('192.168.1.100', $config->host);
        self::assertSame(9100, $config->port);
        self::assertSame(80, $config->paperWidth);
        self::assertTrue($config->enabled);
    }

    public function testFromJsonNullReturnsDisabled(): void
    {
        $config = PrinterConfig::fromJson(null);

        self::assertSame('disabled', $config->type);
        self::assertSame('', $config->host);
        self::assertFalse($config->enabled);
    }

    public function testFromJsonEmptyStringReturnsDisabled(): void
    {
        $config = PrinterConfig::fromJson('');

        self::assertSame('disabled', $config->type);
        self::assertFalse($config->enabled);
    }

    public function testFromJsonMalformedJsonReturnsDefault(): void
    {
        $config = PrinterConfig::fromJson('not-json');

        self::assertSame('disabled', $config->type);
        self::assertSame('', $config->host);
        self::assertSame(9100, $config->port);
        self::assertSame(80, $config->paperWidth);
        self::assertFalse($config->enabled);
    }

    public function testDisabledFactory(): void
    {
        $config = PrinterConfig::disabled();

        self::assertSame('disabled', $config->type);
        self::assertSame('', $config->host);
        self::assertSame(9100, $config->port);
        self::assertSame(80, $config->paperWidth);
        self::assertFalse($config->enabled);
    }
}
