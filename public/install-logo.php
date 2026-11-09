<?php

declare(strict_types=1);

/**
 * The VenPosTPV logo for the web installer, embedded in the page as inline SVG
 * (light and dark theme versions), so it shows whatever the hosting does with
 * /assets before the TPV is installed. Only defines a function: requesting this
 * file prints nothing.
 */
function venpostpv_installer_logo(string $publicDir): string
{
    $light = @file_get_contents($publicDir . '/assets/img/brand/venpostpv-logo.svg');
    $dark = @file_get_contents($publicDir . '/assets/img/brand/venpostpv-logo-dark.svg');
    if (!\is_string($light) || !\is_string($dark) || !str_contains($light, '<svg') || !str_contains($dark, '<svg')) {
        return '<span class="brand-text">VenPos<b>TPV</b></span>';
    }

    $inline = static function (string $svg, bool $labelled): string {
        $svg = (string) preg_replace('/<\?xml[^>]*\?>\s*/', '', $svg);
        // No <title>/id: the two copies share the page, so ids must not repeat.
        $svg = (string) preg_replace('/<title[^>]*>.*?<\/title>/s', '', $svg);
        $svg = (string) preg_replace('/\s(role|aria-labelledby|aria-hidden|aria-label)="[^"]*"/', '', $svg);

        return (string) preg_replace('/<svg\b/', '<svg ' . ($labelled ? 'role="img" aria-label="VenPosTPV"' : 'aria-hidden="true"'), $svg, 1);
    };

    return '<span class="logo-light">' . $inline($light, true) . '</span>'
        . '<span class="logo-dark">' . $inline($dark, false) . '</span>';
}
