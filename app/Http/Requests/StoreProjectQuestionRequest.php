<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Competency;
use App\Models\Project;
use App\Models\ProjectQuestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates a predefined question
 * (potential-competencies-and-authored-questions, AD-4).
 *
 * HOW MANY questions are allowed is a function of the project's assessment
 * type, and that rule lives HERE rather than in the model or the table: it is
 * the same kind of invariant as `competencies ⊆ {MTG, LAT}` and `role_code
 * must be null`, which already live in the project FormRequests.
 *
 *   standard  — at most ONE per competency
 *               ("the first question per competency may be predefined")
 *   potential — at most FOUR per competency
 *               ("4 predefined questions per competency", SA-08)
 *
 * The cap is a maximum, never a minimum. A `standard` project with no authored
 * question is the normal case — the AI opens the competency itself, exactly as
 * it does today — and a half-configured `potential` project must be savable
 * while the operator is still writing the other three.
 */
class StoreProjectQuestionRequest extends FormRequest
{
    private const MAX_PER_COMPETENCY = ['standard' => 1, 'potential' => 4];

    /**
     * The route parameter is an ID, not a bound model: `SubstituteBindings`
     * runs before `TenantContext`, so binding here would resolve with no
     * organization established. Resolved through the tenant scope instead, and
     * a project belonging to somebody else simply is not found.
     */
    private function project(): ?Project
    {
        $id = $this->route('project');

        return is_numeric($id) ? Project::find((int) $id) : null;
    }

    public function authorize(): bool
    {
        $project = $this->project();

        return $project !== null && ($this->user()?->can('update', $project) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Scoped to the catalogue, not to the project's own competencies:
            // the cross-check that the competency actually belongs to this
            // project's type happens below, where the reason can be stated.
            'competency_id' => ['required', 'integer', 'exists:framework_competencies,id'],
            'text' => ['required', 'array'],
            // `en` is required and `it` is not, matching the catalogue: an
            // English fallback always exists, and a missing Italian degrades
            // to it rather than to an empty question.
            'text.en' => ['required', 'string', 'max:2000'],
            'text.it' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $project = $this->project();

            if ($project === null) {
                return;
            }

            $competency = Competency::find($this->integer('competency_id'));

            if ($competency === null) {
                return;
            }

            // A potential project asks only MTG/LAT and a standard one only
            // the eighteen: an authored question for the wrong kind would be
            // asked in an interview that never covers that competency.
            $expectedType = $project->assessment_type === 'potential' ? 'potential' : 'standard';

            if ($competency->type !== $expectedType) {
                $v->errors()->add(
                    'competency_id',
                    "Competency '{$competency->code}' is type={$competency->type}; this project requires {$expectedType}.",
                );

                return;
            }

            // No `?? 1` fallback: `assessment_type` is a closed union the
            // model's own guards enforce, so the offset always exists and a
            // default would be unreachable code pretending to be safety.
            $max = self::MAX_PER_COMPETENCY[$project->assessment_type];

            $existing = ProjectQuestion::where('project_id', $project->id)
                ->where('competency_id', $competency->id)
                ->count();

            if ($existing >= $max) {
                $v->errors()->add(
                    'competency_id',
                    "A {$project->assessment_type} project allows at most {$max} question(s) per competency.",
                );
            }
        });
    }
}
