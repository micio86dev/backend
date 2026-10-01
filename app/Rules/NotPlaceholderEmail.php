<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Participant\PlaceholderEmail;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * NotPlaceholderEmail (reusable-link-visitor-identity, design VD-4): an address
 * under the reserved placeholder domain is not an address a person can give.
 *
 * `@invalid.beai.local` passes Laravel's RFC `email` rule. Without this rule a
 * visitor could type it and create a row that `PlaceholderEmail::is()`
 * classifies as synthesised, so "a participant carrying a placeholder never had
 * an address" would stop being true and the invitation job's refusal would be
 * wrong about that row.
 *
 * The predicate is `PlaceholderEmail::is()`, not a copy of the domain: the
 * spelling lives in that class only. The failure is the framework's own
 * invalid-email message, translated, so it reads like any other bad address and
 * discloses nothing about the reserved domain.
 *
 * A value that is not a string is left to the `string` and `email` rules next
 * to this one.
 */
final class NotPlaceholderEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && PlaceholderEmail::is($value)) {
            $fail('validation.email')->translate();
        }
    }
}
