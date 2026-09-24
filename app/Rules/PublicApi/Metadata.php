<?php

declare(strict_types=1);

namespace App\Rules\PublicApi;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the BEAI Public API (`/v1`) `metadata` object — SPEC.md §3.2
 * "Metadata: free-form `object<string, string>` on interviews, ≤ 20 keys,
 * key ≤ 40 chars, value ≤ 500 chars." and `components.schemas.Metadata`
 * (`maxProperties: 20`, `propertyNames.maxLength: 40`).
 *
 * Reusable across every endpoint that accepts `metadata` (interview
 * creation today; anything later) — `Rule::validate('metadata', ['sometimes',
 * new self])`. Every violation reported through this rule maps to
 * `App\Support\PublicApi\PublicApiExceptionRenderer::ruleCode()`'s
 * `metadata`/`metadata.*` special case, i.e. `errors[].code =
 * metadata_limit_exceeded` — the top-level `code` on the Problem body stays
 * `validation_failed` regardless (task Part B item 6 — "keep it simple").
 * `$fail()` may be called more than once per invocation (one violation kind
 * does not stop the others from being checked too), but
 * `Illuminate\Validation\Validator::failed()` — what the renderer reads —
 * only ever records ONE entry per attribute, so a caller with several
 * violations at once still gets exactly one `errors[]` line for `metadata`.
 */
final class Metadata implements ValidationRule
{
    private const MAX_KEYS = 20;

    private const MAX_KEY_LENGTH = 40;

    private const MAX_VALUE_LENGTH = 500;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('The :attribute must be an object.');

            return;
        }

        if (count($value) > self::MAX_KEYS) {
            $fail(sprintf('The :attribute must not have more than %d keys.', self::MAX_KEYS));
        }

        foreach ($value as $key => $entry) {
            if (! is_string($key) || strlen($key) > self::MAX_KEY_LENGTH) {
                $fail(sprintf('Every :attribute key must be a string of at most %d characters.', self::MAX_KEY_LENGTH));
            }

            if (! is_string($entry) || strlen($entry) > self::MAX_VALUE_LENGTH) {
                $fail(sprintf('Every :attribute value must be a string of at most %d characters.', self::MAX_VALUE_LENGTH));
            }
        }
    }
}
