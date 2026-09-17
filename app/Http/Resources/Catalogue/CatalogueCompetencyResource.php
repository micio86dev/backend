<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalogue;

use App\Models\Competency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CatalogueCompetencyResource — see `CatalogueRoleResource`'s docblock for
 * why this is separate from `App\Http\Resources\CompetencyResource` (full
 * `{en, it}` locale maps, not a single resolved-locale string).
 *
 * @mixin Competency
 */
class CatalogueCompetencyResource extends JsonResource
{
    /**
     * @return array{id: int, code: string, revision_id: int, type: string, name: array<string, string>, definition: array<string, string>}
     *
     * @scramble-return array{id: int, code: string, revision_id: int, type: string, name: array<string, string>, definition: array<string, string>}
     */
    public function toArray(Request $request): array
    {
        /** @var Competency $competency */
        $competency = $this->resource;

        return [
            'id' => (int) $competency->id,
            'code' => $competency->code,
            'revision_id' => (int) $competency->revision_id,
            'type' => $competency->type,
            'name' => $competency->getTranslations('name'),
            'definition' => $competency->getTranslations('definition'),
        ];
    }
}
