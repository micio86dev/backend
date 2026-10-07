<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * Black or white text for a tenant-coloured surface.
 *
 * A PORT of the backoffice's `readableForeground` (`app/utils/brand-color.ts`):
 * WCAG 2.1 relative luminance, whichever of black and white measures the higher
 * contrast wins, and a value that is not `#rrggbb` falls back to white. Keep the
 * two in step, so a tenant sees the same text colour in the app and in the mail.
 */
final class ReadableForeground
{
    public static function for(string $background): string
    {
        if (preg_match('/\A#[0-9a-f]{6}\z/i', $background) !== 1) {
            return '#ffffff';
        }

        return self::contrastRatio($background, '#ffffff') >= self::contrastRatio($background, '#000000')
            ? '#ffffff'
            : '#000000';
    }

    /** WCAG 2.1 contrast ratio between two opaque `#rrggbb` colours, 1:1 to 21:1. */
    public static function contrastRatio(string $a, string $b): float
    {
        $la = self::relativeLuminance($a);
        $lb = self::relativeLuminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function relativeLuminance(string $hex): float
    {
        $linear = static function (int $value): float {
            $channel = $value / 255;

            return $channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $linear((int) hexdec(substr($hex, 1, 2)))
            + 0.7152 * $linear((int) hexdec(substr($hex, 3, 2)))
            + 0.0722 * $linear((int) hexdec(substr($hex, 5, 2)));
    }
}
