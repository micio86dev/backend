<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use App\Http\Requests\Catalogue\Concerns\ResolvesOpenDraftRevision;
use App\Http\Requests\Catalogue\Concerns\ValidatesLocaleMaps;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/catalogue/competencies/{competency}` (framework-catalogue-
 * authoring PR3).
 */
class UpdateCompetencyRequest extends FormRequest
{
    use ResolvesOpenDraftRevision;
    use ValidatesLocaleMaps;

    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->is_superadmin === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $draftId = $this->openDraftRevisionId();
        $competencyId = (int) $this->route('competency');

        return [
            ...$this->localeMapRules('name', required: false),
            ...$this->localeMapRules('definition', required: false),
            'code' => [
                'sometimes', 'required', 'string', 'max:16', 'regex:/^[A-Z0-9_]+$/',
                Rule::unique('framework_competencies', 'code')->where('revision_id', $draftId)->ignore($competencyId),
            ],
            'type' => ['sometimes', 'required', Rule::in(['standard', 'potential'])],
        ];
    }
}
