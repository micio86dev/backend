<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Events\ParticipantCreated;
use App\Http\Controllers\Controller;
use App\Models\InterviewSession;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Project\ProjectInterviewability;
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
 * Canonical flow (order is security-critical; renumbered for Z14,
 * framework-catalogue-authoring, REQUIRED BEFORE ARCHIVE — see that step's
 * own note):
 *   1. Parse + verify sig + exp (tymon checkOrFail)       → fail: 401
 *   2. Assert typ === 'sso-link'                           → fail: 401
 *   3. Resolve Project::withoutGlobalScope('tenant')->findOrFail($projectId)
 *      [MUST NOT use plain findOrFail or withoutGlobalScopes plural]
 *                                                          → missing: 401
 *   4. `ProjectInterviewability::isInterviewable()` (D5/D6) → fail: 403
 *      generic, BEFORE the jti is consumed (Z14 — see below). EXEMPTED
 *      when this candidate already has an `InterviewSession` on this
 *      project (returning/recovered candidate —
 *      `InterviewSession::withoutGlobalScope('tenant')`, same reason as
 *      step 3's `Project` resolution: this path runs with no tenant
 *      context at all). Resolves the existing Participant row for (org,
 *      project, candidate_ref) as part of the exemption check — reused by
 *      Step 9, never a second read of the same row.
 *   5. ATOMIC Redis SET sso_jti:{jti} NX EX <ttl>         → NX-fail: 401 (replay)
 *      [jti consumed BEFORE every OTHER gate — link spent even on gate
 *      failure, by design (unchanged from before Z14); interviewability is
 *      the ONE deliberate exception, moved above THIS step instead]
 *   6. Assert non-empty display_name claim                 → fail: 401
 *   7. Entry gates (status=active, goes_live_at, deadline_at) → fail: 403 generic
 *   8. role_code belt check                                → fail: 403 generic
 *   9. PRE-FLIGHT read of the Step 4 participant row (primary blocked-status
 *      check); status ≠ in_attesa                          → fail: 403 generic
 *  10. Atomic upsert ON CONFLICT(project_id, candidate_ref) DO UPDATE
 *      WHERE status='in_attesa' (secondary safety net)
 *  11. Mint candidate JWT (setTTL 120)                     → 200 { access_token }
 *
 * Z14 (R4-sso-gate-burns-link, REQUIRED BEFORE ARCHIVE): interviewability
 * used to be checked AFTER the jti was consumed (the old step 6b, after the
 * old step 3) — a candidate whose project became non-interviewable between
 * mint and exchange (an operator's later, correctable misconfiguration,
 * exactly the transient state Z9/Z10 exist to tolerate) hit the 403 AND
 * permanently burned their one-time link in the same request. Once the
 * operator fixed the configuration, the candidate had no way to use that
 * SAME link again — the calling system had to mint and redeliver an
 * entirely new one. Every OTHER gate here still burns the jti regardless of
 * outcome, unchanged: those failures are not the kind of thing a candidate
 * retrying the identical link would ever expect to resolve on its own
 * (a closed/expired project, a role_code mismatch), so nothing is gained by
 * sparing the jti for them, and the "by design" doctrine for THOSE gates
 * stands. The token's own `exp` claim still bounds how long a candidate can
 * keep retrying an interviewability failure before the link dies naturally.
 *
 * Security invariants:
 * - All 403 bodies are GENERIC ("Access denied") — no state information disclosed.
 * - jti consumed at step 5, before display_name check (step 6) and gates (steps 7-9)
 *   — but AFTER interviewability (step 4, Z14).
 * - Project resolved via withoutGlobalScope('tenant') only — keeps SoftDeletingScope.
 * - The Step 4 `InterviewSession` exemption read is likewise
 *   `withoutGlobalScope('tenant')` with an EXPLICIT `organization_id`
 *   filter — never a plain `TenantModel` query on this tenant-context-free
 *   path (it would silently match zero rows forever).
 * - organization_id set from $project->organization_id on INSERT (never from claims).
 * - language chain: sso-link lang → project.language → config('app.fallback_locale').
 *
 * REQ: Public SSO Exchange
 */
