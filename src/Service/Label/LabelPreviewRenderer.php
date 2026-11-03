<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Label;

/**
 * The preview of a label (docs/prd/agente-impresion.md L1, L2): a PNG of the same
 * layout as LabelEscPosRenderer at 203 dpi, one pixel per printer dot (576 wide,
 * 8 per mm high), drawn with GD like the ticket logo. The bars are drawn module by
 * module at the module width the printer will use.
 */
final class LabelPreviewRenderer
{
    private const WIDTH = 576;
    private const FONT_DIR = '/vendor/dompdf/dompdf/lib/fonts/';

    public function __construct(private readonly LabelEscPosRenderer $layout = new LabelEscPosRenderer())
    {
    }

    public function dataUri(Label $label, LabelSettings $settings): string
    {
        return 'data:image/png;base64,' . base64_encode($this->png($label, $settings));
    }

    /** The first row of the bars. */
    public function barsTop(Label $label, LabelSettings $settings): int
    {
        return 8 + $this->layout->layout($label, $settings)['text'];
    }

    public function png(Label $label, LabelSettings $settings): string
    {
        $layout = $this->layout->layout($label, $settings);
        $height = $settings->heightDots();
        $image = imagecreatetruecolor(self::WIDTH, $height);
        if ($image === false) {
            throw new \RuntimeException('Could not allocate the label preview');
        }
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 0, 0, 0);
        $grey = (int) imagecolorallocate($image, 150, 150, 150);
        imagefill($image, 0, 0, $white);

        $y = 8;
        foreach ($layout['name'] as $line) {
            $this->text($image, self::utf8($line), $y, 24, 13, true, $black);
            $y += 24;
        }
        $combination = $label->combination !== null ? LabelText::clean($label->combination) : '';
        if ($combination !== '') {
            $this->text($image, $combination, $y, 24, 13, false, $black);
            $y += 24;
        }
        $this->text($image, $label->priceText(), $y, $layout['price_dots'], $layout['price_dots'] === 72 ? 38 : 26, true, $black);
        $y += $layout['price_dots'];

        $modules = $label->barcode->modules();
        $module = $label->barcode->moduleDots;
        $left = intdiv(self::WIDTH - \strlen($modules) * $module, 2);
        foreach (str_split($modules) as $i => $bar) {
            if ($bar === '1') {
                imagefilledrectangle($image, $left + $i * $module, $y, $left + ($i + 1) * $module - 1, $y + $layout['bars'] - 1, $black);
            }
        }
        $y += $layout['bars'];
        $this->text($image, $label->barcode->text, $y, 24, 11, false, $black);

        // Where the dotted line (or the cut) goes.
        for ($x = 0; $x < self::WIDTH; $x += 8) {
            imagefilledrectangle($image, $x, $height - 5, $x + 1, $height - 4, $grey);
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function text(\GdImage $image, string $text, int $top, int $lineHeight, int $size, bool $bold, int $color): void
    {
        $font = \dirname(__DIR__, 3) . self::FONT_DIR . ($bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf');
        if (\function_exists('imagettftext') && is_file($font)) {
            $box = imagettfbbox($size, 0, $font, $text);
            if ($box !== false) {
                $width = $box[2] - $box[0];
                $baseline = $top + intdiv($lineHeight + $size, 2) + 1;
                imagettftext($image, $size, 0, max(0, intdiv(self::WIDTH - $width, 2)), $baseline, $color, $font, $text);

                return;
            }
        }
        // Without FreeType: GD's built-in font (ASCII only).
        $ascii = (string) preg_replace('/[^\x20-\x7E]/', '?', str_replace('€', 'EUR', $text));
        imagestring($image, 5, max(0, intdiv(self::WIDTH - \strlen($ascii) * imagefontwidth(5), 2)), $top + 4, $ascii, $color);
    }

    private static function utf8(string $wpc1252): string
    {
        $converted = @iconv('Windows-1252', 'UTF-8', $wpc1252);

        return $converted === false ? $wpc1252 : $converted;
    }
}
