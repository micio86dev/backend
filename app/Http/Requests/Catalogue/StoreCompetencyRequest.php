<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use App\Http\Requests\Catalogue\Concerns\ResolvesOpenDraftRevision;
use App\Http\Requests\Catalogue\Concerns\ValidatesLocaleMaps;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/catalogue/competencies` (framework-catalogue-authoring PR3).
 */
class StoreCompetencyRequest extends FormRequest
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

        return [
            ...$this->localeMapRules('name'),
            ...$this->localeMapRules('definition'),
            'code' => [
                'required', 'string', 'max:16', 'regex:/^[A-Z0-9_]+$/',
                Rule::unique('framework_competencies', 'code')->where('revision_id', $draftId),
            ],
            'type' => ['required', Rule::in(['standard', 'potential'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'code_required',
            'code.regex' => 'code_invalid',
            'code.unique' => 'code_taken',
            'type.required' => 'type_required',
            'type.in' => 'type_invalid',
        ];
    }
}
