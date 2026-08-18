<?php

declare(strict_types=1);

namespace App\Services\FrameworkCatalog\DTO;

/**
 * Canonical DTO for a single BARS indicator.
 *
 * BARS JSON key→DTO field mapping:
 *   indicator  → text
 *   scale.5    → anchor5
 *   scale.3    → anchor3
 *   scale.1    → anchor1
 *   array index → position (0-based, stable JSON order)
 *
 * Locale dimension (framework-catalog-it-translations, design D1): every
 * translatable field is a locale map keyed by locale code (`en` mandatory,
 * `it` optional today; further locales require no DTO change), e.g.
 * `['en' => 'Recognizes symptoms...', 'it' => 'Individua i sintomi...']`.
 * `CompetencyNormalizer` is the single place that validates and produces
 * this shape — see its own docblock for the shape contract.
 */
final readonly class IndicatorDTO
{
    /**
     * @param  array<string, string>  $text
     * @param  array<string, string>  $anchor5
     * @param  array<string, string>  $anchor3
     * @param  array<string, string>  $anchor1
     */
    public function __construct(
        public array $text,
        public array $anchor5,
        public array $anchor3,
        public array $anchor1,
        public int $position,
    ) {}
}
