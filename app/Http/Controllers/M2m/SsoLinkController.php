<?php

declare(strict_types=1);

namespace App\Http\Controllers\M2m;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Jwt\CandidateTokenFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SsoLinkController (C6 — Participant + SSO Ingress).
 *
 * Mints a short-lived sso-link JWT for a candidate ingress request.
 *
 * Route: POST /api/m2m/sso-link
 * Auth: auth:api-m2m + ability:sso_link:generate
 *
 * Flow:
 *   1. Resolve project SCOPED to the caller's organization (cross-org → 404).
 *   2. Validate entry gates: status=active, goes_live_at, deadline_at.
 *   3. Validate role_code (standard: must match; potential: any supplied → 422).
 *   4. Validate display_name present and non-empty.
 *   5. MINT GATE: check if participant exists with status ∈ {completato, errore} → 409.
 *   6. Mint sso-link JWT via CandidateTokenFactory.
 *
 * Security invariants:
 * - Project is resolved SCOPED to the caller's org: cross-org → 404.
 * - display_name absent/empty → 422 (NOT NULL in DB; prevents 500 at exchange).
 * - role_code for potential → 422 (surfaces integration bugs; do NOT silently null).
 * - Finished candidates (completato/errore) are rejected with 409.
 * - No Redis write at mint (jti consumed only at exchange).
 *
 * REQ: M2M SSO-Link Mint
 */
final class SsoLinkController extends Controller
{
    /**
     * Mint an sso-link JWT.
     *
     * POST /api/m2m/sso-link
     * Auth: auth:api-m2m + ability:sso_link:generate
     *
     * Response (201): { "token": "<sso-link JWT>" }
     */
    public function store(Request $request): JsonResponse
    {
        /** @var ApiClient $client */
        $client = $request->user('api-m2m');
        $clientOrgId = $client->organization_id;

        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'candidate_ref' => ['required', 'string', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
            'role_code' => ['nullable', 'string', 'max:50'],
            'lang' => ['nullable', 'string', 'max:10'],
        ]);

        // 1. Resolve project SCOPED to caller org (cross-org → 404).
        $project = Project::where('organization_id', $clientOrgId)
            ->findOrFail((int) $validated['project_id']);

        // 2. Entry gates (NULL-safe).
        if (! $this->projectIsAccessible($project)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        // 3. Validate role_code.
        $roleCodeError = $this->validateRoleCode($project, $validated['role_code'] ?? null);
        if ($roleCodeError !== null) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['role_code' => [$roleCodeError]],
            ], 422);
        }

        // 4. MINT GATE: check for terminal-status participants.
        $existing = Participant::where('project_id', $project->id)
            ->where('candidate_ref', $validated['candidate_ref'])
            ->value('status');

        if ($existing !== null && in_array($existing, ['completato', 'errore'], true)) {
            return response()->json([
                'message' => 'Conflict: participant has already completed this assessment.',
            ], 409);
        }

        // 5. Mint the sso-link JWT (RAW mint; NO Redis write at this point).
        $lang = $validated['lang'] ?? $project->language ?? config('app.fallback_locale', 'en');

        $token = CandidateTokenFactory::mintSsoLink([
            'candidate_ref' => $validated['candidate_ref'],
            'display_name' => $validated['display_name'],
            'project_id' => $project->id,
            'org_id' => $project->organization_id,
            'role_code' => $validated['role_code'] ?? null,
            'lang' => $lang,
        ]);

        return response()->json(['token' => $token], 201);
    }

    /**
     * Check project entry gates (NULL-safe).
     *
     * status = 'active'
     * AND (goes_live_at IS NULL OR goes_live_at <= now())
     * AND (deadline_at  IS NULL OR deadline_at  >  now())
     */
    private function projectIsAccessible(Project $project): bool
    {
        if ($project->status !== 'active') {
            return false;
        }

        if ($project->goes_live_at !== null && $project->goes_live_at->isAfter(now())) {
            return false;
        }

        if ($project->deadline_at !== null && ! $project->deadline_at->isAfter(now())) {
            return false;
        }

        return true;
    }

    /**
     * Validate role_code against project type.
     *
     * @return string|null Error message if invalid, null if valid.
     */
    private function validateRoleCode(Project $project, ?string $roleCode): ?string
    {
        if ($project->assessment_type === 'potential') {
            // potential projects: ANY supplied role_code is an integration error → 422.
            if ($roleCode !== null) {
                return 'role_code must not be supplied for potential assessment projects.';
            }
        } elseif ($project->assessment_type === 'standard') {
            // standard projects: role_code must match project.role_code if supplied.
            if ($roleCode !== null && $roleCode !== $project->role_code) {
                return 'role_code does not match the project role.';
            }
        }

        return null;
    }
}
