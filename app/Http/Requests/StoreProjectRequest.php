<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Competency;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * StoreProjectRequest (C4 Project Configuration).
 *
 * Validates POST /api/projects payload.
 *
 * Validation layers:
 * 1. Basic rules (assessment_type, framework_version_id org-scoped, slug unique per org,
 *    language ∈ supported_locales, webhook_url url)
 * 2. withValidator cross-field (assessment_type invariants + gap 422):
 *    a. For potential: POTENTIAL_CATALOG_INCOMPLETE check FIRST, then subset validation
 *    b. For standard: role_code ∈ {ICO,FLL,MLL,BUL,SRX}, competencies ⊆ role's pivot, all type=standard
 *    c. For potential: role_code must be null, competencies ⊆ {MTG,LAT}, all type=potential
 */
class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Project::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $orgId = $user->organization_id;

        /** @var list<string> $supportedLocales */
        $supportedLocales = config('app.supported_locales', ['en', 'it']);

        return [
            'framework_version_id' => [
                'required',
                'integer',
                // Org-scoped Rule::exists — prevents cross-org FV pins.
                // NEVER use bare 'exists:framework_versions,id' (bypasses tenant scope).
                Rule::exists('framework_versions', 'id')->where('organization_id', $orgId),
            ],
            'slug' => [
                'required',
                'string',
                'max:255',
                // Org-scoped unique; exclude soft-deleted rows (slug is reusable after soft-delete).
                Rule::unique('projects', 'slug')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'assessment_type' => ['required', 'string', Rule::in(['standard', 'potential'])],
            'role_code' => ['nullable', 'string'],
            'language' => ['required', 'string', Rule::in($supportedLocales)],
            'competency_ids' => ['nullable', 'array'],
            'competency_ids.*' => ['integer'],
            'pause_every_n_competencies' => ['nullable', 'integer', 'min:1', 'max:255'],
            'nudge_min_chars' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'exit_redirect_url' => ['nullable', 'string', 'url', 'max:2048'],
            'webhook_url' => ['nullable', 'url', 'max:2048'],
            'webhook_secret' => ['nullable', 'string', 'max:1024'],
            // Closed event-type set (C10 D10) — not env-overridable, so Rule::in reads
            // the config, never a hardcoded list.
            'webhook_events' => ['sometimes', 'array'],
            'webhook_events.*' => [Rule::in(config('webhooks.events.types'))],
            'deadline_at' => ['nullable', 'date'],
            'goes_live_at' => ['nullable', 'date'],
        ];
    }

    /**
     * Cross-field validation: assessment_type invariants + potential catalog gap.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Only run cross-field validation if basic rules passed
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $type = $this->input('assessment_type');
            $competencyIds = $this->input('competency_ids', []);
            if (! is_array($competencyIds)) {
                $competencyIds = [];
            }

            if ($type === 'potential') {
                $this->validatePotential($v, $competencyIds);
            } elseif ($type === 'standard') {
                $this->validateStandard($v, $competencyIds);
            }
        });
    }

    /**
     * Validate potential assessment_type invariants.
     * Order: POTENTIAL_CATALOG_INCOMPLETE check FIRST, then subset + role_code check.
     *
     * @param  array<int, int>  $competencyIds
     */
    private function validatePotential(Validator $v, array $competencyIds): void
    {
        // 1. role_code must be null for potential
        if ($this->input('role_code') !== null) {
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
    private function validateStandard(Validator $v, array $competencyIds): void
    {
        $roleCode = $this->input('role_code');
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
     * Override failedValidation to return POTENTIAL_CATALOG_INCOMPLETE as a top-level response
     * with a machine-readable 'code' field.
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
