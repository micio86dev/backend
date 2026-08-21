<?php

declare(strict_types=1);

namespace App\Http\Controllers\Candidate;

use App\Actions\InterviewSession\ResetSessionForRetry;
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
use App\Services\Conversation\OpeningTextComposer;
use App\Services\Conversation\SystemPromptComposer;
use App\Services\Provider\HeygenProvider;
use App\Services\Provider\ProviderSessionService;
use App\Services\Provider\ProviderToken;
use App\Services\Provider\QuestionContext;
use App\Services\Provider\TavusProvider;
use App\Support\Interview\CompetencyTally;
use App\Support\Interview\SessionLiveClock;
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
        private readonly OpeningTextComposer $openingComposer,
        private readonly SessionLiveClock $liveClock,
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
            // (D5, call site 3) Idempotent self-heal. Every competency is terminal,
            // so this participant is finished whether or not anything settled them
            // at the time. It rescues candidates stranded before this change who
            // come back, and does nothing for anyone already settled — the CAS
            // predicate makes a second call a no-op, not a second dispatch.
            $this->settleCompletionIfFinished($pid, $project->id);

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

        // (PR3) Hoisted here (was previously computed only on the non-resume path, just
        // before handleIssuePending()): both flags are needed to select the opening-greeting
        // VARIANT below, before QuestionContext is built — participant->status cannot change
        // between here and its prior use site, so hoisting is safe (design D9).
        // Only the FIRST competency of any participant's interview triggers the started_at
        // stamp AND the 'first' greeting variant.
        //
        // (participant-error-recovery D2b) First competency ≡ participant.started_at
        // === null — NOT participant.status === 'in_attesa'. A participant recovered
        // from `errore` is flipped back to `in_attesa` by the recovery action but
        // KEEPS its original started_at (it is resuming, not starting fresh). Keying
        // this off status alone would re-greet a recovered candidate as brand new AND
        // silently overwrite started_at to now() below, destroying the true start time.
        $isFirst = $participant->started_at === null;

        // Which sentence ends THIS turn: the last competency gets the final
        // phrase, every other one the intermediate. Resolved from the same
        // source the /start response advertises to the client, so the sentence
        // the avatar is told to say and the one the client watches for can
        // never drift apart.
        [$endPhrase, $finalPhrase] = $this->resolveCompletionPhrases($project->language);
        $isLastCompetency = ($nextCompetency['competency_ordinal'] ?? 0)
            >= ($nextCompetency['total_competencies'] ?? 0);
        $advancePhrase = $isLastCompetency ? $finalPhrase : $endPhrase;

        $compositionResult = $this->composePromptForCompetency(
            $project,
            $nextCompetency['competency_code'],
            $advancePhrase,
        );
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

        // (D2/D3) A re-offered competency is reset to `pending` and its previous
        // attempt's transcript discarded BEFORE the session is resumed, so the
        // competency is never scored on a conversation that mixes two attempts.
        // `error_count` survives the reset — it is the bound.
        if (($nextCompetency['reoffer'] ?? false) === true) {
            $errored = InterviewSession::where('participant_id', $pid)
                ->where('project_id', $project->id)
                ->where('competency_code', $nextCompetency['competency_code'])
                ->first();

            if ($errored !== null) {
                (new ResetSessionForRetry)($errored);
            }
        }

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

        // (PR3, design D9) Compose the opening greeting — INDEPENDENT of the composed
        // system prompt's success/failure. The avatar must never go silent, even on the
        // degraded RESUME path (only the system prompt degrades there, never the greeting).
        // Variant: 'resume' on RESUME in_corso, else 'first' on the participant's very
        // first competency, else 'next'. Locale = $project->language (matches the system
        // prompt, per D9).
        // 'retry' takes precedence over 'first'/'next' (D10): a re-offered competency
        // is being asked AGAIN, and the candidate must be told so. Without it the
        // avatar repeats the same question with no explanation, which reads as not
        // having listened. It cannot collide with 'resume': that variant means an
        // in-progress conversation is continuing, while a re-offer starts over after
        // a failure on our side.
        $isReoffer = ($nextCompetency['reoffer'] ?? false) === true;
        $openingVariant = match (true) {
            $isResumeInCorso => 'resume',
            $isReoffer => 'retry',
            $isFirst => 'first',
            default => 'next',
        };
        $competencyName = Competency::where('code', $nextCompetency['competency_code'])->first()
            ?->getTranslation('name', $project->language) ?? $nextCompetency['competency_code'];
        $openingText = $this->openingComposer->compose($openingVariant, $competencyName, $project->language)->text;

        $ctx = new QuestionContext(
            competencyCode: $session->competency_code,
            questionIndex: $session->question_index,
            // C8: thread composed prompt and version into QuestionContext (M-3).
            // On the degraded RESUME path systemPrompt is null (legacy non-adaptive provider
            // body), but promptVersion is restored from config (FIX C1) — never null in a 201.
            systemPrompt: $systemPrompt,
            promptVersion: $promptVersion,
            // PR3: opening greeting, always composed (never null on this path — see above).
            openingText: $openingText,
            // Hotfix 0.22.1: HeyGen's avatar_persona.language must match the
            // project's configured language (i18n mandate), same source PR3/D9
            // already uses for the opening greeting — never a static env default.
            language: $project->language,
            // D6 — progress, computed by the resolver from the ordered list.
            competencyOrdinal: $nextCompetency['competency_ordinal'] ?? null,
            totalCompetencies: $nextCompetency['total_competencies'] ?? null,
        );

        // ─── RESUME in_corso path ─────────────────────────────────────────────
        // Session already has a provider_session_ref → it was previously issued.
        if ($session->status === 'in_corso') {
            return $this->handleResumeInCorso($session, $participant, $providerService, $ctx);
        }

        // ─── All pending paths (new, resume-pending, UniqueConstraint recovery) ─
        // The isFirstCompetency flag drives whether participant.started_at + status is stamped.
        // If participant.status is already 'in_corso', this is a subsequent competency.
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
     *
     * PR4 (design D7, F1 fix): step (5)'s `reconcileTranscript()` now THROWS
     * `ProviderTranscriptShapeException` on a shape-mismatched transcript response
     * instead of silently degrading to `[]`. Because the throw happens BEFORE
     * `replaceUtterances()` runs its DELETE, and propagates out of THIS transaction
     * closure, `DB::transaction()` rolls back the ENTIRE txn automatically — the
     * DELETE never commits, ended_at is never stamped, and FinalizeInterview is
     * never dispatched. Caught below and surfaced as 502 (Upstream classification).
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
        $directive = null;

        // (3) BEGIN EXPLICIT DB TRANSACTION + (4) SELECT FOR UPDATE
        try {
            DB::transaction(function () use ($session, $endedReason, $pid, $projectId, $providerService, &$progress, &$directive): void {
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

                // (interview-session-started-at, D4) Close the open period
                // alongside the status write, inside the same lock — a
                // no-op on the FIX-3 idempotency guard's second /end call,
                // since that path already aborted above before reaching
                // here.
                $this->liveClock->close($locked, 'end');

                // (7)+(8) Tally + last-question CAS, extracted (D5) so the two other
                // paths that can finish an interview reach the same code.
                $this->settleCompletionIfFinished($pid, $projectId);

                // (D7) The directive, computed HERE — inside the transaction that
                // already holds the counters, so it costs one extra query (the
                // project's cadence) rather than a second tally.
                $directive = $this->buildDirective($pid, $projectId);

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
        } catch (ProviderException $e) {
            // PR4 (F1 fix): a shape-mismatched transcript (ProviderTranscriptShapeException,
            // class Upstream) rolled the WHOLE transaction back — the DELETE never
            // committed, existing utterances are untouched, ended_at was never stamped.
            // Classified Upstream → 502 (same status a genuine 5xx transcript-fetch
            // failure would carry via handleProviderFailure(), kept consistent here
            // even though /end has no participant-touching branch to route through).
            Log::warning('C7a: /end aborted — provider transcript reconciliation failed', [
                'session_id' => $session->id,
                'class' => $e->failureClass()->value,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'provider_error'], Response::HTTP_BAD_GATEWAY);
        }

        if ($progress !== null) {
            event(new CompetencySessionEnded(
                $progress['participant_id'],
                $progress['project_id'],
                $progress['competency_code'],
            ));
        }

        return response()->json($directive, Response::HTTP_OK);
    }

    /**
     * What the client should do next (D7).
     *
     * Computed on the SERVER because the SA-04 pause cadence is TENANT
     * CONFIGURATION (`projects.pause_every_n_competencies`; `null` = never pause).
     * A browser must not re-derive tenant policy, the numbers are already in hand
     * here, and client-side arithmetic is the documented cause of the defect this
     * change removes — the page carried an empty competency list and concluded
     * every interview was over after one question.
     *
     * `done` is evaluated FIRST so a pause is never due on the final competency:
     * a candidate must not be shown a break screen for an interview that is over.
     *
     * A null project fails closed to "no pause" rather than guessing a cadence.
     *
     * @return array{ended_competencies: int, total_competencies: int, next_action: string}
     */
    private function buildDirective(int $participantId, int $projectId): array
    {
        $tally = new CompetencyTally;
        $ended = $tally->ended($participantId, $projectId);
        $total = $tally->total($projectId);

        $pauseEvery = Project::whereKey($projectId)->value('pause_every_n_competencies');

        $nextAction = match (true) {
            $total > 0 && $ended >= $total => 'done',
            $pauseEvery !== null && $pauseEvery > 0 && $ended % $pauseEvery === 0 => 'pause',
            default => 'continue',
        };

        return [
            // Machine-facing: literal in every locale (CLAUDE.md).
            'ended_competencies' => $ended,
            'total_competencies' => $total,
            'next_action' => $nextAction,
        ];
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
        ?string $advancePhrase = null,
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
                // The sentence the avatar must SPEAK to end its turn. Without it
                // the prompt told it to utter a placeholder it had never been
                // given, so no question ever ended by itself.
                advancePhrase: $advancePhrase,
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
        // All competencies for the project, ordered by position.
        // The ARRAY INDEX of this ordered list — not `position` — is what
        // `competency_ordinal` is derived from (D6). `question_index` below is
        // PERSISTED and equals `position` verbatim; `competency_ordinal` is
        // DERIVED per request and always dense (1..N), never stored. The two
        // coincide for a dense, unreordered project — that convergence is a
        // consequence of well-formed data, not the reason `competency_ordinal`
        // exists.
        $all = DB::table('project_competencies as pc')
            ->join('framework_competencies as fc', 'fc.id', '=', 'pc.competency_id')
            ->where('pc.project_id', $projectId)
            ->orderBy('pc.position')
            ->select('fc.code as competency_code', 'pc.position')
            ->get();

        // Existing sessions for this participant: status AND attempts spent, in
        // ONE query. These were two identical selects differing only in the
        // column plucked — the design promised one and the implementation drifted
        // to two, which `sdd-verify` caught. Same rows, same WHERE, so there was
        // never a reason to ask twice.
        $existing = InterviewSession::where('participant_id', $participantId)
            ->where('project_id', $projectId)
            ->get(['competency_code', 'status', 'error_count'])
            ->keyBy('competency_code');

        $total = $all->count();

        foreach ($all->values() as $index => $row) {
            $status = $existing->get($row->competency_code)?->status;

            if ($status === null) {
                // No session yet → this is the next one to create
                return $this->competencyPayload((array) $row, $index, $total);
            }

            // pending | in_corso → RESUME this competency
            if (in_array($status, ['pending', 'in_corso'], true)) {
                return $this->competencyPayload((array) $row, $index, $total);
            }

            // (D2) error with attempts left → RE-OFFER this competency.
            //
            // Ordered deliberately AFTER the pending|in_corso branch: a session
            // already reset to `pending` is caught above and never reaches here, so
            // the ratified participant-sso "Resume, not restart" scenario keeps
            // holding verbatim — the operator recovery path leaves sessions at
            // `pending`, never at `error`.
            //
            // The reset happens at the call site, not here: this method resolves,
            // it does not mutate. Left to a later step it would be one early return
            // away from being skipped.
            if ($status === 'error'
                && ($existing->get($row->competency_code)?->error_count ?? 0) < InterviewSession::MAX_ERROR_ATTEMPTS
            ) {
                return $this->competencyPayload((array) $row, $index, $total, reoffer: true);
            }

            // completed | timeout | skipped | exhausted error → skip to next
        }

        return null; // all competencies terminal-completed
    }

    /**
     * Build the array `resolveNextCompetency()` returns for one row (D5).
     *
     * Collapses the three near-identical literals that used to live at the three
     * return sites above into one construction. `question_index` MUST equal
     * `$row->position` verbatim (D1) — `position` is 0-based at every writer, so
     * the value needs no arithmetic here; the historic `- 1` produced `-1` for a
     * project's first competency.
     *
     * @param  array<string, mixed>  $row  cast from the `project_competencies` ⋈ `framework_competencies` row
     * @return array{competency_code: string, question_index: int, competency_ordinal: int, total_competencies: int, reoffer?: bool}
     */
    private function competencyPayload(array $row, int $index, int $total, bool $reoffer = false): array
    {
        $payload = [
            'competency_code' => (string) $row['competency_code'],
            'question_index' => (int) $row['position'],
            'competency_ordinal' => $index + 1,
            'total_competencies' => $total,
        ];

        if ($reoffer) {
            $payload['reoffer'] = true;
        }

        return $payload;
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

        // (interview-session-started-at, D1/D4) Close the OUTGOING period —
        // deliberately OUTSIDE the `$oldRef !== null` guard below: a period
        // is BEAI's own observation of live time, not a mirror of the
        // provider's ref, and must close even when no provider ref
        // survives. Nested inside the guard, a null-ref row would leak an
        // open period forever and lose its first stretch from every sum.
        // Placed BEFORE the (c) transaction below is deliberate: if (c)
        // fails, the stretch that genuinely ended stays correctly closed
        // rather than being discarded by a later, unrelated write failure.
        $this->liveClock->close($session, 'resume');

        if ($oldRef !== null) {
            // (b0) HARVEST the outgoing session's transcript BEFORE tearing it
            // down. This is the last moment it is readable.
            //
            // Without it a resume silently destroyed everything said before it.
            // Each resume issues a NEW provider_session_ref; at /end,
            // reconcileTranscript() fetches only the surviving session and
            // replaceUtterances() DELETEs the rest. Observed in production: a
            // competency holding the resume greeting and one word, while an
            // un-resumed one held twenty-seven turns — and the candidate had
            // answered all of them. BARS then scored the fragment.
            //
            // Best-effort by design: a transcript we cannot fetch is turns we
            // never had, which is no worse than today. Losing the candidate's
            // access to a live interview over it would be.
            $this->harvestOutgoingTranscript($session, $provider, $oldRef);

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
                // (D2) `??=` — a no-op here for any row that truly resumed
                // (started_at is already set from the first stretch).
                $session->started_at ??= now();
                $session->save();

                // (D1/D4) Open the NEW stretch in the same transaction — the
                // outgoing one was already closed above, before this txn, so
                // the D5 invariant (at most one open period) holds even if
                // this transaction fails and is retried.
                $this->liveClock->open($session, $freshToken->provider_session_ref);
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

        return $this->buildSuccessResponse($session, $freshToken, $ctx->language, $ctx->promptVersion, $ctx->competencyOrdinal, $ctx->totalCompetencies);
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

                // (interview-session-started-at, D2) The FIRST live moment,
                // write-once. `??=` — not `=` — because this site is NOT only
                // the first-stretch path: `ResetSessionForRetry` returns an
                // already-lived session to `pending`, so a re-offered
                // competency reaches here with a start already recorded, and
                // that value must survive (D6).
                $session->started_at ??= now();
                $session->save();

                // (D1/D4) Open a new live period in the SAME transaction as
                // the status write. The plain path always arrives here with
                // no period already open (D5's invariant — a `pending` row
                // holds none).
                $this->liveClock->open($session, $token->provider_session_ref);

                // started_at is NOT in $fillable → direct property assignment is
                // mandatory. Stamped ONLY on the participant's true first
                // competency (isFirstCompetency, keyed off started_at === null —
                // see D2b above), never on a resumed/recovered competency.
                if ($isFirstCompetency) {
                    $participant->started_at = now();
                }

                // (participant-error-recovery D2b) The status transition to
                // in_corso is a SEPARATE concern from the started_at stamp: a
                // recovered participant (status=in_attesa, started_at already
                // set, isFirstCompetency=false) must STILL move to in_corso
                // here, or it is stranded at in_attesa forever — /end's later
                // completion CAS requires status='in_corso' to reach
                // in_valutazione. Guarded so an already in_corso participant
                // (the normal 2nd+ competency path) triggers no redundant write.
                if ($participant->status !== 'in_corso') {
                    $participant->status = 'in_corso';
                }

                if ($participant->isDirty()) {
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

        return $this->buildSuccessResponse($session, $token, $ctx->language, $ctx->promptVersion, $ctx->competencyOrdinal, $ctx->totalCompetencies);
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
        $response = match ($e->failureClass()) {
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

        // (D5, call site 2) The competency that just died may have been the last one.
        //
        // Placed AFTER the switch, never inside markSessionError(). For `Upstream`,
        // markParticipantFailed() has already moved the participant to `errore`, and
        // settleCompletionIfFinished()'s `where('status','in_corso')` predicate then
        // matches zero rows and does nothing — which is exactly right. Settling
        // FIRST would flip in_corso → in_valutazione, dispatch scoring, and then let
        // markParticipantFailed() overwrite it to `errore`: a scored, webhooked
        // candidate sitting at a failure status. One guard, no branch duplication,
        // and it is the same guard /end already relies on.
        //
        // Not "settle on the next /start" instead: after a terminal error the client
        // renders its error screen and may never call anything again. A settlement
        // that depends on a client action which may never come is not a settlement.
        $this->settleCompletionIfFinished($session->participant_id, $session->project_id);

        return $response;
    }

    /**
     * Settle the participant when every competency of the project is terminal (D5).
     *
     * Extracted from `/end` steps (7)+(8) and called from three places, because an
     * interview can finish in three ways and only one of them was covered:
     *   1. `/end` — a competency ends normally.
     *   2. `handleProviderFailure()` — the LAST competency dies at the provider.
     *   3. `start()`'s `no_competency_remaining` branch — idempotent self-heal for a
     *      participant stranded before this change who comes back.
     *
     * THE DEFECT THIS REPAIRS: the tally counted only `completed|timeout|skipped`
     * while `resolveNextCompetency()` treats `error` as terminal and skips it. A
     * participant with one errored competency exhausted every competency while the
     * count stayed at `total - 1`, so the CAS never fired — no scoring, no webhook,
     * no notification, and nothing anywhere reported it.
     *
     * An `error` counts only once it has spent its re-offer. This predicate and the
     * re-offer branch in `resolveNextCompetency()` are two halves of ONE behaviour
     * and cannot ship apart: count errors too early and a single transient provider
     * 4xx — our own bug — ends the interview with no second chance; count them too
     * late and a competency the resolver skips is never tallied, which is the
     * stranding this method exists to end, wearing a different name.
     *
     * The `where('status','in_corso')` predicate is the single-winner guard and the
     * reason this is safe to call from anywhere: a participant already at
     * `in_valutazione`, `errore` or `completato` matches zero rows, so a second call
     * is a no-op rather than a second dispatch.
     */
    private function settleCompletionIfFinished(int $participantId, int $projectId): void
    {
        $tally = new CompetencyTally;
        $endedCount = $tally->ended($participantId, $projectId);
        $totalCompetencies = $tally->total($projectId);

        if ($endedCount !== $totalCompetencies || $totalCompetencies === 0) {
            return;
        }

        $won = Participant::where('id', $participantId)
            ->where('status', 'in_corso')
            ->update(['status' => 'in_valutazione']);

        if ($won === 1) {
            // afterCommit() attaches to the caller's transaction when there is one
            // (/end) and dispatches immediately when there is not (the two /start
            // call sites, which hold no transaction).
            FinalizeInterview::dispatch($participantId)->afterCommit();
        }
    }

    /**
     * Persist the outgoing provider session's turns before it is torn down.
     *
     * Appends rather than replaces: `replaceUtterances()` at /end is authoritative
     * for ONE provider session, and a resumed competency spans several. Anything
     * already stored belongs to an earlier session and must survive.
     */
    private function harvestOutgoingTranscript(
        InterviewSession $session,
        ProviderSessionService $provider,
        string $oldRef,
    ): void {
        try {
            $turns = $provider->reconcileTranscript($session);
        } catch (\Throwable $e) {
            Log::warning('C7a: could not harvest transcript before resume (non-fatal)', [
                'session_id' => $session->id,
                'old_ref' => $oldRef,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($turns === []) {
            return;
        }

        $existing = DB::table('utterances')
            ->where('interview_session_id', $session->id)
            ->count();

        // The live /utterance path may already have stored some of these. Replace
        // this session's slice wholesale with the provider's authoritative copy,
        // which is a superset of what arrived live.
        if ($existing > 0) {
            DB::table('utterances')->where('interview_session_id', $session->id)->delete();
        }

        DB::table('utterances')->insert(array_map(fn (array $row) => [
            'interview_session_id' => $session->id,
            'organization_id' => $session->organization_id,
            'speaker' => $row['speaker'],
            'text' => $row['text'],
            'ts' => $row['ts'],
        ], $turns));

        // Marks everything stored so far as already harvested, so /end's own
        // reconciliation APPENDS its session rather than replacing the lot.
        $session->transcript_harvested_at = now();
        $session->save();
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
            // D1: the attempt is recorded HERE, the sole writer of `status = 'error'`.
            // `ResetSessionForRetry` deliberately never touches this column, so the
            // count survives the reset that re-offers the competency — a counter its
            // own reset clears is not a bound.
            $session->error_count = $session->error_count + 1;
            $session->save();

            // (interview-session-started-at, D4) Close the open period, if
            // any — a no-op for a session that never left `pending` (the
            // ClientError/Throttle branches never reach here at all). KNOWN
            // RESIDUAL: a RESUME whose issue() throws never reaches
            // teardown, so the old provider session may keep billing to its
            // ceiling while this closes the period only up to the moment
            // the failure was detected — a bounded, disclosed under-count,
            // not papered over with an invented end time.
            $this->liveClock->close($session, 'error');
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
     * resolved for the PROJECT's language with a platform-default fallback.
     * (avatar-language-follows-project, D7: they read `participant.language`
     * until the code was brought into line with interview-session/spec.md, which
     * already required the project's. The participant value arrives from
     * unvalidated M2M input and could disagree with everything else the avatar
     * was given.)
     * The frontend (C7b) consumes them as the SOLE completion-signal source.
     *
     * C8 (M-3): prompt_version added to question_context for audit/traceability.
     * Machine-facing field — returned literally in every locale (not localized).
     *
     * @param  string|null  $language  The PROJECT's language (BCP-ish locale, may be null).
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
        ?int $competencyOrdinal = null,
        ?int $totalCompetencies = null,
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
                // D6: 1-based position in the project's competency order, and how
                // many there are. Machine-facing — literal in every locale.
                //
                // `competency_ordinal` is NOT `question_index + 1` as an identity —
                // it is a coincidence of well-formed data. `question_index` is
                // PERSISTED on the session row, frozen at creation, and equals
                // `position` (0-based) verbatim. `competency_ordinal` is DERIVED
                // per request from the ordered list's own array index and is
                // always dense (1..N), whatever `position` holds — it diverges
                // from `question_index + 1` whenever positions are sparse or the
                // project is reordered after a session already exists.
                'competency_ordinal' => $competencyOrdinal,
                'total_competencies' => $totalCompetencies,
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
     *   1. Use the PROJECT's language when a phrase file exists for it.
     *   2. Otherwise fall back to the platform default language
     *      (config app.fallback_locale) — the fallback phrase is ALWAYS included
     *      (an absent field is a contract violation).
     *
     * Lang::has() checks whether the key resolves for the exact locale (no implicit
     * fallback), so a missing/unknown/null language deterministically falls back.
     *
     * @param  string|null  $language  The PROJECT's language.
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
     * PR4 (design finding F1, delta spec D7) — CRITICAL fix: the empty-guard used to
     * sit AFTER the unconditional DELETE, so ANY empty `$transcript` (a transport
     * failure, OR — before this PR — a silently-mismatched response shape) deleted
     * every already-persisted utterance row and inserted nothing. Data destruction,
     * not merely an empty transcript.
     *
     * Fixed by REORDERING the guard before the DELETE, not by adding a nested
     * transaction: `HeygenProvider::reconcileTranscript()` (PR4) now THROWS
     * `ProviderTranscriptShapeException` on any shape mismatch instead of returning
     * `[]` for that reason — so by the time control reaches this method, an empty
     * `$transcript` is ALWAYS a legitimate case (HTTP fetch failure, soft-logged;
     * or a genuinely empty-but-valid `transcript_data: []`), never a parsing bug in
     * disguise. That invariant makes reordering sufficient: DELETE and INSERT stay
     * adjacent and uninterrupted, so they only ever run TOGETHER (never DELETE
     * alone) — the atomic-replace guarantee, without a redundant nested transaction
     * (this method already runs inside the /end explicit transaction + FOR UPDATE
     * lock, which already makes DELETE+INSERT atomic from any concurrent reader's
     * perspective).
     *
     * @param  array<int, array{speaker: string, text: string, ts: string}>  $transcript
     */
    private function replaceUtterances(InterviewSession $session, array $transcript): void
    {
        if (empty($transcript)) {
            return;
        }

        // DELETE-then-INSERT is authoritative for ONE provider session. Once a
        // resume has harvested an earlier one, the stored rows belong to a
        // session this fetch knows nothing about, and deleting them destroys
        // turns the candidate actually spoke. Append in that case.
        if ($session->transcript_harvested_at === null) {
            DB::table('utterances')->where('interview_session_id', $session->id)->delete();
        }

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
