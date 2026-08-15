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
     * `name`/`definition` are translatable (`Competency::$translatable`) but
     * resolve to a scalar STRING at property-read time —
     * `HasTranslations::getAttributeValue()` intercepts every `$this->name`
     * fetch and returns `getTranslation($key, $locale)`, bypassing the
     * `array` cast Scramble's static walk sees (design.md D1). `id` backed
     * by an explicit `(int)` cast.
     *
     * @return array{id: int, code: string, name: string, definition: string, type: string, bars_available: bool}
     *
     * @scramble-return array{id: int, code: string, name: string, definition: string, type: string, bars_available: bool}
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
            // Exposed because `StoreProjectRequest` validates `competency_ids[]`
            // against primary keys, and this catalog is the only surface a
            // client can discover competencies through. Without it the project
            // form can render a competency picker it is structurally unable to
            // submit. The catalog is global — `framework_competencies` carries
            // no `organization_id` — so the key leaks nothing tenant-specific.
            'id' => (int) $competency->id,
            'code' => $competency->code,
            'name' => $competency->name,
            'definition' => $competency->definition,
            'type' => $competency->type,
            'bars_available' => $barsAvailable,
        ];
    }
}
