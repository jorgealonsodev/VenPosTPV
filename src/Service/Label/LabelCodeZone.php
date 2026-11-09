<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Label;

use Endroid\QrCode\Bacon\MatrixFactory;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelMedium;
use Endroid\QrCode\QrCode;

/**
 * The code zone of a label (docs/prd/agente-impresion.md L3, L4, L7): one 1-bit
 * bitmap the full 576 dots of the TM-T20 wide and as high as the bars plus their
 * text (24 dots), with, centred as a group:
 *
 * - the bars, module by module at an integer number of dots per module (never
 *   scaled), with their quiet zones;
 * - their human-readable text under them, drawn in the bitmap (so it stays centred
 *   under the bars although the group is off-centre);
 * - on the same rows, a small QR code with the same data (the EAN digits, or the
 *   reference of a Code128), error correction M, integer dots per module, a quiet
 *   zone of 2 modules around it, vertically centred and at most 15 mm (120 dots).
 *
 * The printer gets it as a GS v 0 raster (LabelEscPosRenderer) and the preview
 * paints the same bitmap (LabelPreviewRenderer), so the screen shows what prints.
 * A Code128 too wide to leave room for the QR is narrowed to the minimum module
 * (BarcodeSelector::MIN_MODULE_DOTS); if it still does not fit, the label has no
 * QR and the bars are exactly the ones it had.
 */
final class LabelCodeZone
{
    public const WIDTH = BarcodeSelector::PAPER_DOTS;
    public const TEXT_DOTS = 24;
    /** The QR symbol (without its quiet zone) is at most 15 mm at 8 dots per mm. */
    public const QR_MAX_DOTS = 120;
    /** 0.25 mm, the same minimum module as the bars. */
    public const QR_MIN_MODULE_DOTS = 2;
    public const QR_QUIET_MODULES = 2;
    private const TEXT_POINTS = 14;
    private const FONT = '/vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf';

    public readonly int $width;
    /** Modules per side of the QR symbol (21 for version 1), 0 without a QR. */
    public readonly int $qrModules;
    /** @var list<string>|null */
    private ?array $bitmap = null;

    /**
     * @param list<list<int>> $qr the QR matrix (rows of 0/1), [] without a QR
     */
    private function __construct(
        private readonly LabelBarcode $barcode,
        public readonly int $barDots,
        public readonly int $height,
        public readonly int $moduleDots,
        public readonly int $barsLeft,
        public readonly int $barsWidth,
        private readonly array $qr,
        public readonly int $qrModuleDots,
        public readonly int $qrLeft,
        public readonly int $qrTop,
    ) {
        $this->width = self::WIDTH;
        $this->qrModules = \count($qr);
    }

    /** @param int $barDots the height of the bars (the text goes under them) */
    public static function for(LabelBarcode $barcode, int $barDots): self
    {
        $height = $barDots + self::TEXT_DOTS;
        $qr = self::qrMatrix($barcode->data);
        $size = \count($qr);
        $modules = \strlen($barcode->modules());

        // The module the bars have; a Code128 may go down to the minimum to leave room.
        for ($module = $barcode->moduleDots; $module >= BarcodeSelector::MIN_MODULE_DOTS; --$module) {
            $bars = $modules * $module;
            for ($dots = intdiv(self::QR_MAX_DOTS, $size); $dots >= self::QR_MIN_MODULE_DOTS; --$dots) {
                $side = ($size + 2 * self::QR_QUIET_MODULES) * $dots;
                if ($side <= $height && $bars + $side <= self::WIDTH) {
                    $left = intdiv(self::WIDTH - $bars - $side, 2);

                    return new self(
                        $barcode,
                        $barDots,
                        $height,
                        $module,
                        $left,
                        $bars,
                        $qr,
                        $dots,
                        $left + $bars + self::QR_QUIET_MODULES * $dots,
                        intdiv($height - $size * $dots, 2)
                    );
                }
            }
        }

        // No room for the QR: the bars alone, as they were.
        $bars = $modules * $barcode->moduleDots;

        return new self($barcode, $barDots, $height, $barcode->moduleDots, intdiv(self::WIDTH - $bars, 2), $bars, [], 0, 0, 0);
    }

    public function hasQr(): bool
    {
        return $this->qr !== [];
    }