final class SsoExchangeController extends Controller
{
    private const GENERIC_403 = 'Access denied.';

    public function __construct(
        private readonly ProjectInterviewability $projectInterviewability,
    ) {}

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

        // ── Step 3: Resolve project (withoutGlobalScope ONLY 'tenant') ────────
        // MUST strip only the named 'tenant' scope — keeps SoftDeletingScope active.
        // withoutGlobalScopes() (plural, no args) MUST NOT be used.
        //
        // Z14 (framework-catalogue-authoring, REQUIRED BEFORE ARCHIVE): moved
        // ahead of jti consumption — see this class's own docblock — solely
        // so Step 4's interviewability check below can run before the jti is
        // spent. Every OTHER reason this used to run after jti consumption
        // (a bogus/expired project_id) still ends in the SAME generic 401,
        // just without also burning a token that could never have succeeded.
        $projectId = (int) $payload->get('project_id');
        try {
            /** @var Project $project */
            $project = Project::withoutGlobalScope('tenant')->findOrFail($projectId);
        } catch (\Throwable) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // ── Step 4: interviewability (framework-catalogue-authoring PR6,
        // D5/D6; moved ahead of jti consumption by Z14) ───────────────────────
        // The candidate cannot fix a configuration problem, so this stays
        // GENERIC_403 like every other gate here — a field present only on
        // THIS branch would disclose which gate fired, defeating the
        // doctrine. No participant row, no session, no webhook exists yet on
        // the FIRST exchange: this runs before the Step 10 upsert.
        //
        // EXEMPTED once this candidate already has an `InterviewSession` on
        // this project (gga review finding, mirrors `InterviewController::
        // start()`'s own exemption): a RETURNING or recovered candidate
        // re-exchanging mid-interview must not be turned away by a LATER,
        // unrelated competency's misconfiguration — `/start`'s own
        // per-competency gate is what actually protects them from there.
        //
        // Existing participant row for THIS (project, candidate_ref), resolved
        // ONCE here and reused by Step 9's blocked-status check further down
        // — never two separate reads of the same row. `organization_id`
        // filtered explicitly alongside `project_id` (gga review finding —
        // the literal tenancy rule, belt over the project-scoped filter that
        // already pins the tenant).
        $candidateRef = (string) $payload->get('candidate_ref');
        $existingParticipant = Participant::where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->where('candidate_ref', $candidateRef)
            ->first();

        // `withoutGlobalScope('tenant')` + an EXPLICIT `organization_id`
        // filter, same shape as Step 3's `Project` resolution — NOT a plain
        // `InterviewSession::where(...)` (gga review finding, 2nd pass):
        // `InterviewSession extends TenantModel`, whose `tenant` global
        // scope filters by the ambient resolver's org id. This path is
        // PUBLIC and runs with no `TenantContext` middleware, so the
        // resolver holds no org at all — a scoped query would compare
        // `organization_id IS NULL` and silently match zero rows forever,
        // the EXACT trap `ProjectInterviewability`'s own docblock warns
        // about on this identical path.
        $hasAnySession = $existingParticipant !== null
            && InterviewSession::withoutGlobalScope('tenant')
                ->where('organization_id', $project->organization_id)
                ->where('participant_id', $existingParticipant->id)
                ->exists();

        if (! $hasAnySession && ! $this->projectInterviewability->isInterviewable($project)) {
            return $this->generic403($project);
        }

        // ── Step 5: ATOMIC Redis SET NX (jti consume BEFORE every OTHER gate,
        // by design — see this class's own docblock; interviewability, Step
        // 4, is the one deliberate exception, checked ABOVE this step) ──────
        $jti = $payload->get('jti');
        $exp = $payload->get('exp');
        $ttl = max((int) ($exp - time()), 60);

