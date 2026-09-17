<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use App\Http\Requests\Catalogue\Concerns\ValidatesLocaleMaps;
use App\Models\FrameworkDefaultQuestion;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/catalogue/default-questions/{defaultQuestion}` (framework-
 * catalogue-authoring PR4). No `competency_id` re-scoping on PATCH — moving
 * an existing default to another competency is out of scope for this PR,
 * same doctrine as `UpdateBarsIndicatorRequest`'s own note about
 * reassigning `role_id`/`competency_id`.
 */
class UpdateDefaultQuestionRequest extends FormRequest
{
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
        $rules = $this->localeMapRules('text', required: false);
        // `required_with:text`, NOT `sometimes` + `required` (gga review
        // finding, blocking): `sometimes` only runs a rule when the KEY
        // `text.it` is itself present in the payload, which is exactly what
        // lets `PATCH {"text":{"en":"x"}}` — `text` present, `it` absent —
        // pass validation entirely. `required_with:text` is evaluated
        // whenever the SIBLING key `text` is present, which is the actual
        // invariant this class's own previous docblock claimed but did not
        // enforce.
        $rules['text.it'] = ['required_with:text', 'string', self::nonBlank()];

        // Draft-scoped uniqueness re-check on `position` (gga review
        // finding, blocking): the table carries a real
        // `UNIQUE(revision_id, competency_id, position)` constraint
        // (`framework_default_questions_rev_competency_position_unique`),
        // and reassigning `position` onto an already-occupied slot raised an
        // uncaught `QueryException` (HTTP 500) with no FormRequest-level
        // check in the way — this app registers no global `QueryException`
        // handler. Scoped to the TARGET ROW's own `revision_id`/
        // `competency_id` (read from the database, not the payload — this
        // class accepts no `competency_id` field at all), `ignore()`d
        // against itself.
        $defaultQuestionId = (int) $this->route('defaultQuestion');
        $target = FrameworkDefaultQuestion::find($defaultQuestionId);

        $rules['position'] = ['sometimes', 'required', 'integer', 'min:0'];

        // When the target row does not exist at all, there is no
        // `revision_id`/`competency_id` to scope a uniqueness check
        // against — the controller's own `findOrFail` 404s regardless, the
        // same "nothing this request could be updating" posture
        // `UpdateRoleRequest`/`UpdateCompetencyRequest` already take.
        if ($target !== null) {
            $rules['position'][] = Rule::unique('framework_default_questions', 'position')
                ->where('revision_id', $target->revision_id)
                ->where('competency_id', $target->competency_id)
                ->ignore($defaultQuestionId);
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'text.it.required_with' => 'text_it_required',
            'position.unique' => 'position_taken_for_competency',
        ];
    }
}
