<?php

declare(strict_types=1);

namespace App\Support\Catalogue\Concerns;

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Role;
use RuntimeException;

/**
 * Reads and validates a locale-map value (`{"en": "...", "it": "..."}`) from
 * an untrusted catalogue source file, and writes one onto a translatable
 * catalogue model (framework-catalogue-authoring PR4b, K7).
 *
 * Extracted from `FrameworkCatalogSeeder` — `CatalogueImportCommand` used to
 * carry a byte-for-byte duplicate of `readLocaleMap()`/`setAllLocales()`
 * (its own docblock said so explicitly: "Mirrors
 * FrameworkCatalogSeeder::readLocaleMap() exactly"), the exact drift risk
 * two independent readers of the same vendored JSON trees keep paying for.
 * One implementation now; `$sourceLabel` keeps each caller's error messages
 * attributed to itself (`"FrameworkCatalogSeeder: ..."` vs
 * `"catalogue:import: ..."`), the only thing that ever genuinely differed
 * between the two copies.
 */
trait ReadsCatalogueLocaleMaps
{
    /**
     * Validate and read one translatable field's raw JSON value as a locale
     * map (framework-catalog-it-translations design D1). Fails closed
     * (throws) on any shape that is not an explicit `{"en": "...", ...}`
     * object with a mandatory `en` string and no keys outside the
     * known-locale set — mirrors `CompetencyNormalizer::normalizeLocaleMap()`,
     * a SEPARATE implementation (not this one) because that normalizer reads
     * BARS-entry fields, a contract this method's callers' own
     * `roles.json`/`competencies.json` fields never fall under (see that
     * class's own docblock).
     *
     * `$allowBlankEn` exists ONLY for `roles.json`'s `responsibilities`
     * field: an empty EN string is a legitimate, pre-existing sentinel for
     * "not yet authored" (the seeder's own `missing_role_meta` gap) — every
     * other translatable field in the catalogue treats a blank `en` as
     * malformed content (`catalog_malformed_bars_entries`'s own `isBlank`
     * rule).
     *
     * @return array<string, string>
     */
    private function readLocaleMap(mixed $value, string $context, string $sourceLabel, bool $allowBlankEn = false): array
    {
        if (! is_array($value) || array_is_list($value)) {
            $got = is_array($value) ? 'a list/array' : get_debug_type($value);

            throw new RuntimeException(
                "{$sourceLabel}: {$context} must be a locale-map object (e.g. {\"en\": \"...\"}), got {$got}."
            );
        }

        if (! array_key_exists('en', $value) || ! is_string($value['en'])) {
            throw new RuntimeException(
                "{$sourceLabel}: {$context} is missing a mandatory 'en' locale value."
            );
        }

        if (! $allowBlankEn && $value['en'] === '') {
            throw new RuntimeException(
                "{$sourceLabel}: {$context} has a blank 'en' locale value."
            );
        }

        $knownLocales = $this->knownLocales();

        foreach ($value as $locale => $text) {
            if (! is_string($locale) || ! in_array($locale, $knownLocales, true)) {
                throw new RuntimeException(
                    "{$sourceLabel}: {$context} has an unknown locale key [{$locale}]. Known locales: ".implode(', ', $knownLocales).'.'
                );
            }

            if (! is_string($text)) {
                throw new RuntimeException(
                    "{$sourceLabel}: {$context} locale [{$locale}] must be a string, got ".get_debug_type($text).'.'
                );
            }
        }

        /** @var array<string, string> $value */
        return $value;
    }

    /**
     * The known-locale allowlist — sourced from `config('app.supported_locales')`,
     * the SAME single source of truth `CompetencyNormalizer::knownLocales()` reads.
     *
     * @return list<string>
     */
    private function knownLocales(): array
    {
        /** @var list<string> $configured */
        $configured = config('app.supported_locales', ['en']);

        return in_array('en', $configured, true) ? $configured : [...$configured, 'en'];
    }

    /**
     * Write EVERY locale present in the source map — not `en` only. This is
     * the single code path an authored `it` value and the existing `en`
     * value both flow through.
     *
     * @param  array<string, string>  $localeMap
     */
    private function setAllLocales(Role|Competency|BarsIndicator $model, string $field, array $localeMap): void
    {
        foreach ($localeMap as $locale => $value) {
            $model->setTranslation($field, $locale, $value);
        }
    }
}
