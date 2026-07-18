<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * UpdateProjectRequest (C4 Project Configuration).
 *
 * Validates PATCH /api/projects/{id} payload.
 *
 * Key invariants:
 * - framework_version_id: blanket-prohibited in ALL PATCH requests (immutable from creation).
 *   Any PATCH that includes this field is rejected with 422 — even the same value, even on draft.
 * - slug: self-ignoring unique rule (->ignore) with soft-delete exclusion
 * - Immutability: changing assessment_type or role_code when the resulting status is 'active' or
 *   'archived' → 422
 * - Lifecycle: allowed transitions are draft→active and active→archived only.
 *   Forbidden (active→draft, archived→active, archived→draft) → 422.
 *
 * SubstituteBindings note: the route parameter 'project' is an int (no implicit model binding —
 * SubstituteBindings runs BEFORE TenantContext in the api middleware group, so route model binding
 * would resolve Project before the tenant scope is set). Manual findOrFail() inside controller
 * and FormRequest methods ensures the TenantScoped global scope is active at resolution time.
 */
class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Resolve project manually within tenant scope (SubstituteBindings runs before TenantContext).
        $projectId = $this->route('project');
        if ($projectId === null) {
            return false;
        }

        $project = Project::find((int) $projectId);
        if ($project === null) {
            // Project not found in tenant scope → 404 (not 403, so the tenant can't probe
            // whether IDs from other tenants exist).
            abort(404);
        }

        return $this->user()?->can('update', $project) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var \App\Models\User $user */
        $user = $this->user();
        $orgId = $user->organization_id;

        $project = Project::find((int) $this->route('project'));

        /** @var list<string> $supportedLocales */
        $supportedLocales = config('app.supported_locales', ['en', 'it']);

        $rules = [
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                // Self-ignoring unique rule with soft-delete exclusion
                Rule::unique('projects', 'slug')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at')
                    ->ignore($project?->id),
            ],
            'name'                       => ['sometimes', 'string', 'max:255'],
            'assessment_type'            => ['sometimes', 'string', Rule::in(['standard', 'potential'])],
            'role_code'                  => ['nullable', 'string'],
            'language'                   => ['sometimes', 'string', Rule::in($supportedLocales)],
            // Approved status enum: draft|active|archived (no gone_live)
            'status'                     => ['sometimes', 'string', Rule::in(['draft', 'active', 'archived'])],
            'competency_ids'             => ['sometimes', 'nullable', 'array'],
            'competency_ids.*'           => ['integer'],
            'pause_every_n_competencies' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:255'],
            'nudge_min_chars'            => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            'exit_redirect_url'          => ['sometimes', 'nullable', 'string', 'url', 'max:2048'],
            'webhook_url'                => ['sometimes', 'nullable', 'url', 'max:2048'],
            'webhook_secret'             => ['sometimes', 'nullable', 'string', 'max:1024'],
            'deadline_at'                => ['sometimes', 'nullable', 'date'],
            'goes_live_at'               => ['sometimes', 'nullable', 'date'],
        ];

        // framework_version_id is immutable from creation — prohibited in ALL PATCH requests.
        // This rule fires regardless of project status (draft or active) and regardless of
        // whether the submitted value matches the current pin or not.
        // The org-scoped Rule::exists that was here previously is removed: since the field
        // is now blanket-prohibited, existence validation is never reached.
        if ($this->has('framework_version_id')) {
            $rules['framework_version_id'] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * Cross-field validation: immutability enforcement + lifecycle guard.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $project = Project::find((int) $this->route('project'));
            if ($project === null) {
                $v->errors()->add('project', 'Project not found.');
                return;
            }

            $currentStatus   = $project->status;
            $requestedStatus = $this->input('status', $currentStatus);

            // ── Immutability gate ────────────────────────────────────────────
            // assessment_type and role_code are immutable once the resulting status is
            // 'active' OR 'archived'. framework_version_id is always prohibited at the
            // rule layer (never reaches here).
            $immutableStatuses = ['active', 'archived'];
            if (in_array($currentStatus, $immutableStatuses, true) || in_array($requestedStatus, $immutableStatuses, true)) {
                $submittedType = $this->input('assessment_type', $project->assessment_type);
                $submittedRole = $this->input('role_code', $project->role_code);

                if ($submittedType !== $project->assessment_type ||
                    $submittedRole !== $project->role_code
                ) {
                    $v->errors()->add('assessment_type', 'Cannot change immutable fields on an active or archived project.');
                }
            }

            // ── Lifecycle guard ──────────────────────────────────────────────
            // Approved forward transitions only:
            //   draft → active
            //   active → archived
            // Everything else is forbidden.
            $allowed = [
                ['draft',  'active'],
                ['active', 'archived'],
            ];

            if ($this->has('status') && $currentStatus !== $requestedStatus) {
                if (! in_array([$currentStatus, $requestedStatus], $allowed, true)) {
                    $v->errors()->add('status', "Status transition '{$currentStatus}' → '{$requestedStatus}' is not allowed.");
                }
            }
        });
    }
}
