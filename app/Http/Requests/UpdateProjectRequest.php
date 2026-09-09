<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesProjectComposition;
use App\Models\Project;
use App\Models\User;
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
    use ValidatesProjectComposition;

    public function authorize(): bool
    {
        // Resolve project manually within tenant scope (SubstituteBindings runs before TenantContext).
        $projectId = $this->route('project');
        if ($projectId === null) {
            return false;
        }

        $project = $this->resolvedProject();
        if ($project === null) {
            // Project not found in tenant scope → 404 (not 403, so the tenant can't probe
            // whether IDs from other tenants exist).
            abort(404);
        }

        return $this->user()?->can('update', $project) ?? false;
    }

    /**
     * The project this request is about, resolved ONCE.
     *
     * `authorize()`, `rules()` and `withValidator()` each looked it up, and
     * the controller looks it up again — four identical queries per PATCH for
     * one row. Memoized on the request, which lives exactly as long as the
     * request does.
     *
     * Through the tenant scope, never route-model binding: `SubstituteBindings`
     * runs before `TenantContext`, so a bound model resolves with no
     * organization established.
     */
    private ?Project $resolved = null;

    private function resolvedProject(): ?Project
    {
        $id = $this->route('project');

        if ($id === null) {
            return null;
        }

        return $this->resolved ??= Project::find((int) $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $orgId = $user->organization_id;

        $project = $this->resolvedProject();

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
            'name' => ['sometimes', 'string', 'max:255'],
            'assessment_type' => ['sometimes', 'string', Rule::in(['standard', 'potential'])],
            'role_code' => ['sometimes', 'nullable', 'string'],
            'language' => ['sometimes', 'string', Rule::in($supportedLocales)],
            // Approved status enum: draft|active|archived (no gone_live)
            'status' => ['sometimes', 'string', Rule::in(['draft', 'active', 'archived'])],
            'competency_ids' => ['sometimes', 'nullable', 'array', 'list'],
            // `exists` is load-bearing: `validateStandard` iterates
            // `whereIn(...)->get()`, so an unknown id is never looped over and
            // reached the foreign key as a 500.
            'competency_ids.*' => ['integer', 'distinct', Rule::exists('framework_competencies', 'id')],
            'pause_every_n_competencies' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:255'],
            'nudge_min_chars' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            'exit_redirect_url' => ['sometimes', 'nullable', 'string', 'url', 'max:2048'],
            'error_redirect_url' => ['sometimes', 'nullable', 'string', 'url', 'max:2048'],
            'webhook_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            // `sometimes` WITHOUT `nullable`: see `avatarTemplateRule()`.
            'avatar_template_id' => $this->avatarTemplateRule($orgId, 'sometimes'),
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:1024'],
            // Closed event-type set (C10 D10) — not env-overridable, so Rule::in reads
            // the config, never a hardcoded list.
            'webhook_events' => ['sometimes', 'array'],
            'webhook_events.*' => [Rule::in(config('webhooks.events.types'))],
            'deadline_at' => ['sometimes', 'nullable', 'date'],
            'goes_live_at' => ['sometimes', 'nullable', 'date'],
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
            $project = $this->resolvedProject();

            // ABOVE the "basic rules passed" gate, exactly as POST does. That
            // placement is the whole point: when MTG/LAT are missing,
            // `competency_ids.*`'s `exists` rule fails first, the gate below
            // returns, and the operator is told "the selected competency_ids
            // is invalid" — about a catalogue the platform never loaded. Same
            // input, same answer, whichever verb the caller used.
            // RAW, no cast: the value has not been validated yet, and
            // `(string) ['potential']` is an "Array to string conversion"
            // error — a 500 where the rules would answer 422.
            if ($project !== null && $this->guardPotentialCatalog(
                $v,
                $this->input('assessment_type', $project->assessment_type)
            )) {
                return;
            }

            if ($v->errors()->isNotEmpty()) {
                return;
            }

            if ($project === null) {
                $v->errors()->add('project', 'project_not_found');

                return;
            }

            $currentStatus = $project->status;
            $requestedStatus = $this->input('status', $currentStatus);

            // ── Immutability gate ────────────────────────────────────────────
            // assessment_type and role_code are immutable once the resulting status is
            // 'active' OR 'archived'. framework_version_id is always prohibited at the
            // rule layer (never reaches here).
            $immutableStatuses = ['active', 'archived'];
            if (in_array($currentStatus, $immutableStatuses, true) || in_array($requestedStatus, $immutableStatuses, true)) {
                $submittedType = $this->input('assessment_type', $project->assessment_type);
                $submittedRole = $this->input('role_code', $project->role_code);

                // ON THE FIELD THAT MOVED, and one code per field. The
                // guard covers two fields; answering both under
                // `assessment_type` told an operator who changed the ROLE
                // that the assessment type is immutable, and pointed them at
                // a field they never touched. The old string was generic
                // ("cannot change immutable fields") and therefore at least
                // true; a specific code has to be specifically right.
                //
                // Codes rather than sentences for the same reason
                // `framework_version_id.prohibited` already answers with
                // `framework_version_immutable`: this names no competency and
                // no role, so there is nothing a sentence carries that a code
                // does not.
                if ($submittedType !== $project->assessment_type) {
                    $v->errors()->add('assessment_type', 'assessment_type_immutable');
                }

                if ($submittedRole !== $project->role_code) {
                    $v->errors()->add('role_code', 'role_code_immutable');
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
                    // A CODE, for the same reason. The transition pair is
                    // not information the operator needs spelled out: they
                    // chose it, and the UI only ever offers the legal one.
                    $v->errors()->add('status', 'status_transition_forbidden');
                }
            }

            // ── Composition invariants ───────────────────────────────────────
            // The SAME rules POST enforces, and this endpoint enforced none of
            // them. `role_code` was `['nullable', 'string']` and the
            // competencies were unchecked, so on a DRAFT — where the
            // immutability gate above does not apply — a PATCH could set
            // `role_code: "NOT_A_ROLE"`, or hang `potential` competencies off
            // a `standard` project, and get a 200. It surfaced far away and
            // much later, as an interview that could not compose because the
            // role code matched no role.
            //
            // Resolved values, not submitted ones: a PATCH that changes only
            // the competencies still has to be judged against the role the
            // project already has.
            if ($v->errors()->isEmpty()) {
                $submitted = $this->input('competency_ids');

                $type = (string) $this->input('assessment_type', $project->assessment_type);
                $roleCode = $this->input('role_code', $project->role_code);

                // The STORED set is pulled in only when the thing the
                // invariant DEPENDS ON is changing. A PATCH that moves a draft
                // from FLL to ICO, or flips it to `potential`, sends no
                // competencies — and passing `[]` made both branches skip
                // their loop entirely, so the role changed, the competencies
                // did not, and nothing compared them.
                //
                // Deliberately NOT revalidated on every PATCH: a project whose
                // composition is already invalid — written before this rule
                // existed, or straight into the database — would then be
                // frozen, unable to accept even a rename. The invariant is
                // checked at the moment its inputs move, which is the moment
                // it can be broken.
                $composesDifferently = $type !== $project->assessment_type
                    || $roleCode !== $project->role_code;

                // Gate the CALL, not just the lookup. Passing `[]` skips
                // nothing: both branches check `role_code` before they ever
                // reach their `!empty($competencyIds)` guard, so an
                // unconditional call revalidates the role on every PATCH.
                //
                // That bricks the rows this change exists to stop creating.
                // PATCH used to accept `role_code: "NOT_A_ROLE"` with a 200,
                // and such a project could then be promoted to `active` with
                // no composition check on that path. Once active, a rename
                // fails on the composition gate and fixing `role_code` fails
                // on the immutability gate — no way out, in either direction.
                if ($composesDifferently || is_array($submitted)) {
                    $competencyIds = is_array($submitted)
                        ? $submitted
                        : $project->competencies()->pluck('framework_competencies.id')->all();

                    $this->validateComposition($v, $type, $roleCode, $competencyIds);
                }
            }
        });
    }
}