    /**
     * The rows of the zone, top to bottom, each WIDTH characters: '1' a black dot.
     *
     * @return list<string>
     */
    public function bitmap(): array
    {
        if ($this->bitmap !== null) {
            return $this->bitmap;
        }

        $rows = array_fill(0, $this->height, str_repeat('0', self::WIDTH));

        $bars = '';
        foreach (str_split($this->barcode->modules()) as $module) {
            $bars .= str_repeat($module, $this->moduleDots);
        }
        for ($y = 0; $y < $this->barDots; ++$y) {
            $rows[$y] = substr_replace($rows[$y], $bars, $this->barsLeft, $this->barsWidth);
        }

        foreach ($this->qr as $my => $line) {
            $dots = '';
            foreach ($line as $value) {
                $dots .= str_repeat($value === 1 ? '1' : '0', $this->qrModuleDots);
            }
            for ($dy = 0; $dy < $this->qrModuleDots; ++$dy) {
                $y = $this->qrTop + $my * $this->qrModuleDots + $dy;
                $rows[$y] = substr_replace($rows[$y], $dots, $this->qrLeft, \strlen($dots));
            }
        }

        foreach ($this->textRows() as $dy => $row) {
            $y = $this->barDots + $dy;
            // The text band only holds the text and the part of the QR beside it.
            $rows[$y] = $row | $rows[$y];
        }

        return $this->bitmap = $rows;
    }

    /** The bitmap packed for GS v 0: rows of WIDTH / 8 bytes, the leftmost dot in the high bit. */
    public function raster(): string
    {
        $raster = '';
        foreach ($this->bitmap() as $row) {
            foreach (str_split($row, 8) as $byte) {
                $raster .= \chr((int) bindec($byte));
            }
        }

        return $raster;
    }

    /**
     * The human-readable text, centred under the bars' ink, in a TEXT_DOTS band:
     * DejaVu Sans without antialiasing (so it is already 1 bit), or GD's built-in
     * font without FreeType.
     *
     * @return list<string>
     */
    private function textRows(): array
    {
        // A palette image: white (index 0) is the background, black is index 1.
        $image = imagecreate(self::WIDTH, self::TEXT_DOTS);
        if ($image === false) {
            throw new \RuntimeException('Could not allocate the label code text');
        }
        imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 0, 0, 0);

        $modules = $this->barcode->modules();
        $first = (int) strpos($modules, '1');
        $last = (int) strrpos($modules, '1');
        $centre = $this->barsLeft + intdiv(($first + $last + 1) * $this->moduleDots, 2);

        $text = $this->barcode->text;
        $font = \dirname(__DIR__, 3) . self::FONT;
        $box = \function_exists('imagettftext') && is_file($font) ? imagettfbbox(self::TEXT_POINTS, 0, $font, $text) : false;
        if ($box !== false) {
            $width = $box[2] - $box[0];
            $x = max(0, min(self::WIDTH - $width, $centre - intdiv($width, 2)));
            // A negative colour turns antialiasing off: crisp 1-bit glyphs.
            imagettftext($image, self::TEXT_POINTS, 0, $x - $box[0], 20, -$black, $font, $text);
        } else {
            $width = \strlen($text) * imagefontwidth(5);
            imagestring($image, 5, max(0, min(self::WIDTH - $width, $centre - intdiv($width, 2))), 5, $text, $black);
        }

        $rows = [];
        for ($y = 0; $y < self::TEXT_DOTS; ++$y) {
            $row = '';
            for ($x = 0; $x < self::WIDTH; ++$x) {
                $row .= imagecolorat($image, $x, $y) === $black ? '1' : '0';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The QR modules of the data, error correction M; ISO-8859-1 so the ASCII of a
     * reference goes in byte mode without an ECI header.
     *
     * @return list<list<int>>
     */
    private static function qrMatrix(string $data): array
    {
        $matrix = (new MatrixFactory())->create(new QrCode($data, new Encoding('ISO-8859-1'), new ErrorCorrectionLevelMedium()));
        $rows = [];
        for ($y = 0, $n = $matrix->getBlockCount(); $y < $n; ++$y) {
            $row = [];
            for ($x = 0; $x < $n; ++$x) {
                $row[] = $matrix->getBlockValue($y, $x);
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
