<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use App\Http\Requests\Catalogue\Concerns\ResolvesOpenDraftRevision;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /api/catalogue/roles/{role}/competencies` (framework-catalogue-authoring
 * PR8b, `catalogue-authoring/spec.md`'s pivot CRUD requirement — PR3 shipped
 * role/competency/indicator CRUD with no way to change a role's competency
 * SET, so a newly created role could never be made usable).
 *
 * ONE idempotent PUT replaces the whole set — attach, detach and reorder are
 * the same operation on a pivot that already carries a `position` column
 * (D1's `framework_role_competency` shape: PK `(revision_id, role_id,
 * competency_id)` + `position`), not three endpoints that could each apply
 * only partially and leave the set in a state no single request asked for.
 *
 * Read-only draft resolution (`existingOpenDraftRevisionId()`), matching
 * `UpdateRoleRequest`'s own no-auto-open rationale: the role named in the
 * URL either already belongs to an existing open draft or it does not exist
 * to update at all — auto-opening a fresh clone here would copy ~450 rows
 * only to 404 immediately after, since a freshly-cloned role's id can never
 * equal the id in the URL.
 */
class UpdateRoleCompetenciesRequest extends FormRequest
{
    use ResolvesOpenDraftRevision;

    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->is_superadmin === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $draftId = $this->existingOpenDraftRevisionId();
        $roleId = (int) $this->route('role');

        return [
            // `present`, not `required` — an EMPTY array is a legal payload
            // (detach every competency from the role) and Laravel's
            // `required` rule treats an empty array as "absent", which
            // would wrongly refuse the exact "detach everything" case this
            // endpoint exists to support.
            'competency_ids' => [
                'present', 'array', 'list',
                function (string $attribute, mixed $value, Closure $fail) use ($draftId, $roleId): void {
                    // gga review finding (blocking): `array`/`list` are not
                    // `Validator::shouldStopValidating()` rules — only
                    // `present` is, and it passes for a non-array value
                    // (`"abc"`, `null`) just as happily as for a real one.
                    // Without this guard a malformed payload reached
                    // `refuseDetachWithLiveIndicators(..., array $submittedIds, ...)`
                    // as a non-array under `declare(strict_types=1)`, an
                    // uncaught `TypeError`, a 500 for exactly the 422 this
                    // FormRequest exists to produce. The field's own
                    // `array`/`list` rules still fail and are reported
                    // regardless — this closure just stops doing more work
                    // on a value they already refuse.
                    if (! is_array($value)) {
                        return;
                    }

                    $this->refuseDetachWithLiveIndicators($draftId, $roleId, $value, $fail);
                },
            ],
            // `type = 'standard'` only — a `potential` competency (MTG/LAT)
            // belongs to no role by rule (design.md row
            // `CI_NON_ROLE_BARS_FILES`, mirrored by `PublishRevision::
            // potentialInPivotViolations()`'s own blocking publish-sweep
            // check): refusing it here is the SAME rule, enforced earlier.
            // `distinct` refuses a duplicate id in the payload with a 422 —
            // `sync()` would otherwise silently collapse the duplicate keys,
            // and a raw pivot INSERT racing itself is exactly the "422, not
            // 500" this rule exists to prevent.
            'competency_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('framework_competencies', 'id')
                    ->where('revision_id', $draftId)
                    ->where('type', 'standard'),
            ],
        ];
    }

    /**
     * A competency the payload DROPS from the role, while its pair still
     * carries indicators in this draft, is refused by name — the publish
     * sweep's `undeclaredPairIndicatorViolations()` would otherwise report
     * them as orphans the moment a superadmin tried to publish, far away
     * from the edit that actually caused it.
     *
     * Takes a plain `array`, not `list<mixed>`: the closure's own `is_array`
     * guard narrows the value BEFORE the field's own `list` rule has run —
     * a genuinely non-list array (a JSON object) still reaches here, and is
     * handled the same way `array_diff()`/`array_filter()` handle it below
     * regardless of its keys.
     *
     * @param  array<mixed, mixed>  $submittedIds
     */
    private function refuseDetachWithLiveIndicators(?int $draftId, int $roleId, array $submittedIds, Closure $fail): void
    {
        if ($draftId === null) {
            // No open draft — the controller's own `findOrFail` 404s
            // regardless (`existingOpenDraftRevisionId()`'s own contract).
            return;
        }

        $role = Role::where('revision_id', $draftId)->find($roleId);

        if ($role === null) {
            // Role absent from the draft — same 404-regardless contract.
            return;
        }

        $currentIds = $role->competencies()->pluck('framework_competencies.id')->all();
        $submitted = array_map('intval', array_filter($submittedIds, 'is_numeric'));
        $removed = array_diff($currentIds, $submitted);

        if ($removed === []) {
            return;
        }

        $stillAnchored = BarsIndicator::where('revision_id', $draftId)
            ->where('role_id', $roleId)
            ->whereIn('competency_id', $removed)
            ->distinct()
            ->pluck('competency_id');

        if ($stillAnchored->isEmpty()) {
            return;
        }

        $codes = Competency::whereIn('id', $stillAnchored)->pluck('code')->implode(', ');

        $fail("cannot detach — still anchored by indicators in this draft: {$codes}");
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'competency_ids.present' => 'competency_ids_required',
            'competency_ids.*.distinct' => 'competency_id_duplicated',
            'competency_ids.*.exists' => 'competency_not_found_or_not_standard',
        ];
    }
}
