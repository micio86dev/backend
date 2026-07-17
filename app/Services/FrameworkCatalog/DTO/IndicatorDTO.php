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
 */
final readonly class IndicatorDTO
{
    public function __construct(
        public string $text,
        public string $anchor5,
        public string $anchor3,
        public string $anchor1,
        public int $position,
    ) {}
}
