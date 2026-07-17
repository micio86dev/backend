<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BarsIndicator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BarsIndicatorResource (C3 Framework Catalog).
 *
 * Serializes a BarsIndicator for GET /api/framework/roles/{roleCode}/competencies/{competencyCode}/indicators.
 *
 * translation_gap: true when ANY of the four translatable fields (text, anchor_5, anchor_3, anchor_1)
 * is missing the IT *authoring* translation — detected via hasTranslation('field', 'it').
 * This is an authoring-completeness signal independent of the request's ?locale= parameter.
 * An ?locale=en consumer receiving translation_gap=true should understand IT content is not yet
 * authored, not that the request failed.
 *
 * @mixin BarsIndicator
 */
class BarsIndicatorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BarsIndicator $indicator */
        $indicator = $this->resource;

        return [
            'position' => $indicator->position,
            'text' => $indicator->text,
            'anchor_5' => $indicator->anchor_5,
            'anchor_3' => $indicator->anchor_3,
            'anchor_1' => $indicator->anchor_1,
            'translation_gap' => $indicator->hasTranslationGap(),
        ];
    }
}
