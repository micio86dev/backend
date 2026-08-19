<?php

declare(strict_types=1);

namespace App\Http\Controllers\Candidate;

use App\DTOs\Conversation\ComposedPrompt;
use App\Enums\ProviderFailureClass;
use App\Events\CompetencySessionEnded;
use App\Exceptions\Conversation\CompositionException;
use App\Exceptions\ProviderException;
use App\Exceptions\Scoring\AnchorTranslationMissingException;
use App\Http\Controllers\Candidate\Concerns\ResolvesOwnedSession;
use App\Http\Controllers\Controller;
use App\Jobs\FinalizeInterview;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Services\Conversation\SystemPromptComposer;
use App\Services\Provider\HeygenProvider;
use App\Services\Provider\ProviderSessionService;
use App\Services\Provider\ProviderToken;
use App\Services\Provider\QuestionContext;
use App\Services\Provider\TavusProvider;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * InterviewController (C7a — Interview Session Mechanics).
 *
 * Handles:
 *   POST /api/candidate/interview/start — create or resume a provider session for the next competency
 *   POST /api/candidate/interview/end   — end a session, reconcile transcript, dispatch scoring
 *
 * Security:
 * - Provider API keys NEVER reach the response body (task 14.3).
 * - resolveOwnedSession enforces participant_id + org isolation on all session-scoped paths.
 *
 * REQ: /start + /end endpoints (C7a Phase 8.2 + 9.2)
 */
class InterviewController extends Controller
{
    use ResolvesOwnedSession;

    public function __construct(
        private readonly SystemPromptComposer $composer,
    ) {}

    // =========================================================================
    // POST /api/candidate/interview/start
    // =========================================================================

