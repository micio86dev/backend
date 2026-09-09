<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesProjectComposition;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
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
    use ValidatesProjectComposition;

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
            'competency_ids' => ['nullable', 'array', 'list'],
            // `exists` is load-bearing: `validateStandard` iterates
            // `whereIn(...)->get()`, so an unknown id is never looped over and
            // reached the foreign key as a 500.
            'competency_ids.*' => ['integer', 'distinct', Rule::exists('framework_competencies', 'id')],
            'pause_every_n_competencies' => ['nullable', 'integer', 'min:1', 'max:255'],
            'nudge_min_chars' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'exit_redirect_url' => ['nullable', 'string', 'url', 'max:2048'],
            'error_redirect_url' => ['nullable', 'string', 'url', 'max:2048'],
            'webhook_url' => ['nullable', 'url', 'max:2048'],
            // REQUIRED, and org-scoped: see `avatarTemplateRule()` in the
            // trait for why, and for the soft-delete clause.
            'avatar_template_id' => $this->avatarTemplateRule($orgId, 'required'),
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
            $type = $this->input('assessment_type');

            // Above the "basic rules passed" gate — see the trait for why.
            if ($this->guardPotentialCatalog($v, $type)) {
                return;
            }

            // Only run the rest of the cross-field work if basic rules passed
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $competencyIds = $this->input('competency_ids', []);
            if (! is_array($competencyIds)) {
                $competencyIds = [];
            }

            $this->validateComposition($v, $type, $this->input('role_code'), $competencyIds);
        });
    }
}
