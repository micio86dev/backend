<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Competency;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * UpdateProjectRequest (C4 Project Configuration).
 *
 * Validates PATCH /api/projects/{id} payload.
 *
 * Key invariants:
 * - framework_version_id (if present): org-scoped Rule::exists (same as Store)
 * - slug: self-ignoring unique rule (->ignore) with soft-delete exclusion
 * - Immutability: changing assessment_type/framework_version_id/role_code when
 *   the resulting status is 'active' → 422
 * - Lifecycle: forbidden transitions (active→draft, archived→active, archived→draft) → 422
 * - framework_version_id comparison: BOTH sides cast to (int) to avoid false positives
 */
class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route parameter 'project' is an int (no implicit model binding for
        // tenant-scoped models — see ProjectController class docblock for rationale).
        // We resolve it manually so the TenantScoped global scope filters correctly.
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
            'status'                     => ['sometimes', 'string', Rule::in(['draft', 'active', 'gone_live', 'archived'])],
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

        // framework_version_id is always immutable after creation — reject if submitted
        // (even when still draft, re-pinning is not allowed in PATCH)
        // The rule 'prohibited' causes a 422 if the field is present in the request.
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
            // Once gone_live (or already active), assessment_type and role_code are immutable.
            // framework_version_id is always immutable (rejected at rule level with 'prohibited').
            $immutableStatuses = ['active', 'gone_live', 'archived'];
            if (in_array($currentStatus, $immutableStatuses, true) || in_array($requestedStatus, $immutableStatuses, true)) {
                $submittedType = $this->input('assessment_type', $project->assessment_type);
                $submittedRole = $this->input('role_code', $project->role_code);

                if ($submittedType !== $project->assessment_type ||
                    $submittedRole !== $project->role_code
                ) {
                    $v->errors()->add('assessment_type', 'Cannot change immutable fields on a gone-live or active project.');
                }
            }

            // ── Lifecycle guard ──────────────────────────────────────────────
            // Allowed forward transitions only:
            //   draft → active
            //   active → gone_live
            //   gone_live → archived
            // Everything else is forbidden.
            $allowed = [
                ['draft',     'active'],
                ['active',    'gone_live'],
                ['gone_live', 'archived'],
            ];

            if ($this->has('status') && $currentStatus !== $requestedStatus) {
                if (! in_array([$currentStatus, $requestedStatus], $allowed, true)) {
                    $v->errors()->add('status', "Status transition '{$currentStatus}' → '{$requestedStatus}' is not allowed.");
                }
            }
        });
    }
}