    /**
     * Create or resume a provider session for the next competency interview.
     *
     * Sequence (from design data flow — CRITICAL: provider call is OUTSIDE any DB txn):
     * (1) Resolve next competency by project_competencies.position ASC.
     * (2) Create-or-RESUME: INSERT or catch UniqueConstraintViolationException → re-query.
     * (3) ProviderSessionService.issue() — OUTSIDE any DB transaction.
     * (4a) Provider success → short DB txn: UPDATE session + participant (FIX-8).
     * (4b) Provider 5xx → session error + participant errore + 502.
     * (4c) Provider 429 → session stays pending + 429 provider_busy (NOT errore).
     * (4d) DB failure after provider success → teardown(in-memory token) + 500.
     *
     * RESUME in_corso:
     *   - issue() FRESH token.
     *   - Teardown OLD session via ProviderToken::fromRef($session->provider, $session->provider_session_ref).
     *   - Persist new ref.
     *
     * RESUME pending:
     *   - Retry issue(). On success, persist ref and flip to in_corso.
     *
     * FIX-8: both session UPDATE and participant UPDATE are inside ONE short transaction.
     *
     * @throws \Throwable
     */
    public function start(Request $request): JsonResponse
    {
        /** @var Participant $participant */
        $participant = auth('api-candidate')->user();

        $project = $participant->project;
        $pid = $participant->id;

        // Fail-closed: a participant must always resolve to its project (non-null FK).
        if ($project === null) {
            return response()->json(['error' => 'project_not_found'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // (1) Resolve next competency — lowest position not yet in a terminal-completed state.
        $nextCompetency = $this->resolveNextCompetency($pid, $project->id);

        if ($nextCompetency === null) {
            return response()->json(['error' => 'no_competency_remaining'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // (FIX W1) Guard: only 'standard' assessment type is supported by the composition engine.
        // 'potential' and any future types must not reach composition or the degraded bypass.
        // This check applies on BOTH the fresh-start AND the resume in_corso path.
        // ($project is guaranteed non-null here: the project_not_found early return above
        // narrows it, and project_id is a non-nullable FK.)
        if ($project->assessment_type !== 'standard') {
            return response()->json(['error' => 'assessment_type_not_supported'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // (C8 M-3 / PR2) Compose system prompt BEFORE session creation and provider call.
        //
        // Failure semantics depend on whether this is a RESUME or a NEW session:
        //   NEW/pending path  → fail-fast (422); no InterviewSession created; no provider call.
        //   RESUME in_corso   → degrade gracefully; issue fresh provider session WITHOUT a system
        //                       prompt (legacy non-adaptive body); log a warning. The candidate
        //                       must not be locked out of an in-progress interview due to a
        //                       transient composition failure.
        //
        // We peek BEFORE attempting composition so we can determine the correct failure mode
        // without creating any row or making any provider call.
        $isResumeInCorso = $this->hasActiveInCorsoSession($pid, $nextCompetency['competency_code']);

        $compositionResult = $this->composePromptForCompetency($project, $nextCompetency['competency_code']);
        if ($compositionResult instanceof JsonResponse) {
            if (! $isResumeInCorso) {
                // NEW/pending path — hard fail. No session, no provider call.
                return $compositionResult;
            }

            // RESUME in_corso — degrade: proceed with null system prompt.
            Log::warning('C8: composition failed on resume in_corso path — degrading to legacy non-adaptive body', [
                'participant_id' => $pid,
                'competency_code' => $nextCompetency['competency_code'],
                'composition_error' => $compositionResult->getData(assoc: true)['error'] ?? 'unknown',
            ]);
        }

        // (2) Create-or-RESUME session row.
        $providerName = $project->provider_override ?? config('interview.provider', 'heygen');
        $session = $this->createOrResumeSession($participant, $project, $nextCompetency, $providerName);

        // Re-resolve the provider after we know the project override.
        $providerService = $this->resolveProvider($providerName);

        // Build QuestionContext. On the degraded RESUME path, compositionResult is a JsonResponse
        // (composition failed), so we fall back to null system prompt (no fresh BARS prompt was
        // composed — do NOT fabricate prompt text) but restore the prompt_version from config
        // so /start always returns a non-null prompt_version in every 201 response (FIX C1).
        $systemPrompt = ($compositionResult instanceof JsonResponse) ? null : $compositionResult->text;
        $promptVersion = ($compositionResult instanceof JsonResponse)
            ? (string) config('conversation.prompt_version')
            : $compositionResult->version;

        $ctx = new QuestionContext(
            competencyCode: $session->competency_code,
            questionIndex: $session->question_index,
            // C8: thread composed prompt and version into QuestionContext (M-3).
            // On the degraded RESUME path systemPrompt is null (legacy non-adaptive provider
            // body), but promptVersion is restored from config (FIX C1) — never null in a 201.
            systemPrompt: $systemPrompt,
            promptVersion: $promptVersion,
        );

        // ─── RESUME in_corso path ─────────────────────────────────────────────
        // Session already has a provider_session_ref → it was previously issued.
        if ($session->status === 'in_corso') {
            return $this->handleResumeInCorso($session, $participant, $providerService, $ctx);
        }

        // ─── All pending paths (new, resume-pending, UniqueConstraint recovery) ─
        // The isFirstCompetency flag drives whether participant.started_at + status is stamped.
        // Only the FIRST competency of any participant's interview triggers the started_at stamp.
        // First competency ≡ participant.status = 'in_attesa' (not yet started any interview).
        // If participant.status is already 'in_corso', this is a subsequent competency.
        $isFirst = $participant->status === 'in_attesa';

        return $this->handleIssuePending($session, $participant, $providerService, $ctx, isFirstCompetency: $isFirst);
    }

    // =========================================================================
    // POST /api/candidate/interview/end
    // =========================================================================

    /**
     * End a provider session, reconcile the transcript, and (on last question) dispatch scoring.
     *
     * Sequence (CRITICAL-3 atomicity boundary = steps 3–6 in ONE explicit txn):
     * (1) resolveOwnedSession → 404 if not owned.
     * (2) Validate ended_reason ∈ {completed, timeout, skipped}; reject 'error' → 422 (FIX-11).
     * (3) BEGIN EXPLICIT DB TRANSACTION + SELECT FOR UPDATE on session.
     * (4) FIX-3 guard: if session.status !== 'in_corso' → ROLLBACK → 409.
     * (5) HeyGen: replaceUtterances inside txn. Tavus: no reconcile.
     * (6) UPDATE session status = ended_reason, ended_at = now().
     * (7) Count ended sessions (status ∈ {completed, timeout, skipped}) for this participant+project.
     * (8) Last-question CAS: Participant::where(id, status=in_corso)->update(in_valutazione).
     *     Only if $won === 1: dispatch FinalizeInterview::dispatch($pid)->afterCommit().
     * (9) COMMIT. Return 200.
     */
    public function end(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'integer'],
            'ended_reason' => ['required', 'string', 'in:completed,timeout,skipped'],
        ]);

        // (1) resolveOwnedSession → 404 if not owned.
        $session = $this->resolveOwnedSession((int) $validated['session_id']);

        $endedReason = $validated['ended_reason'];
        $pid = $session->participant_id;
        $projectId = $session->project_id;

        // Determine provider for reconcile
        $providerName = $session->provider;
        $providerService = $this->resolveProvider($providerName);

        // C10 D5: declared before the closure, captured by reference, set ONLY on
        // the success path (last statement inside the closure — every abort() above
        // it throws past this point, so reaching it means the write committed).
        $progress = null;

        // (3) BEGIN EXPLICIT DB TRANSACTION + (4) SELECT FOR UPDATE
        DB::transaction(function () use ($session, $endedReason, $pid, $projectId, $providerService, &$progress): void {
            // Lock the session row (scope MUST cover the status UPDATE in step 6)
            $locked = InterviewSession::lockForUpdate()->find($session->id);

            if ($locked === null) {
                // Session disappeared under us (very rare) — treat as 404
                abort(404);
            }

            // (4) FIX-3 guard: idempotency guard inside the FOR UPDATE lock
            if ($locked->status !== 'in_corso') {
                // NOT re-stamping ended_at, NOT re-dispatching FinalizeInterview
                abort(Response::HTTP_CONFLICT);
            }

            // (5) HeyGen: replaceUtterances inside the txn (inside the lock)
            if ($locked->provider === 'heygen') {
                $transcript = $providerService->reconcileTranscript($locked);
                $this->replaceUtterances($locked, $transcript);
            }
            // Tavus: no reconcile — live /utterance rows are kept as-is.

            // (6) UPDATE session status + ended_at
            $locked->status = $endedReason;
            $locked->ended_at = now();
            $locked->ended_reason = $endedReason;
            $locked->save();

            // (7) Count ended sessions (completed, timeout, skipped) for this participant + project
            $endedCount = InterviewSession::where('participant_id', $pid)
                ->where('project_id', $projectId)
                ->whereIn('status', ['completed', 'timeout', 'skipped'])
                ->count();
            $totalCompetencies = DB::table('project_competencies')
                ->where('project_id', $projectId)
                ->count();

            // (8) Last-question CAS single-winner
            if ($endedCount === $totalCompetencies && $totalCompetencies > 0) {
                $won = Participant::where('id', $pid)
                    ->where('status', 'in_corso')
                    ->update(['status' => 'in_valutazione']);

                if ($won === 1) {
                    // afterCommit() attaches to THIS explicit transaction
                    FinalizeInterview::dispatch($pid)->afterCommit();
                }
            }

            // C10 D5: set ONLY on the success path — every abort() above (:225,
            // :231) throws past this point, so reaching it means the write
            // committed. Captured here (not re-derived after the closure returns)
            // so the emitted competency_code is exactly the one this /end call
            // just ended, even if a later request changes state before the event
            // fires.
            $progress = [
                'participant_id' => $pid,
                'project_id' => $projectId,
                'competency_code' => $session->competency_code,
            ];
            // (9) COMMIT happens at end of DB::transaction closure
        });

        if ($progress !== null) {
            event(new CompetencySessionEnded(
                $progress['participant_id'],
                $progress['project_id'],
                $progress['competency_code'],
            ));
        }

        return response()->json(null, Response::HTTP_OK);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Compose the system prompt for the next competency.
     *
     * Returns a ComposedPrompt on success, or a 422 JsonResponse on failure.
     * This MUST be called BEFORE createOrResumeSession() and BEFORE issue() so that
     * a composition failure leaves zero InterviewSession rows and makes zero provider calls.
     *
     * Failure codes (machine-readable, not localized per BEAI machine-facing response policy):
     *   - 'composition_error'           → CompositionException (empty indicators / bad role)
     *   - 'anchor_translation_missing'  → AnchorTranslationMissingException (missing locale text)
     *
     * REQ: M-3 controller wiring (C8 Phase 5 — task 5.5)
     * RV-3: provider field client confirmation required before live deploy.
     */
    private function composePromptForCompetency(
        ?Project $project,
        string $competencyCode,
    ): ComposedPrompt|JsonResponse {
        if ($project === null) {
            // Participant has no associated project — treat as composition failure.
            return response()->json(['error' => 'composition_error'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Resolve role_id from project.role_code.
        // role_code is required for standard assessments; null for potential (deferred — not in C8).
        $role = Role::where('code', $project->role_code)->first();

        if ($role === null) {
            // role_code set on project but not found in catalog → composition failure.
            return response()->json(['error' => 'composition_error'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Resolve competency_id from competency_code.
        $competency = Competency::where('code', $competencyCode)->first();

        if ($competency === null) {
            return response()->json(['error' => 'composition_error'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $budget = (int) config('conversation.followup_budget', 2);

        try {
            return $this->composer->compose(
                competencyCode: $competencyCode,
                roleId: $role->id,
                competencyId: $competency->id,
                projectLocale: $project->language,
                budget: $budget,
                nudgeMinChars: $project->nudge_min_chars,
            );
        } catch (AnchorTranslationMissingException) {
            return response()->json(['error' => 'anchor_translation_missing'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (CompositionException) {
            return response()->json(['error' => 'composition_error'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Check whether an active in_corso session already exists for this participant + competency.
     *
     * Used as a cheap pre-check BEFORE composition so we can decide the correct failure mode:
     *   - true  → RESUME path → composition failure degrades gracefully (log + null prompt).
     *   - false → NEW/pending path → composition failure returns 422 immediately.
     *
     * A single EXISTS-style count query scoped to (participant_id, competency_code, status=in_corso).
     * This runs before createOrResumeSession() so it never creates any row.
     */
    private function hasActiveInCorsoSession(int $participantId, string $competencyCode): bool
    {
        return InterviewSession::where('participant_id', $participantId)
            ->where('competency_code', $competencyCode)
            ->where('status', 'in_corso')
            ->exists();
    }

    /**
     * Resolve the next competency for this participant (lowest position not yet terminal-completed).
     *
     * terminal-completed = status ∈ {completed, timeout, skipped}
     * pending | in_corso → RESUME that session (no new creation needed).
     *
     * @return array{competency_code: string, question_index: int}|null
     */
    private function resolveNextCompetency(int $participantId, int $projectId): ?array
    {
        // All competencies for the project, ordered by position
        $all = DB::table('project_competencies as pc')
            ->join('framework_competencies as fc', 'fc.id', '=', 'pc.competency_id')
            ->where('pc.project_id', $projectId)
            ->orderBy('pc.position')
            ->select('fc.code as competency_code', 'pc.position')
            ->get();

        // Existing sessions for this participant (non-terminal or terminal)
        $existingStatuses = InterviewSession::where('participant_id', $participantId)
            ->where('project_id', $projectId)
            ->pluck('status', 'competency_code');

        foreach ($all as $row) {
            $status = $existingStatuses->get($row->competency_code);

            if ($status === null) {
                // No session yet → this is the next one to create
                return [
                    'competency_code' => $row->competency_code,
                    'question_index' => $row->position - 1, // 0-based
                ];
            }

            // pending | in_corso → RESUME this competency
            if (in_array($status, ['pending', 'in_corso'], true)) {
                return [
                    'competency_code' => $row->competency_code,
                    'question_index' => $row->position - 1,
                ];
            }

            // completed | timeout | skipped | error → skip to next
        }

        return null; // all competencies terminal-completed
    }

    /**
     * Create a new session row or re-query an existing one (RESUME path).
     *
     * Catches UniqueConstraintViolationException (concurrent double /start) and
     * recovers by re-querying the existing session (→ RESUME, not 500).
     *
     * PostgreSQL note: a unique-constraint violation aborts the current transaction,
     * leaving the connection in a "transaction is aborted" state. We wrap the INSERT
     * in a DB::transaction() (which uses a SAVEPOINT internally when nested) so that
     * the savepoint is rolled back on violation while the outer connection remains clean.
     *
     * @param  array{competency_code: string, question_index: int}  $competency
     */
    private function createOrResumeSession(
        Participant $participant,
        Project $project,
        array $competency,
        string $providerName,
    ): InterviewSession {
        try {
            return DB::transaction(function () use ($participant, $project, $competency, $providerName): InterviewSession {
                return InterviewSession::create([
                    'participant_id' => $participant->id,
                    'project_id' => $project->id,
                    'question_index' => $competency['question_index'],
                    'competency_code' => $competency['competency_code'],
                    'framework_version_id' => $project->framework_version_id,
                    'provider' => $providerName,
                    'status' => 'pending',
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Concurrent double /start: UNIQUE(participant_id, competency_code) violated.
            // PostgreSQL aborted the inner transaction/savepoint; the outer connection is clean.
            // Re-query the existing session → RESUME path.
            return InterviewSession::where('participant_id', $participant->id)
                ->where('competency_code', $competency['competency_code'])
                ->firstOrFail();
        }
    }

    /**
     * Handle the RESUME in_corso path.
     *
     * Re-issue a FRESH provider token, tear down the OLD provider session (best-effort),
     * then persist the new ref.
     *
     * Per WARNING-6: the RESUME teardown wraps the OLD persisted ref via
     * ProviderToken::fromRef($session->provider, $session->provider_session_ref).
     * The compensation teardown (step 4d) uses the IN-MEMORY token from issue().
     */
    private function handleResumeInCorso(
        InterviewSession $session,
        Participant $participant,
        ProviderSessionService $provider,
        QuestionContext $ctx,
    ): JsonResponse {
        // (a) issue() OUTSIDE any DB transaction
        try {
            $freshToken = $provider->issue($session, $ctx);
        } catch (ProviderException $e) {
            return $this->handleProviderFailure($e, $session, $participant);
        }

        // (b) Teardown OLD session (best-effort, non-fatal) — FIX-1
        // Provider name is required by ProviderToken::fromRef (F1).
        // A null provider_session_ref means there is no old provider session to tear down.
        $oldRef = $session->provider_session_ref;
        if ($oldRef !== null) {
            $oldToken = ProviderToken::fromRef($session->provider, $oldRef);
            try {
                $provider->teardown($oldToken);
            } catch (\Throwable $e) {
                // Non-fatal — log and continue (candidate needs the fresh session)
                Log::warning('C7a: teardown of old provider session failed (non-fatal)', [
                    'session_id' => $session->id,
                    'old_ref' => $oldRef,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // (c) Persist new ref in a short DB txn (FIX-8: no participant update needed on RESUME)
        try {
            DB::transaction(function () use ($session, $freshToken): void {
                $session->provider_session_ref = $freshToken->provider_session_ref;
                $session->status = 'in_corso';
                $session->save();
            });
        } catch (\Throwable $e) {
            // DB failure after provider success → teardown the NEW in-memory token (WARNING-6)
            try {
                $provider->teardown($freshToken);
            } catch (\Throwable) {
                Log::error('C7a: teardown compensation failed after DB error', [
                    'session_id' => $session->id,
                ]);
            }

            return response()->json(['error' => 'db_error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->buildSuccessResponse($session, $freshToken, $participant->language, $ctx->promptVersion);
    }

    /**
     * Handle issue() for a pending session (new or existing pending with no ref).
     *
     * Provider call is OUTSIDE any DB transaction.
     * On success: short DB txn updating both session + participant (FIX-8).
     * Failure matrix: 429 → provider_busy; 5xx → errore + 502; DB failure → teardown + 500.
     */
    private function handleIssuePending(
        InterviewSession $session,
        Participant $participant,
        ProviderSessionService $provider,
        QuestionContext $ctx,
        bool $isFirstCompetency,
    ): JsonResponse {
        // Provider call OUTSIDE any DB transaction (design invariant)
        try {
            $token = $provider->issue($session, $ctx);
        } catch (ProviderException $e) {
            return $this->handleProviderFailure($e, $session, $participant);
        }

        // (4a) Provider SUCCESS → short txn (FIX-8: BOTH writes in ONE transaction)
        try {
            DB::transaction(function () use ($session, $token, $participant, $isFirstCompetency): void {
                // UPDATE session status = in_corso + new ref
                $session->provider_session_ref = $token->provider_session_ref;
                $session->status = 'in_corso';
                $session->save();

                // On first competency only: stamp participant.started_at + status (direct property)
                if ($isFirstCompetency) {
                    // started_at is NOT in $fillable → direct property assignment is mandatory
                    $participant->started_at = now();
                    $participant->status = 'in_corso';
                    $participant->save();
                }
            });
        } catch (\Throwable $e) {
            // (4d) DB failure after provider success → teardown in-memory token (WARNING-6)
            try {
                $provider->teardown($token);
            } catch (\Throwable) {
                Log::error('C7a: teardown compensation failed after DB error', [
                    'session_id' => $session->id,
                ]);
            }

            return response()->json(['error' => 'db_error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->buildSuccessResponse($session, $token, $participant->language, $ctx->promptVersion);
    }

    /**
     * Handle provider HTTP failure (ProviderException).
     *
     * PR1 (delta spec D4) — three-way classification, switched ONLY here:
     * (4c) Throttle (429)     → session stays pending, 429 provider_busy (NOT errore).
     * (4d) ClientError (4xx)  → session error, participant UNCHANGED, HTTP 500 (our bug,
     *                           not the provider's — see `markParticipantFailed()` below).
     * (4b) Upstream (5xx/timeout/malformed) → session error, participant → errore, 502.
     *      Unchanged from pre-PR1 behavior.
     */
    private function handleProviderFailure(
        ProviderException $e,
        InterviewSession $session,
        Participant $participant,
    ): JsonResponse {
        return match ($e->failureClass()) {
            // (4c) 429 — retryable; DO NOT flip participant to errore; session stays pending
            ProviderFailureClass::Throttle => response()->json(['error' => 'provider_busy'], Response::HTTP_TOO_MANY_REQUESTS),

            // (4d) A 4xx WE caused (the provider correctly rejected a malformed request).
            // Session is marked error for visibility, but the participant is left
            // UNCHANGED — a payload bug on our side must not permanently burn the
            // candidate. HTTP 500 (our fault), not 502 (which would blame the upstream).
            ProviderFailureClass::ClientError => $this->markSessionError($session, Response::HTTP_INTERNAL_SERVER_ERROR),

            // (4b) Genuine provider failure — unchanged from pre-PR1 behavior.
            ProviderFailureClass::Upstream => $this->markSessionError($session, Response::HTTP_BAD_GATEWAY, $participant),
        };
    }

    /**
     * Mark the session as errored and return the provider_error response.
     *
     * When `$participant` is given, also runs `markParticipantFailed()` — the
     * Upstream-only path. ClientError calls this WITHOUT a participant, so the
     * candidate's status is never touched for a bug on our own side.
     */
    private function markSessionError(
        InterviewSession $session,
        int $httpStatus,
        ?Participant $participant = null,
    ): JsonResponse {
        try {
            $session->status = 'error';
            $session->ended_reason = 'error';
            $session->save();
        } catch (\Throwable) {
            // Best-effort — if even the error update fails, still return the failure status
        }

        if ($participant !== null) {
            $this->markParticipantFailed($participant);
        }

        return response()->json(['error' => 'provider_error'], $httpStatus);
    }

    /**
     * Flip a participant to the terminal `errore` status after a genuine (Upstream)
     * provider failure.
     *
     * SEAM with `participant-error-recovery` (design D5): this method is extracted
     * VERBATIM from the pre-PR1 `handleProviderFailure()` body (formerly :609-617).
     * This change (`liveavatar-contract-alignment`) only CREATES the method and calls
     * it from the Upstream branch above — it does not change what happens inside it.
     * `participant-error-recovery` owns and edits ONLY this method's body (recoverable
     * status, audit log, admin retry) and must not re-shape the classification switch.
     */
    private function markParticipantFailed(Participant $participant): void
    {
        try {
            // in_attesa → errore and in_corso → errore are both allowed (CRITICAL-1)
            if (! in_array($participant->status, ['completato', 'errore'], true)) {
                $participant->status = 'errore';
                $participant->save();
            }
        } catch (\Throwable) {
            // Best-effort
        }
    }

    /**
     * Build a 201 success response.
     *
     * SECURITY: NEVER include API key material. Only the ephemeral token/URL
     * that the client needs to connect to the provider is included.
     *
     * question_context.end_phrase / final_phrase are the localized avatar
     * completion-signal phrases (C7a follow-up — interview-frontend addendum),
     * resolved for the participant's language with a platform-default fallback.
     * The frontend (C7b) consumes them as the SOLE completion-signal source.
     *
     * C8 (M-3): prompt_version added to question_context for audit/traceability.
     * Machine-facing field — returned literally in every locale (not localized).
     *
     * @param  string|null  $language  The participant's language (BCP-ish locale, may be null).
     * @param  string|null  $promptVersion  Composed prompt template version (C8). On the standard
     *                                      path this is the composed prompt's version; on the
     *                                      degraded resume path it falls back to the config
     *                                      version (FIX C1) so a 201 never carries a null version.
     */
    private function buildSuccessResponse(
        InterviewSession $session,
        ProviderToken $token,
        ?string $language,
        ?string $promptVersion = null,
    ): JsonResponse {
        [$endPhrase, $finalPhrase] = $this->resolveCompletionPhrases($language);

        return response()->json([
            'session_id' => $session->id,
            'provider' => $token->provider,
            // HeyGen: token; Tavus: null
            'provider_token' => $token->token,
            // Tavus: conversation_url; HeyGen: null
            'conversation_url' => $token->conversation_url,
            'question_context' => [
                'competency_code' => $session->competency_code,
                'question_index' => $session->question_index,
                // Machine-facing field names stay literal (snake_case); VALUES are localized.
                'end_phrase' => $endPhrase,
                'final_phrase' => $finalPhrase,
                // C8 (M-3): prompt version for audit and traceability.
                // Machine-facing: returned literally, never localized.
                'prompt_version' => $promptVersion,
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Resolve the localized avatar completion-signal phrases for a language.
     *
     * Institutional UX chrome (NOT tenant/BARS content): the same phrases for every
     * project of a given language, stored in lang/{locale}/interview.php.
     *
     * Resolution rule (per interview-frontend delta spec):
     *   1. Use the participant's language when a phrase file exists for it.
     *   2. Otherwise fall back to the platform default language
     *      (config app.fallback_locale) — the fallback phrase is ALWAYS included
     *      (an absent field is a contract violation).
     *
     * Lang::has() checks whether the key resolves for the exact locale (no implicit
     * fallback), so a missing/unknown/null language deterministically falls back.
     *
     * @param  string|null  $language  The participant's language.
     * @return array{0: string, 1: string} [end_phrase, final_phrase]
     */
    private function resolveCompletionPhrases(?string $language): array
    {
        $fallback = (string) config('app.fallback_locale');

        $locale = ($language !== null && Lang::has('interview.end_phrase', $language))
            ? $language
            : $fallback;

        // Both keys resolve to scalar strings (leaf entries in lang/{locale}/interview.php).
        $endPhrase = (string) Lang::get('interview.end_phrase', [], $locale);
        $finalPhrase = (string) Lang::get('interview.final_phrase', [], $locale);

        return [$endPhrase, $finalPhrase];
    }

    /**
     * REPLACE utterances for HeyGen reconciliation (inside the /end txn + lock).
     *
     * DELETE all existing utterances for the session, then INSERT the server transcript.
     * This runs INSIDE the explicit DB transaction + FOR UPDATE lock from /end,
     * preventing a concurrent /utterance from interleaving between DELETE and INSERT.
     *
     * @param  array<int, array{speaker: string, text: string, ts: string}>  $transcript
     */
    private function replaceUtterances(InterviewSession $session, array $transcript): void
    {
        // DELETE all existing utterances for this session
        DB::table('utterances')->where('interview_session_id', $session->id)->delete();

        if (empty($transcript)) {
            return;
        }

        // INSERT the authoritative server transcript
        $rows = array_map(fn (array $row) => [
            'interview_session_id' => $session->id,
            'organization_id' => $session->organization_id,
            'speaker' => $row['speaker'],
            'text' => $row['text'],
            'ts' => $row['ts'],
        ], $transcript);

        DB::table('utterances')->insert($rows);
    }

    /**
     * Resolve the correct ProviderSessionService for the given provider name.
     *
     * This overrides the IoC-bound instance when a project-level override is in effect,
     * ensuring the correct provider class is always used for the current session.
     */
    private function resolveProvider(string $providerName): ProviderSessionService
    {
        return match ($providerName) {
            'tavus' => app(TavusProvider::class),
            default => app(HeygenProvider::class),
        };
    }
}
