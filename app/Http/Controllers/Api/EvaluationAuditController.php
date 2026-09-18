<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AuditEvaluationJob;
use App\Models\Evaluation;
use App\Models\User;
use App\Support\Admin\AdminParticipantReader;
use App\Support\Admin\ParticipantReadScope;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * EvaluationAuditController (scoring-audit-jev, design D12).
 *
 * Route: POST /api/participants/{id}/evaluation/audit
 * Auth: auth:api + TenantContext, throttle:6,1 (routes/api.php)
 *
 * Own route group, adjacent to (NOT inside) the Admin Read API block — it is
 * a WRITE that fans out into up to 18 paid third-party AI calls, never a
 * read.
 *
 * Ordered flow (design D12's own table; each step names the status it owns):
 *   1. `config('scoring.audit.enabled')` — checked FIRST, before any
 *      authorization: a deliberate operator kill-switch state must never be
 *      shadowed by a role check. false -> 409 `audit_disabled`.
 *   2. `EvaluationPolicy::audit` — admin only, model-less, so a non-admin
 *      never learns whether the given participant id even exists (403
 *      before 404, mirrors `ParticipantRecoveryController`).
 *   3. `AdminParticipantReader::read($id, ParticipantReadScope::Evaluation)`
 *      — buys the 404 cross-tenant/unknown case AND the 409
 *      `lifecycle_not_ready` gate (`LifecycleReadGate`, `completato`
 *      required) in one call, auto-rendered by `LifecycleNotReadyException`
 *      (bootstrap/app.php).
 *   4. `Evaluation::where('participant_id', ...)->firstOrFail()` — ambient
 *      tenant scope; the participant is already org-verified by step 3, so a
 *      missing row here means the evaluation data itself does not exist yet
 *      -> 404.
 *   5. `Cache::lock("audit:evaluation:{id}", timeout+120)->get()` — refuses
 *      a double-click with 409 `audit_already_running`, and fails CLOSED
 *      with 409 `audit_lock_unavailable` on a Redis throw. This is the
 *      OPPOSITE direction from the M2M guard's fail-open
 *      (`AppServiceProvider.php`'s `client_revoked` check): failing open
 *      here risks paying a vendor twice for the same evaluation, which is
 *      the more expensive mistake (design D7).
 *   6. `AuditRecorder::record('evaluation.audit_requested', ...)` — never
 *      propagates (its own contract); an accepted request is logged before
 *      the job is dispatched.
 *   7. `AuditEvaluationJob::dispatch($evaluation->id, $actor?->id, owner)`.
 *   8. `202 {"status":"queued","evaluation_id":N}`.
 *
 * REQ: The Audit Trigger Is Admin-Only, Throttled, and Refuses an In-Flight
 *      Duplicate; Audit Runs Are Operator-Triggered Only; An Audit Run
 *      Requires an Existing Completed Evaluation
 *      (openspec/changes/scoring-audit-jev/specs/scoring-audit/spec.md)
 */
final class EvaluationAuditController extends Controller
{
    public function __construct(
        private readonly AdminParticipantReader $reader,
    ) {}

    public function store(Request $request, int $id): JsonResponse
    {
        // (1) Kill switch — BEFORE authorization. A viewer/operator gets the
        // same 409 an admin would, because the platform state is what
        // refuses, not the caller's role.
        if (! (bool) config('scoring.audit.enabled')) {
            return response()->json(['reason' => 'audit_disabled'], 409);
        }

        // (2) 403 before 404 — model-less, mirrors
        // ParticipantRecoveryController::store()'s own ordering.
        $this->authorize('audit', Evaluation::class);

        // (3) Org filter + RBAC (already satisfied above) + the completato
        // lifecycle gate, in one call. Cross-tenant/unknown -> 404
        // (ModelNotFoundException). Not-yet-completato -> 409
        // lifecycle_not_ready (LifecycleNotReadyException, both
        // auto-rendered — bootstrap/app.php).
        $participant = $this->reader->read($id, ParticipantReadScope::Evaluation);

        // (4) Ambient tenant scope. The participant is already org-verified
        // by step 3, so a missing Evaluation row here is a real data gap
        // (completato with no evaluation persisted), never a cross-tenant
        // leak.
        $evaluation = Evaluation::where('participant_id', $participant->id)->firstOrFail();

        $ttlSeconds = AuditEvaluationJob::TIMEOUT_SECONDS + 120;

        try {
            // `Cache::lock()` itself, not only the returned Lock's `get()`,
            // can throw: `RedisStore::lock()` resolves a connection before
            // constructing the Lock object, and a lock-store driver failure
            // is free to fail at either point. Both calls stay inside the
            // SAME try so neither failure point escapes uncaught.
            $lock = Cache::lock("audit:evaluation:{$evaluation->id}", $ttlSeconds);
            $acquired = $lock->get();
        } catch (Throwable) {
            // Redis unavailable -> refuse, not proceed (design D7). Fail
            // CLOSED: the cheaper mistake here is a refused request, not a
            // duplicate vendor charge for the same evaluation.
            return response()->json(['reason' => 'audit_lock_unavailable'], 409);
        }

        if (! $acquired) {
            return response()->json(['reason' => 'audit_already_running'], 409);
        }

        /** @var User|null $actor */
        $actor = $request->user();

        // (6) Never propagates (AuditRecorder's own contract) — an accepted
        // request is logged before the job that will spend money is
        // dispatched.
        app(AuditRecorder::class)->record(
            'evaluation.audit_requested',
            'evaluation',
            $evaluation->id,
            after: [
                'participant_id' => $participant->id,
                'evaluation_id' => $evaluation->id,
            ],
        );

        // (7) The lock owner token travels with the job so it — and only
        // it — may release the lock it was handed (design D7).
        AuditEvaluationJob::dispatch($evaluation->id, $actor?->id, $lock->owner());

        return response()->json([
            'status' => 'queued',
            'evaluation_id' => $evaluation->id,
        ], 202);
    }
}
