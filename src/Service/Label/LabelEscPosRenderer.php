<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Label;

/**
 * ESC/POS labels for the Epson TM-T20 (docs/prd/agente-impresion.md L3, L5-L7):
 * the full 72 mm width (576 dots at 203 dpi), each label exactly its configured
 * height (8 dots per mm), all the labels of a job in one strip separated by a
 * dotted line and a single cut at the end; or a cut after every label when the till
 * asks for it. Text uses character table 16 (WPC1252) for "€", like the ticket.
 *
 * The code goes as one GS v 0 raster image (LabelCodeZone): the bars, their text
 * and a small QR with the same data beside them. ESC/POS cannot put a GS k barcode
 * and a QR side by side, so the printer's own barcode commands are not used; the
 * bars stay module-aligned (integer dots per module, never scaled).
 *
 * Layout of one label, top to bottom: 8 dots of margin, the name in bold (1-2 lines
 * of 24 dots), the combination (24), the price in double size (48; triple, 72, from
 * 40 mm), the code zone (the bars and their text, 24, with the QR beside both) and
 * the separator (8 dots: the dotted line, or plain paper before a cut). The bars
 * take the height left, 40 to 255 dots; any remainder is fed so every label has the
 * same pitch. A raster feeds the paper exactly its height.
 */
final class LabelEscPosRenderer
{
    private const ESC = "\x1B";
    private const GS = "\x1D";
    private const LINE_DOTS = 24;
    private const TOP_DOTS = 8;
    private const HRI_DOTS = LabelCodeZone::TEXT_DOTS;
    private const SEPARATOR_DOTS = 8;
    private const MIN_BAR_DOTS = 40;
    private const MAX_BAR_DOTS = 255;
    private const LARGE_PRICE_FROM_MM = 40;
    /** Partial cut right where the paper is (GS V 65 0). */
    private const CUT = "\x1DV\x41\x00";

    /**
     * @param list<Label> $labels
     */
    public function render(array $labels, LabelSettings $settings): string
    {
        $bytes = self::ESC . '@' . self::ESC . "t\x10" . self::ESC . "a\x01" . self::ESC . '3' . \chr(self::LINE_DOTS);
        $last = \count($labels) - 1;
        foreach ($labels as $i => $label) {
            $bytes .= $this->label($label, $settings);
            if ($settings->cutEach) {
                $bytes .= self::feed(self::SEPARATOR_DOTS) . self::CUT;
            } elseif ($i < $last) {
                $bytes .= self::dottedLine();
            } else {
                $bytes .= self::feed(self::SEPARATOR_DOTS) . self::CUT;
            }
        }

        return $bytes;
    }

    /** The dots one label takes on the paper, separator included: always its height. */
    public function labelDots(Label $label, LabelSettings $settings): int
    {
        $layout = $this->layout($label, $settings);

        return self::TOP_DOTS + $layout['text'] + $layout['bars'] + self::HRI_DOTS + $layout['fill'] + self::SEPARATOR_DOTS;
    }

    /**
     * @return array{name: list<string>, price_dots: int, text: int, bars: int, fill: int}
     */
    public function layout(Label $label, LabelSettings $settings): array
    {
        $name = LabelText::nameLines($label->name);
        $priceDots = $settings->heightMm >= self::LARGE_PRICE_FROM_MM ? 3 * self::LINE_DOTS : 2 * self::LINE_DOTS;
        $text = self::LINE_DOTS * \count($name) + ($this->combination($label) !== '' ? self::LINE_DOTS : 0) + $priceDots;
        $room = $settings->heightDots() - self::SEPARATOR_DOTS - self::TOP_DOTS - $text - self::HRI_DOTS;
        $bars = max(self::MIN_BAR_DOTS, min(self::MAX_BAR_DOTS, $room));

        return ['name' => $name, 'price_dots' => $priceDots, 'text' => $text, 'bars' => $bars, 'fill' => max(0, $room - $bars)];
    }

    private function label(Label $label, LabelSettings $settings): string
    {
        $layout = $this->layout($label, $settings);
        $bytes = self::feed(self::TOP_DOTS);

        if ($layout['name'] !== []) {
            $bytes .= self::ESC . "E\x01" . implode('', array_map(static fn (string $line): string => $line . "\n", $layout['name'])) . self::ESC . "E\x00";
        }
        $combination = $this->combination($label);
        if ($combination !== '') {
            $bytes .= LabelText::toWpc1252($combination) . "\n";
        }

        $size = $layout['price_dots'] === 3 * self::LINE_DOTS ? "\x22" : "\x11";
        $bytes .= self::GS . '!' . $size . self::ESC . "E\x01" . self::ESC . '3' . \chr($layout['price_dots'])
            . LabelText::toWpc1252($label->priceText()) . "\n"
            . self::GS . "!\x00" . self::ESC . "E\x00" . self::ESC . '3' . \chr(self::LINE_DOTS);

        $bytes .= self::raster(LabelCodeZone::for($label->barcode, $layout['bars']));

        return $bytes . self::feed($layout['fill']);
    }

    private function combination(Label $label): string
    {
        return $label->combination !== null ? LabelText::clean($label->combination) : '';
    }

    /** GS v 0 0 xL xH yL yH d1..dk: the code zone at normal size, 72 bytes per row. */
    private static function raster(LabelCodeZone $zone): string
    {
        $widthBytes = intdiv($zone->width, 8);

        return self::GS . "v0\x00" . \chr($widthBytes % 256) . \chr(intdiv($widthBytes, 256))
            . \chr($zone->height % 256) . \chr(intdiv($zone->height, 256)) . $zone->raster();
    }

    /** 8 dots: 3 of paper, a 2-dot dotted line across the 576 dots, 3 of paper. */
    private static function dottedLine(): string
    {
        return self::feed(3) . self::GS . "v0\x00\x48\x00\x02\x00" . str_repeat("\xC0", 72 * 2) . self::feed(3);
    }

    /** ESC J n feeds n dots (at most 255 per command). */
    private static function feed(int $dots): string
    {
        $bytes = '';
        while ($dots > 0) {
            $step = min(255, $dots);
            $bytes .= self::ESC . 'J' . \chr($step);
            $dots -= $step;
        }

        return $bytes;
    }
}
