<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Competency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CompetencyResource (C3 Framework Catalog).
 *
 * Serializes a Competency model for GET /api/framework/roles/{roleCode}/competencies.
 * bars_available: computed from a preloaded set of covered competency IDs (N+1-free).
 * Pass the covered set via ->additional(['bars_covered_ids' => [...competency_id...]]).
 *
 * @mixin Competency
 */
class CompetencyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Competency $competency */
        $competency = $this->resource;

        // bars_available: check against the preloaded covered set passed via ->additional()
        // NOT a per-row DB query (that would be N+1).
        $barsCoveredIds = $this->additional['bars_covered_ids'] ?? [];
        $barsAvailable = in_array($competency->id, $barsCoveredIds, true);

        return [
            'code' => $competency->code,
            'name' => $competency->name,
            'definition' => $competency->definition,
            'type' => $competency->type,
            'bars_available' => $barsAvailable,
        ];
    }
}
