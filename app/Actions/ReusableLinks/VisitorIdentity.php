<?php

declare(strict_types=1);

namespace App\Actions\ReusableLinks;

use Illuminate\Support\Str;

/**
 * The identity a visitor typed before a reusable-link redemption: a name and an
 * email address, self-declared and NOT verified.
 *
 * This value object owns the normalisation, so the action never depends on a
 * middleware for a stored invariant:
 *   - both values are trimmed (`Str::trim`, the function `TrimStrings` uses, so a
 *     value is spelled the same whether or not the middleware ran);
 *   - the email is lower-cased with the multibyte-safe `mb_strtolower`, the same
 *     normalisation the public API's enrolment applies, so the duplicate check
 *     and the unique index compare one spelling;
 *   - the name is otherwise stored verbatim: no case change, no whitespace
 *     collapsing, no character filtering.
 *
 * Never logged and never part of an exception message built by this code.
 */
final readonly class VisitorIdentity
{
    private function __construct(
        public string $displayName,
        public string $email,
    ) {}

    /**
     * Build the identity from values that already passed validation.
     */
    public static function fromValidated(string $displayName, string $email): self
    {
        return new self(Str::trim($displayName), mb_strtolower(Str::trim($email)));
    }
}