        if (! CandidateTokenFactory::consumeJti((string) $jti, $ttl)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // ── Step 6: Assert non-empty display_name claim ───────────────────────
        $displayName = $payload->get('display_name');
        if (empty($displayName)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // ── Step 7: Entry gates (NULL-safe) ───────────────────────────────────
        if (! $this->projectIsAccessible($project)) {
            return $this->generic403($project);
        }

        // ── Step 8: role_code belt check ──────────────────────────────────────
        $roleCode = $payload->get('role_code');
        $roleCodeError = $this->checkRoleCode($project, $roleCode);
        if ($roleCodeError !== null) {
            return $this->generic403($project);
        }

        // ── Step 9: PRE-FLIGHT READ (primary blocked-status check) ────────────
        $existingStatus = $existingParticipant?->status;

        if ($existingStatus !== null && $existingStatus !== 'in_attesa') {
            return $this->generic403($project);
        }

        // C10 D5: "created" is inferred from THIS pre-flight read, captured BEFORE
        // the upsert runs — never re-derived from the post-upsert row (which always
        // exists by the time we could inspect it).
        $isNewCandidate = $existingStatus === null;

        // ── Step 10: Atomic upsert ────────────────────────────────────────────
        // language chain: sso-link lang → project.language → fallback_locale
        $lang = $payload->get('lang')
            ?? $project->language
            ?? config('app.fallback_locale', 'en');

        $now = now()->toDateTimeString();

        // ON CONFLICT(project_id, candidate_ref) DO UPDATE WHERE status='in_attesa'
        // SET clause MUST NOT include organization_id, project_id, or candidate_ref.
        // `participants.email` is NOT NULL (CLAUDE.md ruling 8, reversed
        // 2026-09-01), and this upsert is the only writer on the SSO path. A
        // token minted before the column existed carries no `email` claim, so
        // it falls back to the same deterministic, undeliverable placeholder
        // the backfill migration used rather than failing the exchange: a
        // candidate standing in front of an interview must not be turned away
        // because the link in their inbox predates a schema change.
        // `.local` is reserved by RFC 6762 and resolves nowhere, so the
        // placeholder can never reach a real person, and it is greppable.
        $email = (string) ($payload->get('email') ?? $candidateRef.'@invalid.beai.local');

        DB::statement("
            INSERT INTO participants
                (organization_id, project_id, candidate_ref, display_name, email, role_code, language, status, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, 'in_attesa', ?, ?)
            ON CONFLICT (project_id, candidate_ref)
            DO UPDATE SET
                display_name = EXCLUDED.display_name,
                email        = EXCLUDED.email,
                role_code    = EXCLUDED.role_code,
                language     = EXCLUDED.language,
                updated_at   = EXCLUDED.updated_at
            WHERE participants.status = 'in_attesa'
        ", [
            $project->organization_id,
            $project->id,
            $candidateRef,
            $displayName,
            $email,
            $roleCode,
            $lang,
            $now,
            $now,
        ]);

        // Reload the participant to get the current DB state (handles both insert + update).
        $participant = Participant::where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->where('candidate_ref', $candidateRef)
            ->first();

        if ($participant === null) {
            return $this->generic403($project);
        }

        // C10 D5: post-durability (the upsert already executed) — a concurrent
        // duplicate "creation" is absorbed by the webhook_deliveries unique dedupe
        // index (PR4), never by weakening this atomic upsert.
        if ($isNewCandidate) {
            event(new ParticipantCreated($participant->id, $project->id));
        }

        // ── Step 11: Mint candidate JWT ───────────────────────────────────────
        $token = CandidateTokenFactory::mintCandidateToken($participant);

        return response()->json(['access_token' => $token], 200);
    }

    /**
     * The ONE 403 shape every gate in this controller returns (framework-
     * catalogue-authoring PR6, D6). `redirect_url` — `$project->error_redirect_url`,
     * nullable — is emitted UNIFORMLY on every 403 branch, not only the
     * interviewability one: a field present on a single gate would disclose
     * which gate fired, defeating `GENERIC_403`'s own doctrine. `null` is the
     * normal answer for a project with no configured redirect.
     * `frontend/app/pages/interview/[token].vue`'s 403 branch consumes this
     * field via `useExitRedirect` and falls through to
     * `/interview/terminal?reason=403` when it is null (PR11 — not built
     * here, but this response is what PR11's consumer needs).
     */
    private function generic403(Project $project): JsonResponse
    {
        return response()->json([
            'message' => self::GENERIC_403,
            'redirect_url' => $project->error_redirect_url,
        ], 403);
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
