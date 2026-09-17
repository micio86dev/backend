<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalogue;

use App\Models\BarsIndicator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CatalogueBarsIndicatorResource — see `CatalogueRoleResource`'s docblock
 * for why this is separate from `App\Http\Resources\BarsIndicatorResource`
 * (full `{en, it}` locale maps, not a single resolved-locale string).
 *
 * @mixin BarsIndicator
 */
class CatalogueBarsIndicatorResource extends JsonResource
{
    /**
     * @return array{id: int, revision_id: int, role_id: int|null, competency_id: int, position: int, text: array<string, string>, anchor_5: array<string, string>, anchor_3: array<string, string>, anchor_1: array<string, string>}
     *
     * @scramble-return array{id: int, revision_id: int, role_id: int|null, competency_id: int, position: int, text: array<string, string>, anchor_5: array<string, string>, anchor_3: array<string, string>, anchor_1: array<string, string>}
     */
    public function toArray(Request $request): array
    {
        /** @var BarsIndicator $indicator */
        $indicator = $this->resource;

        return [
            'id' => (int) $indicator->id,
            'revision_id' => (int) $indicator->revision_id,
            'role_id' => $indicator->role_id === null ? null : (int) $indicator->role_id,
            'competency_id' => (int) $indicator->competency_id,
            'position' => (int) $indicator->position,
            'text' => $indicator->getTranslations('text'),
            'anchor_5' => $indicator->getTranslations('anchor_5'),
            'anchor_3' => $indicator->getTranslations('anchor_3'),
            'anchor_1' => $indicator->getTranslations('anchor_1'),
        ];
    }
}
