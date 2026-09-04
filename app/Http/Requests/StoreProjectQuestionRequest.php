<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Competency;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Support\Settings\PlatformSettings;
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
 *   standard  — at most ONE per competency by default
 *               ("the first question per competency may be predefined")
 *   potential — at most FOUR per competency by default
 *               ("4 predefined questions per competency", SA-08)
 *
 * Those two numbers are now PLATFORM SETTINGS (`App\Support\Settings\
 * PlatformSettings`) rather than a constant here, so a superadmin can move
 * them without a release. They remain platform-level and not tenant-level:
 * the cap describes the assessment method, not a client's preference.
 *
 * The cap is a maximum, never a minimum. A `standard` project with no authored
 * question is the normal case — the AI opens the competency itself, exactly as
 * it does today — and a half-configured `potential` project must be savable
 * while the operator is still writing the other three.
 */
class StoreProjectQuestionRequest extends FormRequest
{
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
                    // Localized: an operator reads this in the backoffice, in
                    // whatever language they are working in. `SetLocaleFromRequest`
                    // is prepended to the whole `api` group, so `__()` already
                    // resolves to the request's locale here.
                    // Both types through the SAME lookup the cap message uses.
                    // Interpolated raw, an Italian operator read "…è di tipo
                    // standard; questo progetto richiede potential." — two
                    // untranslated tokens in a translated sentence, which is
                    // exactly what the sibling key was fixed for.
                    __('messages.project_questions.competency_type_mismatch', [
                        'code' => $competency->code,
                        'actual' => __("messages.project_questions.assessment_type.{$competency->type}"),
                        'expected' => __("messages.project_questions.assessment_type.{$expectedType}"),
                    ]),
                );

                return;
            }

            // Read from the PLATFORM settings, not from a constant in this
            // file. It was `private const MAX_PER_COMPETENCY = ['standard' =>
            // 1, 'potential' => 4]` here, which meant moving it required a
            // release; it is now a superadmin knob with those same numbers as
            // its defaults.
            //
            // Still not a tenant setting. How many predefined questions a
            // `standard` interview may carry is a property of the assessment
            // METHOD — the adaptivity is the product — and a client able to
            // raise it would turn a BARS interview into a questionnaire while
            // still calling it a BARS interview.
            $max = app(PlatformSettings::class)->maxQuestionsPerCompetency($project->assessment_type);

            $existing = ProjectQuestion::where('project_id', $project->id)
                ->where('competency_id', $competency->id)
                ->count();

            if ($existing >= $max) {
                $v->errors()->add(
                    'competency_id',
                    // trans_choice for the count, and a TRANSLATED type: the raw
                    // enum interpolated into an Italian sentence read "Un
                    // progetto standard…" with an untranslated token in the
                    // middle of it, and ":max domande" said "1 domande" at the
                    // default cap.
                    trans_choice('messages.project_questions.max_per_competency', $max, [
                        'type' => __("messages.project_questions.assessment_type.{$project->assessment_type}"),
                    ]),
                );
            }
        });
    }
}
