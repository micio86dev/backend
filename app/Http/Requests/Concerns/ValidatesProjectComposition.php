<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Competency;
use App\Models\Role;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * The composition invariants of a project: which role, which competencies.
 *
 * Shared by `StoreProjectRequest` and `UpdateProjectRequest` because they are
 * the same invariants, and only one of the two was enforcing them. POST
 * checked `role_code` against the five roles and every competency against the
 * role's pivot; PATCH checked `role_code` as `['nullable', 'string']` and the
 * competencies not at all — so on a DRAFT (where the immutability guard does
 * not apply) an operator could PATCH `role_code: "NOT_A_ROLE"` and get a 200.
 *
 * The damage surfaced far away and much later: `InterviewController` looks the
 * role up by code, finds nothing, and the interview fails to compose — for a
 * project the API said was fine. The door was guarded and the window was not.
 *
 * `$roleCode` is a parameter rather than read from the request, because on a
 * PATCH the value that matters is "submitted, or the one already stored", and
 * only the caller knows which.
 */
trait ValidatesProjectComposition
{
    /**
     * @param  array<int, int>  $competencyIds
     */
    private function validateComposition(
        Validator $v,
        ?string $type,
        ?string $roleCode,
        array $competencyIds
    ): void {
        if ($type === 'potential') {
            $this->validatePotential($v, $roleCode, $competencyIds);
        } elseif ($type === 'standard') {
            $this->validateStandard($v, $roleCode, $competencyIds);
        }
    }

    /**
     * Validate potential assessment_type invariants.
     * Order: POTENTIAL_CATALOG_INCOMPLETE check FIRST, then subset + role_code check.
     *
     * @param  array<int, int>  $competencyIds
     */
    private function validatePotential(Validator $v, ?string $roleCode, array $competencyIds): void
    {
        // 1. role_code must be null for potential
        if ($roleCode !== null) {
            $v->errors()->add('role_code', 'role_code must be null for potential assessment type.');

            return;
        }

        // 2. POTENTIAL_CATALOG_INCOMPLETE — MUST run BEFORE subset check.
        //    Uses code-based lookup (not type-count) to distinguish which specific codes are missing.
        if (Competency::whereIn('code', ['MTG', 'LAT'])->count() < 2) {
            // Abort with structured 422 — use failedValidation to return a custom response.
            $v->errors()->add('__potential_catalog__', 'POTENTIAL_CATALOG_INCOMPLETE');

            return; // skip subset check (catalog isn't ready)
        }

        // 3. Competencies must be ⊆ {MTG, LAT} and all type='potential'
        if (! empty($competencyIds)) {
            $competencies = Competency::whereIn('id', $competencyIds)->get();

            foreach ($competencies as $competency) {
                if ($competency->type !== 'potential') {
                    $v->errors()->add('competency_ids', "Competency '{$competency->code}' is type=standard; potential projects require only potential-type competencies.");

                    return;
                }
                if (! in_array($competency->code, ['MTG', 'LAT'], true)) {
                    $v->errors()->add('competency_ids', "Competency '{$competency->code}' is not in {MTG, LAT}.");

                    return;
                }
            }
        }
    }

    /**
     * Validate standard assessment_type invariants.
     *
     * @param  array<int, int>  $competencyIds
     */
    private function validateStandard(Validator $v, ?string $roleCode, array $competencyIds): void
    {
        $validRoles = ['ICO', 'FLL', 'MLL', 'BUL', 'SRX'];

        if (! in_array($roleCode, $validRoles, true)) {
            $v->errors()->add('role_code', 'role_code must be one of: '.implode(', ', $validRoles).'.');

            return;
        }

        if (! empty($competencyIds)) {
            // Validate each competency: must be type=standard and assigned to this role
            $role = Role::where('code', $roleCode)->first();
            if ($role === null) {
                $v->errors()->add('role_code', "Role '{$roleCode}' not found in catalog.");

                return;
            }

            $assignedIds = DB::table('framework_role_competency')
                ->where('role_id', $role->id)
                ->pluck('competency_id')
                ->toArray();

            $competencies = Competency::whereIn('id', $competencyIds)->get();

            foreach ($competencies as $competency) {
                if ($competency->type !== 'standard') {
                    $v->errors()->add('competency_ids', "Competency '{$competency->code}' is type=potential; standard projects require only standard-type competencies.");

                    return;
                }
                if (! in_array($competency->id, $assignedIds, true)) {
                    $v->errors()->add('competency_ids', "Competency '{$competency->code}' is not assigned to role '{$roleCode}'.");

                    return;
                }
            }
        }
    }

    /**
     * Translate the `__potential_catalog__` sentinel into the documented
     * response.
     *
     * It lives HERE, with the rule that raises it. The rule was extracted into
     * this trait and its translator was left behind in `StoreProjectRequest`,
     * so a PATCH flipping a draft to `potential` on an unseeded catalog
     * answered with the raw sentinel as a public field name — no `code`, and
     * unrecognisable to a client that already handles the POST contract.
     * One sentinel, one translator.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $errors = $validator->errors();

        if ($errors->has('__potential_catalog__')) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Potential catalog incomplete: MTG/LAT competencies are not seeded.',
                    'code' => 'POTENTIAL_CATALOG_INCOMPLETE',
                ], 422)
            );
        }

        parent::failedValidation($validator);
    }
}
