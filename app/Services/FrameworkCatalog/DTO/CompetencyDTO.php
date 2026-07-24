<?php

declare(strict_types=1);

namespace App\Services\FrameworkCatalog\DTO;

/**
 * Canonical DTO for a competency with optional BARS indicators.
 *
 * @property list<IndicatorDTO> $indicators
 */
final readonly class CompetencyDTO
{
    /**
     * @param  list<IndicatorDTO>  $indicators
     */
    public function __construct(
        public string $code,
        public string $name,
        public string $definition,
        public string $type,
        public array $indicators = [],
    ) {}
}
