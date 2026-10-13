<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Interview\PhraseNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses a text that contains the end or final phrase of ANY locale, compared
 * the way the candidate app compares them, because speaking one makes the client
 * believe the interview is over.
 */
final class NoCompletionPhrase implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $normalised = PhraseNormalizer::normalize($value);

        foreach (PhraseNormalizer::completionPhrases() as $phrase) {
            if (str_contains($normalised, $phrase)) {
                $fail("{$attribute}_completion_phrase");

                return;
            }
        }
    }
}
