<?php

declare(strict_types=1);

namespace App\Support\Interview;

use Illuminate\Support\Facades\Lang;

/**
 * PHP twin of `normalizePhrase` in `frontend/app/utils/proctor-config.ts`.
 *
 * The candidate app decides "the interview is over" by checking whether the
 * avatar's speech, normalised this way, contains the end or final phrase of the
 * project language. Anything the platform lets an operator add to what the
 * avatar says must therefore be checked with the SAME normalisation, or a phrase
 * the client would read as a completion signal slips through: lowercase, NFD,
 * strip combining marks, every non `[a-z0-9]` becomes a space, collapse
 * whitespace, trim.
 */
final class PhraseNormalizer
{
    /** Locales whose completion phrases are forbidden inside an intro. */
    private const LOCALES = ['it', 'en'];

    public static function normalize(string $text): string
    {
        $lower = mb_strtolower($text);
        $decomposed = \Normalizer::normalize($lower, \Normalizer::FORM_D);
        $stripped = preg_replace('/\p{M}/u', '', is_string($decomposed) ? $decomposed : $lower) ?? '';
        $spaced = preg_replace('/[^a-z0-9]/', ' ', $stripped) ?? '';

        return trim(preg_replace('/\s+/', ' ', $spaced) ?? '');
    }

    /**
     * The normalised end and final phrase of every supported locale. An empty one is dropped: it is
     * contained in every text, so a blank lang entry would refuse every intro.
     *
     * @return list<string>
     */
    public static function completionPhrases(): array
    {
        $phrases = [];

        foreach (self::LOCALES as $locale) {
            foreach (['interview.end_phrase', 'interview.final_phrase'] as $key) {
                $phrases[] = self::normalize((string) Lang::get($key, [], $locale));
            }
        }

        return array_values(array_filter($phrases, static fn (string $phrase): bool => $phrase !== ''));
    }
}
