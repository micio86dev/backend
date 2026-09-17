<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use App\Http\Requests\Catalogue\Concerns\ValidatesLocaleMaps;
use App\Models\BarsIndicator;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/catalogue/bars-indicators/{indicator}` (framework-catalogue-
 * authoring PR3). No 4th-indicator check here — editing text/anchors on an
 * EXISTING row never changes the count for its pair. Reassigning
 * `role_id`/`competency_id` on an existing indicator is out of scope for
 * this PR (D3's twin scopes the count check to creation).
 */
class UpdateBarsIndicatorRequest extends FormRequest
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
        $rules = [
            ...$this->localeMapRules('text', required: false),
            ...$this->localeMapRules('anchor_5', required: false),
            ...$this->localeMapRules('anchor_3', required: false),
            ...$this->localeMapRules('anchor_1', required: false),
        ];

        // K2 (framework-catalogue-authoring PR4b, R3-bars-update-position-
        // 500): reassigning `position` onto an already-occupied slot in the
        // SAME (revision, role, competency) group hit the partial unique
        // index and 500'd with no FormRequest check in the way — mirrors
        // `UpdateDefaultQuestionRequest`'s own fix for the identical defect
        // class. Scoped to the TARGET ROW's own `revision_id`/`role_id`/
        // `competency_id` (read from the database, not the payload — this
        // class accepts no `role_id`/`competency_id` field at all),
        // `ignore()`d against itself. `role_id` is nullable (a role-less
        // `potential` indicator), so the unique check is scoped with
        // `whereNull()` rather than `where(null)`, which Laravel's
        // `Rule::unique()` would otherwise turn into a literal `= NULL`
        // (always false in SQL) instead of `IS NULL`.
        $indicatorId = (int) $this->route('indicator');
        $target = BarsIndicator::find($indicatorId);

        $rules['position'] = ['sometimes', 'required', 'integer', 'min:0'];

        // When the target row does not exist at all, there is no
        // `revision_id`/`role_id`/`competency_id` to scope a uniqueness
        // check against — the controller's own `findOrFail` 404s
        // regardless, the same posture `UpdateDefaultQuestionRequest`
        // already takes.
        if ($target !== null) {
            $unique = Rule::unique('framework_bars_indicators', 'position')
                ->where('revision_id', $target->revision_id)
                ->where('competency_id', $target->competency_id)
                ->ignore($indicatorId);

            $unique = $target->role_id === null
                ? $unique->whereNull('role_id')
                : $unique->where('role_id', $target->role_id);

            $rules['position'][] = $unique;
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'position.unique' => 'position_taken_for_pair',
        ];
    }
}
