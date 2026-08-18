<?php

declare(strict_types=1);

namespace App\Services\FrameworkCatalog\DTO;

/**
 * Canonical DTO for a competency with optional BARS indicators.
 *
 * Locale dimension (framework-catalog-it-translations, design D1): `name`
 * and `definition` are locale maps, e.g. `['en' => '...', 'it' => '...']` —
 * same shape and same validation contract as `IndicatorDTO`'s fields. See
 * `CompetencyNormalizer` for the shape contract.
 *
 * @property list<IndicatorDTO> $indicators
 */
final readonly class CompetencyDTO
{
    /**
     * @param  array<string, string>  $name
     * @param  array<string, string>  $definition
     * @param  list<IndicatorDTO>  $indicators
     */
    public function __construct(
        public string $code,
        public array $name,
        public array $definition,
        public string $type,
        public array $indicators = [],
    ) {}
}
