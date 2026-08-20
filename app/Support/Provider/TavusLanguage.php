<?php

declare(strict_types=1);

namespace App\Support\Provider;

/**
 * The spelled-out language vocabulary Tavus expects
 * (avatar-language-follows-project, D3).
 *
 * Lifted out of `TemplatePayload::tavus()` when the template stopped carrying a
 * language, so the ratified `it → italian` requirement keeps a pure unit test of
 * its own instead of being observable only through an HTTP fake.
 *
 * Tavus ACCEPTS a wrong value and ignores it silently — the avatar then answers
 * in English to an Italian candidate, a failure nobody would attribute to a
 * language mapping. That is why an unmapped locale passes through unchanged
 * rather than falling back to a default: a confidently wrong value is worse than
 * an unrecognised one, because only the unrecognised one can be noticed.
 */
final class TavusLanguage
{
    public static function forWire(string $locale): string
    {
        return match ($locale) {
            'it' => 'italian',
            'en' => 'english',
            default => $locale,
        };
    }
}
