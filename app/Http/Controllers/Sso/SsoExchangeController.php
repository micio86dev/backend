<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Events\ParticipantCreated;
use App\Http\Controllers\Controller;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Jwt\CandidateTokenFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\JWTAuth;

/**
 * SsoExchangeController (C6 — Participant + SSO Ingress).
 *
 * PUBLIC endpoint — no auth guard, no TenantContext.
 *
 * Route: GET /api/sso/exchange?token=<sso-link JWT>
 * Auth: none (->withoutMiddleware(TenantContext::class))
 *
 * Canonical 10-step flow (order is security-critical):
 *   1. Parse + verify sig + exp (tymon checkOrFail)       → fail: 401
 *   2. Assert typ === 'sso-link'                           → fail: 401
 *   3. ATOMIC Redis SET sso_jti:{jti} NX EX <ttl>         → NX-fail: 401 (replay)
 *      [jti consumed BEFORE gates — link spent even on gate failure, by design]
 *   4. Assert non-empty display_name claim                 → fail: 401
 *   5. Resolve Project::withoutGlobalScope('tenant')->findOrFail($projectId)
 *      [MUST NOT use plain findOrFail or withoutGlobalScopes plural]
 *                                                          → missing: 401
 *   6. Entry gates (status=active, goes_live_at, deadline_at) → fail: 403 generic
 *   7. role_code belt check                                → fail: 403 generic
 *   8. PRE-FLIGHT SELECT status (primary blocked-status check)
 *      status ≠ in_attesa                                  → fail: 403 generic
 *   9. Atomic upsert ON CONFLICT(project_id, candidate_ref) DO UPDATE
 *      WHERE status='in_attesa' (secondary safety net)
 *  10. Mint candidate JWT (setTTL 120)                     → 200 { access_token }
 *
 * Security invariants:
 * - All 403 bodies are GENERIC ("Access denied") — no state information disclosed.
 * - jti consumed at step 3, before display_name check (step 4) and gates (steps 5-8).
 * - Project resolved via withoutGlobalScope('tenant') only — keeps SoftDeletingScope.
 * - organization_id set from $project->organization_id on INSERT (never from claims).
 * - language chain: sso-link lang → project.language → config('app.fallback_locale').
 *
 * REQ: Public SSO Exchange
 */
final class SsoExchangeController extends Controller
{
    private const GENERIC_403 = 'Access denied.';

    /**
     * Exchange a sso-link JWT for a candidate JWT.
     *
     * GET /api/sso/exchange?token=<sso-link JWT>
     * Public — no auth guard.
     */
    public function exchange(Request $request): JsonResponse
    {
        $raw = $request->query('token', '');
        if (! is_string($raw) || $raw === '') {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // ── Step 1: Parse + verify sig + exp ─────────────────────────────────
        try {
            $payload = app(JWTAuth::class)->setToken((string) $raw)->checkOrFail();
        } catch (\Throwable) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // ── Step 2: Assert typ === 'sso-link' ────────────────────────────────
        if ($payload->get('typ') !== 'sso-link') {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // ── Step 3: ATOMIC Redis SET NX (jti consume BEFORE any gate) ────────
        $jti = $payload->get('jti');
        $exp = $payload->get('exp');
        $ttl = max((int) ($exp - time()), 60);

        if (! CandidateTokenFactory::consumeJti((string) $jti, $ttl)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // ── Step 4: Assert non-empty display_name claim ───────────────────────
        $displayName = $payload->get('display_name');
        if (empty($displayName)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // ── Step 5: Resolve project (withoutGlobalScope ONLY 'tenant') ────────
        // MUST strip only the named 'tenant' scope — keeps SoftDeletingScope active.
        // withoutGlobalScopes() (plural, no args) MUST NOT be used.
        $projectId = (int) $payload->get('project_id');
        try {
            /** @var Project $project */
            $project = Project::withoutGlobalScope('tenant')->findOrFail($projectId);
        } catch (\Throwable) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // ── Step 6: Entry gates (NULL-safe) ───────────────────────────────────
        if (! $this->projectIsAccessible($project)) {
            return response()->json(['message' => self::GENERIC_403], 403);
        }

        // ── Step 7: role_code belt check ──────────────────────────────────────
        $roleCode = $payload->get('role_code');
        $roleCodeError = $this->checkRoleCode($project, $roleCode);
        if ($roleCodeError !== null) {
            return response()->json(['message' => self::GENERIC_403], 403);
        }

        // ── Step 8: PRE-FLIGHT READ (primary blocked-status check) ────────────
        $candidateRef = (string) $payload->get('candidate_ref');
        $existingStatus = Participant::where('project_id', $project->id)
            ->where('candidate_ref', $candidateRef)
            ->value('status');

        if ($existingStatus !== null && $existingStatus !== 'in_attesa') {
            return response()->json(['message' => self::GENERIC_403], 403);
        }

        // C10 D5: "created" is inferred from THIS pre-flight read, captured BEFORE
        // the upsert runs — never re-derived from the post-upsert row (which always
        // exists by the time we could inspect it).
        $isNewCandidate = $existingStatus === null;

        // ── Step 9: Atomic upsert ─────────────────────────────────────────────
        // language chain: sso-link lang → project.language → fallback_locale
        $lang = $payload->get('lang')
            ?? $project->language
            ?? config('app.fallback_locale', 'en');

        $now = now()->toDateTimeString();

        // ON CONFLICT(project_id, candidate_ref) DO UPDATE WHERE status='in_attesa'
        // SET clause MUST NOT include organization_id, project_id, or candidate_ref.
        DB::statement("
            INSERT INTO participants
                (organization_id, project_id, candidate_ref, display_name, role_code, language, status, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, 'in_attesa', ?, ?)
            ON CONFLICT (project_id, candidate_ref)
            DO UPDATE SET
                display_name = EXCLUDED.display_name,
                role_code    = EXCLUDED.role_code,
                language     = EXCLUDED.language,
                updated_at   = EXCLUDED.updated_at
            WHERE participants.status = 'in_attesa'
        ", [
            $project->organization_id,
            $project->id,
            $candidateRef,
            $displayName,
            $roleCode,
            $lang,
            $now,
            $now,
        ]);

        // Reload the participant to get the current DB state (handles both insert + update).
        $participant = Participant::where('project_id', $project->id)
            ->where('candidate_ref', $candidateRef)
            ->first();

        if ($participant === null) {
            return response()->json(['message' => self::GENERIC_403], 403);
        }

        // C10 D5: post-durability (the upsert already executed) — a concurrent
        // duplicate "creation" is absorbed by the webhook_deliveries unique dedupe
        // index (PR4), never by weakening this atomic upsert.
        if ($isNewCandidate) {
            event(new ParticipantCreated($participant->id, $project->id));
        }

        // ── Step 10: Mint candidate JWT ───────────────────────────────────────
        $token = CandidateTokenFactory::mintCandidateToken($participant);

        return response()->json(['access_token' => $token], 200);
    }

    /**
     * Check project entry gates (NULL-safe).
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
     * Belt-check role_code at exchange.
     *
     * @return string|null Error string if invalid, null if valid.
     */
    private function checkRoleCode(Project $project, mixed $roleCode): ?string
    {
        if ($project->assessment_type === 'potential') {
            // potential: any non-null role_code → 403 (belt; should have been caught at mint)
            if ($roleCode !== null) {
                return 'role_code must be null for potential projects';
            }
        } elseif ($project->assessment_type === 'standard') {
            // standard: role_code must match project.role_code
            if ($roleCode !== $project->role_code) {
                return 'role_code mismatch';
            }
        }

        return null;
    }
}
