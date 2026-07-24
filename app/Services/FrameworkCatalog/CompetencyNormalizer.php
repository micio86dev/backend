<?php

declare(strict_types=1);

namespace App\Services\FrameworkCatalog;

use App\Services\FrameworkCatalog\DTO\CompetencyDTO;
use App\Services\FrameworkCatalog\DTO\IndicatorDTO;

/**
 * Adapter that normalizes competency data from either split-file or unified JSON shape
 * into a canonical CompetencyDTO.
 *
 * Split shape:
 *   $competencyEntry = ['code'=>..., 'name'=>..., 'definition'=>..., 'type'=>...]
 *   $barsArray       = [['indicator'=>..., 'scale'=>['5'=>..., '3'=>..., '1'=>...]], ...]
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
 *   scale.5    → anchor5 (stored in BarsIndicator.anchor_5)
 *   scale.3    → anchor3 (stored in BarsIndicator.anchor_3)
 *   scale.1    → anchor1 (stored in BarsIndicator.anchor_1)
 *   array index → position (0-based, stable JSON insertion order)
 */
final class CompetencyNormalizer
{
    /**
     * Normalize a competency entry into a CompetencyDTO with BARS indicators.
     *
     * @param  array<string, mixed>  $competencyEntry  One competency record (with or without 'bars' key).
     * @param  list<array<string, mixed>>|null  $barsArray  BARS indicators (split shape) or null (unified shape).
     */
    public function normalize(array $competencyEntry, ?array $barsArray): CompetencyDTO
    {
        // Auto-detect unified shape: 'bars' key present in the competency entry itself
        $resolvedBars = $barsArray ?? (isset($competencyEntry['bars']) ? $competencyEntry['bars'] : []);

        $indicators = $this->normalizeBars($resolvedBars);

        return new CompetencyDTO(
            code: (string) $competencyEntry['code'],
            name: (string) $competencyEntry['name'],
            definition: (string) $competencyEntry['definition'],
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
            /** @var array{indicator: string, scale: array{5: string, 3: string, 1: string}} $barEntry */
            $scale = $barEntry['scale'];

            $indicators[] = new IndicatorDTO(
                text: (string) $barEntry['indicator'],
                anchor5: (string) $scale['5'],
                anchor3: (string) $scale['3'],
                anchor1: (string) $scale['1'],
                position: $position,
            );
        }

        return $indicators;
    }
}
