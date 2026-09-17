<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalogue;

use App\Models\FrameworkDefaultQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CatalogueDefaultQuestionResource — see `CatalogueRoleResource`'s docblock
 * for why this is separate from a candidate/operator-facing resource: full
 * `{en, it}` locale maps, not a single resolved-locale string.
 *
 * @mixin FrameworkDefaultQuestion
 */
class CatalogueDefaultQuestionResource extends JsonResource
{
    /**
     * @return array{id: int, competency_id: int, revision_id: int, position: int, text: array<string, string>}
     *
     * @scramble-return array{id: int, competency_id: int, revision_id: int, position: int, text: array<string, string>}
     */
    public function toArray(Request $request): array
    {
        /** @var FrameworkDefaultQuestion $question */
        $question = $this->resource;

        return [
            'id' => (int) $question->id,
            'competency_id' => (int) $question->competency_id,
            'revision_id' => (int) $question->revision_id,
            'position' => (int) $question->position,
            'text' => $question->getTranslations('text'),
        ];
    }
}
