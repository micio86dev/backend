<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use App\Http\Requests\Catalogue\Concerns\ResolvesOpenDraftRevision;
use App\Http\Requests\Catalogue\Concerns\ValidatesLocaleMaps;
use App\Models\BarsIndicator;
use App\Models\User;
use App\Support\Catalogue\CatalogueRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/catalogue/bars-indicators` (framework-catalogue-authoring
 * PR3, D3). Refuses a 4th indicator for `(revision, role, competency)` —
 * `catalogue-authoring` spec: "Each BARS indicator write MUST enforce
 * exactly 3 indicators per role×competency pair ... at the FormRequest and
 * DB-constraint layer, not only at seed time."
 */
class StoreBarsIndicatorRequest extends FormRequest
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
            ...$this->localeMapRules('text'),
            ...$this->localeMapRules('anchor_5'),
            ...$this->localeMapRules('anchor_3'),
            ...$this->localeMapRules('anchor_1'),
            'competency_id' => [
                'required', 'integer',
                Rule::exists('framework_competencies', 'id')->where('revision_id', $draftId),
                // The 4th-indicator refusal, for (revision, role,
                // competency) — role_id INCLUDED as null for a role-less
                // (`potential`) pair, which has exactly the same 3-per-pair
                // rule as a role-scoped one. Lives on `competency_id`
                // (always present) rather than `role_id` (nullable, so a
                // closure on it only ever sees the value, never "absent
                // entirely" in a way distinguishable from "explicitly
                // null" — both must run this same check).
                function (string $attribute, mixed $value, \Closure $fail) use ($draftId): void {
                    $roleId = $this->input('role_id');

                    $query = BarsIndicator::where('revision_id', $draftId)
                        ->where('competency_id', (int) $value);

                    if ($roleId === null) {
                        $query->whereNull('role_id');
                    } else {
                        $query->where('role_id', (int) $roleId);
                    }

                    if ($query->count() >= CatalogueRules::INDICATORS_PER_PAIR) {
                        $fail('bars_indicator_pair_full');
                    }
                },
            ],
            'position' => ['required', 'integer', 'min:0'],
            // Nullable: a `potential` competency's indicators MUST carry
            // `role_id = null` (PublishRevision's own sweep, D3) — the
            // FormRequest does not refuse null here, only validates the
            // value's shape when present.
            'role_id' => [
                'nullable', 'integer',
                Rule::exists('framework_roles', 'id')->where('revision_id', $draftId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'competency_id.required' => 'competency_id_required',
            'competency_id.exists' => 'competency_not_found_in_draft',
            'role_id.exists' => 'role_not_found_in_draft',
            'position.required' => 'position_required',
        ];
    }
}
