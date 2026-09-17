<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue\Concerns;

/**
 * The FormRequest half of the runtime twin's "non-blank locale maps, no
 * edge whitespace" rule (framework-catalogue-authoring PR3, D3 — mirrors
 * `scripts/ci-guards.sh:2330` and the DB CHECK constraints in
 * `2026_09_15_201045_add_catalogue_nonblank_locale_checks`).
 *
 * A superadmin catalogue FormRequest names its locale-map fields once; this
 * trait expands each into `{field}` (required array) plus a rule per
 * `{field}.{locale}` that refuses a present-but-blank or whitespace-only
 * value. `en` is mandatory; every other declared locale (`it`) is optional
 * but non-blank WHEN PRESENT — an operator who has not authored the Italian
 * text yet simply omits the key, exactly like the seeder's own JSON
 * convention.
 */
trait ValidatesLocaleMaps
{
    /**
     * @param  list<string>  $locales
     * @return array<string, list<mixed>>
     */
    protected function localeMapRules(string $field, array $locales = ['en', 'it'], bool $required = true): array
    {
        $rules = [
            // Z3 (R1-001, REQUIRED BEFORE ARCHIVE): the parent map was
            // validated only as `array` — nothing refused a key beyond the
            // known locale set (`fr`, a typo, an invented code), and it
            // stored unvalidated straight into the JSON column. Restricted
            // to `$locales` here, the SAME allowlist
            // `CompetencyNormalizer::knownLocales()` enforces on the seed/
            // import side — a locale map is either a subset of known
            // locales or refused outright, never partially trusted.
            $field => [
                $required ? 'required' : 'sometimes', 'array',
                function (string $attribute, mixed $value, \Closure $fail) use ($locales): void {
                    if (! is_array($value)) {
                        return;
                    }

                    $unknown = array_diff(array_keys($value), $locales);

                    if ($unknown !== []) {
                        $fail("{$attribute}_unknown_locale");
                    }
                },
            ],
            // Z2 (R3-responsibilities-missing-en, REQUIRED BEFORE ARCHIVE):
            // when the map itself is optional (`$required = false`), a bare
            // `'sometimes'` on `{field}.en` only runs when that EXACT KEY is
            // present — so `{"responsibilities": {"it": "..."}}` (the map
            // present, `en` simply omitted) passed validation entirely,
            // violating the same "en mandatory whenever the map is present"
            // invariant `CompetencyNormalizer::normalizeLocaleMap()` already
            // enforces on the seed/import side. `required_with:{field}` is
            // evaluated whenever the SIBLING key `{field}` is present, which
            // is the actual invariant — the same fix
            // `UpdateDefaultQuestionRequest` already applies to `text.it`.
            "{$field}.en" => [$required ? 'required' : "required_with:{$field}", 'string', self::nonBlank()],
        ];

        foreach ($locales as $locale) {
            if ($locale === 'en') {
                continue;
            }

            $rules["{$field}.{$locale}"] = ['sometimes', 'string', self::nonBlank()];
        }

        return $rules;
    }

    /**
     * Refuses both an all-blank value AND a value carrying leading/trailing
     * whitespace (gga review finding, non-blocking — the docblock above
     * promised "no edge whitespace" and this method previously only
     * checked for all-blank, so `" text "` passed). Deliberately STRICTER
     * than the DB CHECK constraint (`length(btrim(value)) > 0`, which
     * accepts `" text "` — trimming only decides blank-or-not there, not
     * edge whitespace itself): a FormRequest may reject more than its DB
     * backstop refuses; the twin only requires the DB layer never accept
     * something already rejected here.
     */
    private static function nonBlank(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            if (trim($value) === '') {
                $fail("{$attribute}_blank");

                return;
            }

            if (trim($value) !== $value) {
                $fail("{$attribute}_edge_whitespace");
            }
        };
    }
}
