<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Label;

/**
 * The labels of one till (docs/prd/agente-impresion.md L5, L6): the height of each
 * label, 25 to 60 mm (30 by default), on the full 72 mm width; and whether to cut
 * after every label instead of once at the end of the strip (off by default).
 */
final class LabelSettings
{
    public const MIN_HEIGHT_MM = 25;
    public const MAX_HEIGHT_MM = 60;
    public const DEFAULT_HEIGHT_MM = 30;
    /** 203 dpi is 8 dots per millimetre. */
    public const DOTS_PER_MM = 8;

    public function __construct(
        public readonly int $heightMm,
        public readonly bool $cutEach,
    ) {
    }

    public static function defaults(): self
    {
        return new self(self::DEFAULT_HEIGHT_MM, false);
    }

    /**
     * @throws \InvalidArgumentException when the height is out of bounds
     */
    public static function validated(int $heightMm, bool $cutEach): self
    {
        if ($heightMm < self::MIN_HEIGHT_MM || $heightMm > self::MAX_HEIGHT_MM) {
            throw new \InvalidArgumentException('Alto de etiqueta no válido (25-60 mm)');
        }

        return new self($heightMm, $cutEach);
    }

    /** What the database holds; a value out of bounds prints with the default height. */
    public static function fromStored(mixed $heightMm, mixed $cutEach): self
    {
        $height = is_numeric($heightMm) ? (int) $heightMm : self::DEFAULT_HEIGHT_MM;
        if ($height < self::MIN_HEIGHT_MM || $height > self::MAX_HEIGHT_MM) {
            $height = self::DEFAULT_HEIGHT_MM;
        }

        return new self($height, (bool) (is_numeric($cutEach) ? (int) $cutEach : $cutEach));
    }

    public function heightDots(): int
    {
        return $this->heightMm * self::DOTS_PER_MM;
    }
}
