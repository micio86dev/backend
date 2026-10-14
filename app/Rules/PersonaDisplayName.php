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

    /**
     * Case-insensitive, bounded by any non letter or digit (so `_` too): HeyGen, LiveAvatar, Tavus, Daily
     * (and daily.co), LiveKit. The two parts of a multi-part name may be split by spaces, `-`, `_` or `.`
     * (`Hey-Gen`, `Live_Kit`). The word boundary is deliberate: a camel-case suffix such as `LiveKitBot`
     * is accepted, as are look-alikes such as `Dailyn`, `Heygenie`, `Taverna` and `Live Aviator`.
     */
    private const PROVIDERS = '/(?<![\p{L}\p{N}])(?:hey[\s\-_.]*gen|live[\s\-_.]*avatar|tavus|daily|live[\s\-_.]*kit)(?![\p{L}\p{N}])/iu';

    /**
     * Why `$value` is not an acceptable display name, or null when it is.
     */
    public static function problem(mixed $value): ?string
    {
        // Malformed UTF-8 makes every `/u` match below return false, which must never read as a pass.
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return 'The display name must be text.';
        }

        // Unicode spaces (NO-BREAK SPACE, U+3000) survive `trim()` yet render blank.
        $name = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $value);
        $length = mb_strlen($name);

        if ($length < 1 || $length > self::MAX) {
            return 'The display name must be between 1 and '.self::MAX.' characters.';
        }

        // The raw value, not the trimmed one: an edge control character would otherwise be stored.
        // `!== 0` so a regex error refuses instead of passing.
        if (preg_match('/[<>]|[\p{C}\p{Zl}\p{Zp}]/u', $value) !== 0) {
            return 'The display name must be plain text.';
        }

        if (preg_match(self::PROVIDERS, $name) !== 0) {
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
