<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use App\Http\Requests\Catalogue\Concerns\ResolvesOpenDraftRevision;
use App\Http\Requests\Catalogue\Concerns\ValidatesLocaleMaps;
use App\Models\FrameworkDefaultQuestion;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/catalogue/default-questions` (framework-catalogue-authoring
 * PR4, catalogue-authoring spec — "Catalogue-Level Default Questions Per
 * Competency").
 *
 * Both `en` AND `it` are mandatory here — deliberately stricter than every
 * other catalogue FormRequest's `localeMapRules()` default (`en` mandatory,
 * `it` optional-but-non-blank-when-present, `StoreRoleRequest`/
 * `StoreCompetencyRequest`/`StoreBarsIndicatorRequest`'s own shape). A
 * default question is a TEMPLATE `ApplyCompetencySelection` (PR5) copies
 * verbatim into `project_questions` the moment a project first selects the
 * competency, in whatever language that project runs in — an operator
 * authoring one in `en` only would silently ship an Italian project a blank
 * question the day it is first selected, with no later gate to catch it.
 */
class StoreDefaultQuestionRequest extends FormRequest
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

        $rules = $this->localeMapRules('text');
        // `localeMapRules()` leaves every non-`en` locale merely
        // sometimes-present — overridden to `required` for this one field,
        // per this class's own docblock. `self::nonBlank()` is the trait's
        // own private closure factory, reachable here because a trait's
        // private members become members of the composing class.
        $rules['text.it'] = ['required', 'string', self::nonBlank()];

        $rules['competency_id'] = [
            'required', 'integer',
            Rule::exists('framework_competencies', 'id')->where('revision_id', $draftId),
        ];

        // A closure, not `Rule::unique()` (gga review finding, blocking): the
        // raw `competency_id` input can fail its OWN `integer`/`exists` rule
        // independently — Laravel still runs every OTHER attribute's rules
        // regardless — and a non-numeric value (`"abc"`) fed straight into
        // `Rule::unique()->where('competency_id', ...)` reaches Postgres as a
        // malformed `bigint` literal, raising an uncaught `QueryException`
        // (HTTP 500) instead of the 422 this whole FormRequest exists to
        // produce. Guarded to a no-op when `competency_id` is not numeric —
        // `competency_id`'s own rule above still fails the request.
        $competencyId = $this->input('competency_id');

        $rules['position'] = [
            'required', 'integer', 'min:0',
            function (string $attribute, mixed $value, Closure $fail) use ($draftId, $competencyId): void {
                if (! is_numeric($competencyId)) {
                    return;
                }

                $taken = FrameworkDefaultQuestion::where('revision_id', $draftId)
                    ->where('competency_id', (int) $competencyId)
                    ->where('position', (int) $value)
                    ->exists();

                if ($taken) {
                    $fail('position_taken_for_competency');
                }
            },
        ];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'text.it.required' => 'text_it_required',
            'competency_id.required' => 'competency_id_required',
            'competency_id.exists' => 'competency_not_found_in_draft',
            'position.required' => 'position_required',
        ];
    }
}
