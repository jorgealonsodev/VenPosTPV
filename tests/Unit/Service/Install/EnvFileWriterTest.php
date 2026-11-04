<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Install;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use VenPosTPV\Service\Install\EnvFileWriter;

final class EnvFileWriterTest extends TestCase
{
    public function testValuesRoundTripThroughDotenvWithoutExpansion(): void
    {
        $values = [
            'PLAIN' => 'tpv_user',
            'QUOTE' => "it's a 'pass'",
            'DOLLAR' => '$HOME and ${PATH} and $(id)',
            'BACKSLASH' => 'a\\b\\\\c"d#e f',
            'EMPTY' => '',
            'HASH' => 'x #not-a-comment',
        ];

        $parsed = (new Dotenv())->parse((new EnvFileWriter())->render($values));

        self::assertSame($values, $parsed);
    }

    public function testRejectsLineBreaks(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EnvFileWriter())->render(['X' => "a\nINJECTED=1"]);
    }

    public function testRejectsInvalidNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EnvFileWriter())->render(['bad-name' => 'x']);
    }
}
