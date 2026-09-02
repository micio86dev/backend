<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProjectQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes a predefined interview question.
 *
 * `text` is the RAW locale map, not the locale-resolved accessor: the
 * backoffice edits both languages side by side, so resolving to the operator's
 * locale here would make the other one uneditable and silently droppable.
 *
 * @mixin ProjectQuestion
 */
class ProjectQuestionResource extends JsonResource
{
    /**
     * @return array{id: int, project_id: int, competency_id: int, competency_code: string|null, text: array<string, string>, position: int, created_at: string, updated_at: string}
     *
     * @scramble-return array{id: int, project_id: int, competency_id: int, competency_code: string|null, text: array<string, string>, position: int, created_at: string, updated_at: string}
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectQuestion $question */
        $question = $this->resource;

        /** @var array<string, string> $text */
        $text = $question->getAttribute('text') ?? [];

        return [
            'id' => (int) $question->id,
            'project_id' => (int) $question->project_id,
            'competency_id' => (int) $question->competency_id,
            // The code, so the UI can group and label without a second call.
            // Null when the relation was not eager-loaded — absent, never fatal.
            'competency_code' => $question->competency?->code,
            'text' => $text,
            'position' => (int) $question->position,
            // Not nullsafe: both are non-nullable on this model, and `?->`
            // would advertise a state that cannot occur.
            'created_at' => $question->created_at->toIso8601String(),
            'updated_at' => $question->updated_at->toIso8601String(),
        ];
    }
}
