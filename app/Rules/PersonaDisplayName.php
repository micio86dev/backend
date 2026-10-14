<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The interviewer's display name on an avatar template (`persona.display_name`,
 * live-interview-call-chrome D7): plain text the candidate reads in a tile chip and a caption prefix.
 *
 * ONE rule, one class: create, update and import validate through it, and the model accessor
 * re-checks a stored value with `problem()`, so a name no write path would accept never reaches a
 * candidate's screen. The name is candidate-facing, so it is:
 *
 * - 1 to `MAX` characters after trimming (it must fit the chip and the caption prefix);
 * - free of `<`, `>` and control or format characters (no markup, no line breaks);
 * - free of an avatar provider's or its infrastructure's name (provider opacity extends to
 *   operator-authored text). `PROVIDERS` is the one constant to extend when a provider is added.
 *
 * No message repeats the matched vendor word: an error that echoes it back is itself a leak into
 * logs and screenshots.
 *
 * A blank or absent value is NOT this rule's business: the form's `nullable` and the import's
 * normalisation treat it as "not set" before it gets here.
 *
 * REQ: avatar-templates "A template MAY carry an interviewer display name"
 */
final class PersonaDisplayName implements ValidationRule
{
    /** Longest name that fits the chip and the caption prefix. */
    public const MAX = 60;

    /** Case-insensitive, word-bounded: HeyGen, LiveAvatar, Tavus, Daily (and daily.co), LiveKit. */
    private const PROVIDERS = '/\b(?:hey\s*gen|live\s*avatar|tavus|daily|live\s*kit)\b/iu';

    /**
     * Why `$value` is not an acceptable display name, or null when it is.
     */
    public static function problem(mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'The display name must be text.';
        }

        $name = trim($value);
        $length = mb_strlen($name);

        if ($length < 1 || $length > self::MAX) {
            return 'The display name must be between 1 and '.self::MAX.' characters.';
        }

        if (preg_match('/[<>]|\p{C}/u', $name) === 1) {
            return 'The display name must be plain text.';
        }

        if (preg_match(self::PROVIDERS, $name) === 1) {
            return 'The display name must not name a technology provider.';
        }

        return null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $problem = self::problem($value);

        if ($problem !== null) {
            $fail($problem);
        }
    }
}
