<?php

declare(strict_types=1);

namespace App\Services\FrameworkCatalog;

use App\Services\FrameworkCatalog\DTO\CompetencyDTO;
use App\Services\FrameworkCatalog\DTO\IndicatorDTO;
use InvalidArgumentException;

/**
 * Adapter that normalizes competency data from either split-file or unified JSON shape
 * into a canonical CompetencyDTO.
 *
 * Split shape:
 *   $competencyEntry = ['code'=>..., 'name'=>{locale map}, 'definition'=>{locale map}, 'type'=>...]
 *   $barsArray       = [['indicator'=>{locale map}, 'scale'=>['5'=>{locale map}, '3'=>{locale map}, '1'=>{locale map}]], ...]
 *
 * Unified shape (future):
 *   $competencyEntry = [...same fields..., 'bars' => [...same bars array...]]
 *   $barsArray       = null  (normalizer detects 'bars' key in competencyEntry)
 *
 * Auto-detects shape by presence of 'bars' key in $competencyEntry.
 * One switch-point; no config flag required.
 *
 * BARS JSON key→DB column mapping:
 *   indicator  → text    (stored in BarsIndicator.text)
 *   scale.5    → anchor5 (stored in BarsIndicator.anchor5)
 *   scale.3    → anchor3 (stored in BarsIndicator.anchor3)
 *   scale.1    → anchor1 (stored in BarsIndicator.anchor1)
 *   array index → position (0-based, stable JSON insertion order)
 *
 * Locale dimension (framework-catalog-it-translations, design D1). Every
 * translatable field's JSON value MUST be an explicit locale map at the
 * leaf — `{"en": "...", "it": "..."}` — never a bare string. This is the
 * SAME rule `catalog_malformed_bars_entries` (scripts/ci-guards.sh) enforces
 * at CI time; this class enforces it at seed time, so a shape regression
 * fails LOUDLY here rather than being cast into a garbage string
 * (`(string) ['en' => '...']` produces PHP's "Array to string conversion",
 * not a useful error) or silently accepted.
 *
 *   - Not an object (e.g. a bare string) → rejected.
 *   - Missing the mandatory `en` key, or `en` not a non-empty string →
 *     rejected. Every catalogue string has an English source; there is no
 *     locale-less fallback.
 *   - Any key outside the known-locale set (`config('app.supported_locales')`)
 *     → rejected. A typo'd or invented locale code must never land silently.
 *   - Any present locale value that is not a string → rejected.
 *
 * A field with ONLY `en` present (no `it` key at all) is valid — that is
 * the migrated-but-not-yet-translated state every catalogue entry is in
 * until Italian content is authored (Phase 5+ of
 * `framework-catalog-it-translations`, out of scope for the normalizer
 * itself).
 */
final class CompetencyNormalizer
{
    /**
     * Normalize a competency entry into a CompetencyDTO with BARS indicators.
     *
     * @param  array<string, mixed>  $competencyEntry  One competency record (with or without 'bars' key).
     * @param  list<array<string, mixed>>|null  $barsArray  BARS indicators (split shape) or null (unified shape).
     *
     * @throws InvalidArgumentException When any translatable field is not a valid locale map.
     */
    public function normalize(array $competencyEntry, ?array $barsArray): CompetencyDTO
    {
        // Auto-detect unified shape: 'bars' key present in the competency entry itself
        $resolvedBars = $barsArray ?? (isset($competencyEntry['bars']) ? $competencyEntry['bars'] : []);

        $indicators = $this->normalizeBars($resolvedBars);

        return new CompetencyDTO(
            code: (string) $competencyEntry['code'],
            name: $this->normalizeLocaleMap($competencyEntry['name'] ?? null, 'name'),
            definition: $this->normalizeLocaleMap($competencyEntry['definition'] ?? null, 'definition'),
            type: (string) ($competencyEntry['type'] ?? 'standard'),
            indicators: $indicators,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $barsArray
     * @return list<IndicatorDTO>
     */
    private function normalizeBars(array $barsArray): array
    {
        $indicators = [];

        foreach ($barsArray as $position => $barEntry) {
            /** @var array{indicator: mixed, scale: array{5: mixed, 3: mixed, 1: mixed}} $barEntry */
            $scale = $barEntry['scale'];

            $indicators[] = new IndicatorDTO(
                text: $this->normalizeLocaleMap($barEntry['indicator'] ?? null, 'indicator'),
                anchor5: $this->normalizeLocaleMap($scale['5'] ?? null, 'scale.5'),
                anchor3: $this->normalizeLocaleMap($scale['3'] ?? null, 'scale.3'),
                anchor1: $this->normalizeLocaleMap($scale['1'] ?? null, 'scale.1'),
                position: (int) $position,
            );
        }

        return $indicators;
    }

    /**
     * Validate and normalize one translatable field's raw JSON value into a
     * locale map. Fails closed (throws) on every shape that is not an
     * explicit `{"en": "...", ...}` object with a mandatory, non-empty `en`
     * string and no keys outside the known-locale set — see class docblock.
     *
     * @return array<string, string>
     */
    private function normalizeLocaleMap(mixed $value, string $field): array
    {
        if (! is_array($value) || array_is_list($value)) {
            $got = is_array($value) ? 'a list/array' : get_debug_type($value);

            throw new InvalidArgumentException(
                "CompetencyNormalizer: field [{$field}] must be a locale-map object ".
                "(e.g. {\"en\": \"...\"}), got {$got}."
            );
        }

        if (! array_key_exists('en', $value) || ! is_string($value['en']) || $value['en'] === '') {
            throw new InvalidArgumentException(
                "CompetencyNormalizer: field [{$field}] is missing a mandatory, non-empty 'en' locale value."
            );
        }

        $knownLocales = $this->knownLocales();
        $result = [];

        foreach ($value as $locale => $text) {
            if (! is_string($locale) || ! in_array($locale, $knownLocales, true)) {
                throw new InvalidArgumentException(
                    "CompetencyNormalizer: field [{$field}] has an unknown locale key [{$locale}]. ".
                    'Known locales: '.implode(', ', $knownLocales).'.'
                );
            }

            if (! is_string($text)) {
                throw new InvalidArgumentException(
                    "CompetencyNormalizer: field [{$field}] locale [{$locale}] must be a string, got ".
                    get_debug_type($text).'.'
                );
            }

            $result[$locale] = $text;
        }

        return $result;
    }

    /**
     * The known-locale allowlist — sourced from `config('app.supported_locales')`
     * (single source of truth shared with the API's own locale validation,
     * `LocaleValidationTest.php`) so adding a future locale (es/fr/de/pt)
     * needs no change to this class, only to that config array and content.
     *
     * @return list<string>
     */
    private function knownLocales(): array
    {
        /** @var list<string> $configured */
        $configured = config('app.supported_locales', ['en']);

        // 'en' is always known, config drift or not — every catalogue string
        // has an English source and the mandatory-en rule above depends on it.
        return in_array('en', $configured, true) ? $configured : [...$configured, 'en'];
    }
}
