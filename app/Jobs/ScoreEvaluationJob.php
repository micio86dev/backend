<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Scoring\ResolveEvaluationTerminalState;
use App\Actions\Scoring\ScoreCompetency;
use App\Contracts\LLMProvider;
use App\Enums\EvaluationStatus;
use App\Events\EvaluationFailed;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\InterviewSession;
use App\Models\Participant;
use App\Models\Role;
use App\Services\Conversation\BarsIndicatorLoader;
use App\Services\Scoring\Contracts\ReliabilityStrategy;
use App\Services\Scoring\Contracts\ValidityPredicate;
use App\Services\Scoring\EvaluationParser;
use App\Services\Scoring\ExcerptValidator;
use App\Services\Scoring\IndicatorValidator;
use App\Services\Scoring\MeanCalculator;
use App\Services\Scoring\PromptBuilder;
use App\Services\Scoring\TranscriptAssembler;
use App\Support\Catalogue\CatalogueRevisionResolver;
use App\Support\PublicApi\InterviewEventRecorder;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ScoreEvaluationJob — async BARS scoring orchestrator (C9 — Scoring Engine).
 *
 * Dispatched from FinalizeInterview (via ScoringRequested event + DispatchScoringJob
 * listener — wired in PR3). Processes all competencies sequentially.
 *
 * PR3 SCOPE: ReliabilityStrategy, ValidityPredicate, CompletionGate, lifecycle resolution,
 *   EvaluationCompleted event, ZeroCompetencies invariant guard, FinalizeInterview hook wired.
 *   CassetteLLMProvider / FakeLLMProvider (real binding blocked by D25 gap D7).
 *   TranscriptAssembler → PromptBuilder → LLMProvider.complete() → EvaluationParser
 *   → IndicatorValidator → ExcerptValidator → MeanCalculator → ReliabilityStrategy
 *   → ValidityPredicate → CompletionGate → lifecycle in_valutazione→completato → EvaluationCompleted.
 *
 * Start-of-job guard (D2 CC4 — idempotency):
 *   Step 1: participant.status == 'errore' → no-op (log + return).
 *   Step 2: load Evaluation row → branch:
 *     - No row: create Evaluation(status=processing, versions) → score normally.
 *     - 23505 concurrent INSERT: catch + reload + re-enter guard.
 *     - {completed|pending} + retry_attempt=false (database) → no-op.
 *     - pending + retry_attempt=true (database) + participant in_valutazione →
 *       RT-B merge (delete invalid results, flip to processing, one transaction),
 *       then the resume-skip loop re-scores what has no result.
 *     - completed + retry_attempt=true, or pending + retry while the participant
 *       has not re-interviewed → logged no-op.
 *     - processing → resume-skip path (skips already-scored competencies).
 *
 * Per-competency loop (D2 D3 D4):
 *   - Resume-skip: skip competencies with existing CompetencyResult.
 *   - RoleNoBarsException → persist unscorable (role_no_bars), no LLM call.
 *   - AnchorTranslationMissingException → persist unscorable (anchor_translation_missing), no LLM call.
 *   - JSON/count/score/excerpt parse errors → persist unscorable (llm_parse_error), no queue retry.
 *   - Success → persist ai_requests + CompetencyResult + IndicatorScores in ONE transaction.
 *   - UniqueConstraintViolationException on CompetencyResult INSERT → skip (CW5).
 *
 * failed() (D9 CC5):
 *   (a) Guard: transition participant in_valutazione → errore ONLY if currently in_valutazione.
 *   (b) ALWAYS emit EvaluationFailed($participantId) regardless of transition outcome.
 *
 * REQ: ScoreEvaluationJob full pipeline (C9 D2/D3/D4/D9)
 */
class ScoreEvaluationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum queue-level retry attempts.
     *
     * Distinct from domain retry RT-B (D10). Queue retries handle transient
     * infrastructure failures (DB blip, network timeout). RT-B handles domain
     * re-scoring after a 'pending' evaluation.
     */
    public int $tries = 3;

    /**
     * Per-job execution ceiling in seconds.
     *
     * Derived from the sequential per-competency LLM loop (handle() ->
     * runScoringPipeline() -> scoreCompetency(), one Anthropic call per
     * competency, no retry()): App\Support\Queue\QueueRuntimeInvariant::MAX_ROLE_COMPETENCIES
     * (18, single source of truth — CLAUDE.md max per role) x
     * scoring.anthropic.timeout_seconds (default 60) x 1.1 safety margin =
     * 1188s, rounded up to 1200s (20 min). MUST independently exceed the
     * config-independent 600s floor (queue-runtime/spec.md) and MUST stay
     * below queue.runtime.worker_timeout and connections.*.retry_after —
     * enforced by tests/Unit/QueueRuntimeConfigTest.php.
     */
    public int $timeout = 1200;

    /**
     * Backoff in seconds between queue-level retry attempts.
     */
    public int $backoff = 60;

    public function __construct(
        private readonly int $participantId,
        private readonly bool $retryAttempt = false,
    ) {}

    /**
     * Execute the scoring job.
     *
     * PR2: Full pipeline wired — start-of-job guard + scoring pipeline.
     *
     * Tenant context (queued-job-tenancy PR2): steps 1 and the org-derivation
     * guard run OUTSIDE any tenant context — the org is not yet known, and no
     * tenant-scoped write happens here. Once the org is derived from the
     * participant's own DB record, the ENTIRE remaining pipeline (evaluation
     * guard, scoring loop, gate, lifecycle, EvaluationCompleted) runs inside
     * ONE TenantContextScope::runFor() boundary (design D3 — one wrapper, not
     * one per write site).
     */
    public function handle(): void
    {
        // ── Step 1: Participant errore guard ──────────────────────────────────
        // Runs BEFORE loading any Evaluation row. errore is the terminal guard:
        // once a participant is errore, no further scoring is ever performed.
        $participant = Participant::withoutGlobalScopes()->find($this->participantId);

        if ($participant === null) {
            Log::warning('ScoreEvaluationJob: participant not found', [
                'participant_id' => $this->participantId,
            ]);

            return;
        }

        if ($participant->status === 'errore') {
            Log::info('ScoreEvaluationJob: participant already errore — no-op', [
                'participant_id' => $this->participantId,
            ]);

            return;
        }

        // ── Step 2: derive tenant context from the aggregate root ─────────────
        // Re-derived from the participant's own DB record — NEVER from ambient
        // TenantResolver state (Queue::before already reset it to null) and
        // NEVER from the job's serialized payload (design D2).
        $orgId = $participant->organization_id;

        if ($orgId < 1) {
            // Fail closed: no throw, no queue retry — mirrors the invariant-guard
            // precedent at resolveEvaluationTerminalState() (ZeroCompetenciesInvariantException
            // handling below, ~:428-442). A corrupted/unresolvable org must abort
            // before any write, not crash the queue worker.
            Log::error('ScoreEvaluationJob: participant has no resolvable organization_id — aborting before any write', [
                'participant_id' => $this->participantId,
                'organization_id' => $orgId,
            ]);

            $participant->refresh();
            $this->transitionParticipantToErrore($participant);

            return;
        }

        // ── Step 3: Evaluation row guard — runs inside the established context ─
        TenantContextScope::runFor($orgId, fn () => $this->enterEvaluationGuard($participant));
    }

    /**
     * Start-of-job guard step 2: load or create the Evaluation row.
     *
     * Handles the 4 branches from D2:
     *   - No row → create in processing + proceed.
     *   - 23505 concurrent INSERT → reload + re-enter (max 1 re-entry).
     *   - {completed|pending} + retry_attempt=false → no-op.
     *   - pending + retry_attempt=true → RT-B merge (design D8 table), see below.
     *   - processing → resume-skip path.
     *
     * @param  bool  $reentrant  True when called after a 23505 catch (prevents infinite loop).
     */
    private function enterEvaluationGuard(Participant $participant, bool $reentrant = false): void
    {
        // Use withoutGlobalScopes to load across tenant boundary inside the job.
        // The job is dispatched for a specific participant; cross-tenant isolation
        // is enforced by the participantId (minted per org by C6).
        $evaluation = Evaluation::withoutGlobalScopes()
            ->where('participant_id', $this->participantId)
            ->first();

        if ($evaluation === null) {
            // No row exists → create in processing and proceed.
            try {
                $evaluation = Evaluation::create([
                    'participant_id' => $this->participantId,
                    'status' => EvaluationStatus::Processing->value,
                    'framework_version_id' => $this->resolveFrameworkVersionId($participant),
                    'model_version' => config('scoring.model_version'),
                    'prompt_version' => config('scoring.prompt_version'),
                    'evaluated_at' => null,
                    'retry_attempt' => false,
                ]);
            } catch (UniqueConstraintViolationException) {
                // 23505: concurrent job won the INSERT race. Reload and re-enter guard.
                if ($reentrant) {
                    // Guard against infinite loop — should not happen in practice.
                    Log::error('ScoreEvaluationJob: 23505 re-entry loop detected', [
                        'participant_id' => $this->participantId,
                    ]);

                    return;
                }

                Log::info('ScoreEvaluationJob: 23505 concurrent INSERT — reloading and re-entering guard', [
                    'participant_id' => $this->participantId,
                ]);

                $this->enterEvaluationGuard($participant, reentrant: true);

                return;
            }

            Log::info('ScoreEvaluationJob: Evaluation created, starting scoring', [
                'participant_id' => $this->participantId,
                'evaluation_id' => $evaluation->id,
            ]);

            $this->runScoringPipeline($evaluation, $participant);

            return;
        }

        // Existing Evaluation row found — branch on status (cast to EvaluationStatus by the model).
        $status = $evaluation->status;

        if ($status === EvaluationStatus::Processing) {
            // Resume-skip path: a previous job started but did not complete.
            // Scoring pipeline skips already-scored competencies (CompetencyResult rows).
            Log::info('ScoreEvaluationJob: Evaluation in processing — resuming', [
                'participant_id' => $this->participantId,
                'evaluation_id' => $evaluation->id,
            ]);

            $this->runScoringPipeline($evaluation, $participant);

            return;
        }

        // ── Terminal status ({completed|pending}) ─────────────────────────────
        // RT-B (design D8): the DATABASE row is authoritative for "this is a retry".
        // The payload flag is only a hint — a crash-resumed or re-dispatched job
        // carries no reliable flag, the evaluation row does.
        if (! $evaluation->retry_attempt) {
            if ($this->retryAttempt) {
                Log::info('ScoreEvaluationJob: retry flag without a database authorization — no-op', [
                    'participant_id' => $this->participantId,
                    'evaluation_id' => $evaluation->id,
                    'status' => $status->value,
                ]);

                return;
            }

            Log::info('ScoreEvaluationJob: Evaluation already terminal — no-op', [
                'participant_id' => $this->participantId,
                'evaluation_id' => $evaluation->id,
                'status' => $status->value,
            ]);

            return;
        }

        // retry_attempt = true in the database from here on.
        if ($status === EvaluationStatus::Completed) {
            // A6: the retry already ran (superseded, duplicated or raced). No LLM call, no write, no event.
            Log::info('ScoreEvaluationJob: retry already completed — no-op', [
                'participant_id' => $this->participantId,
                'evaluation_id' => $evaluation->id,
            ]);

            return;
        }

        if ($participant->status !== 'in_valutazione') {
            // A stray job while the candidate has not (yet) finished the re-interview.
            Log::info('ScoreEvaluationJob: retry not scored — participant has not re-interviewed', [
                'participant_id' => $this->participantId,
                'evaluation_id' => $evaluation->id,
                'participant_status' => $participant->status,
            ]);

            return;
        }

        if (! $this->retryAttempt) {
            // Unreachable once DispatchScoringJob passes the flag; never strand the participant over it.
            Log::warning('ScoreEvaluationJob: database has a retry authorization but the job payload flag is false — merging anyway', [
                'participant_id' => $this->participantId,
                'evaluation_id' => $evaluation->id,
            ]);
        }

        $merged = $this->mergeRetryResults($evaluation);

        if ($merged === null) {
            Log::info('ScoreEvaluationJob: retry merge skipped — evaluation no longer pending under the lock', [
                'participant_id' => $this->participantId,
                'evaluation_id' => $evaluation->id,
            ]);

            return;
        }

        $this->runScoringPipeline($merged, $participant);
    }

    /**
     * RT-B retry merge: in ONE transaction delete the invalid competency results
     * (indicator scores and audits go with them by FK cascade) and flip the
     * Evaluation `pending` → `processing`. Re-scoring of the competencies that now
     * have no result then runs on the ordinary resume-skip loop.
     *
     * Atomic only for the delete-and-flip step; crash safety of what follows comes
     * from the `processing` resume path, which never re-merges. The row is locked
     * and re-checked so two racing jobs cannot both delete.
     *
     * @return Evaluation|null the processing evaluation, or null when it is no
     *                         longer a pending retry under the lock
     */
    private function mergeRetryResults(Evaluation $evaluation): ?Evaluation
    {
        return DB::transaction(function () use ($evaluation): ?Evaluation {
            $locked = Evaluation::withoutGlobalScopes()->lockForUpdate()->find($evaluation->id);

            if ($locked === null || $locked->status !== EvaluationStatus::Pending || ! $locked->retry_attempt) {
                return null;
            }

            $deleted = CompetencyResult::withoutGlobalScopes()
                ->where('evaluation_id', $locked->id)
                ->where('valid', false)
                ->delete();

            $locked->status = EvaluationStatus::Processing;
            $locked->save();

            Log::info('ScoreEvaluationJob: retry merge — invalid results deleted, evaluation processing', [
                'participant_id' => $this->participantId,
                'evaluation_id' => $locked->id,
                'deleted_results' => $deleted,
            ]);

            return $locked;
        });
    }

    /**
     * Run the full per-competency BARS scoring pipeline.
     *
     * Processes all competencies assigned to the participant's project sequentially.
     * Resume-skip: competencies with an existing CompetencyResult row are skipped.
     *
     * REQ: Per-competency scoring loop + gate + lifecycle (C9 D2/D3/D4/D5/D9)
     */
    private function runScoringPipeline(Evaluation $evaluation, Participant $participant): void
    {
        $project = $participant->project()->withoutGlobalScopes()->first();

        if ($project === null) {
            Log::error('ScoreEvaluationJob: project not found for participant', [
                'participant_id' => $this->participantId,
            ]);

            return;
        }

        // ── Resolve the pinned catalogue revision and role ONCE, before the loop ──
        // `role_code` is null for `potential` (MTG/LAT are role-less by design —
        // a legal state, not a failure). For `standard` it MUST resolve to a real
        // Role row: falling through to null would query `role_id IS NULL` and
        // silently score a standard project against the role-less potential set,
        // reintroducing this exact class of contamination under another name.
        //
        // The lookup is BY CODE, so it must be revision-scoped: once a draft is
        // open every role code exists twice, and `framework_roles` is UNIQUE
        // (revision_id, code), not unique on code alone.
        //
        // Aborting is not enough on its own, and a bare `return` here would be a
        // real defect: by this point enterEvaluationGuard() has written the
        // Evaluation row as `processing` and the participant is still
        // `in_valutazione`. Nothing throws, so failed() never runs — no
        // EvaluationFailed, no `errore` transition — and
        // resolveEvaluationTerminalState() is skipped, so there is no terminal
        // status and no webhook. A re-dispatch resumes into the Processing
        // branch, reaches this same guard and returns again: the candidate is
        // stranded permanently and the calling system is never told. So this arm
        // ends the participant the way failed() and the `orgId < 1` guard do.
        $revisionId = app(CatalogueRevisionResolver::class)->tryForProject($project);
        $roleId = null;

        if ($revisionId === null) {
            Log::error('ScoreEvaluationJob: project has no resolvable catalogue revision — aborting before any further write', [
                'participant_id' => $this->participantId,
                'project_id' => $project->id,
            ]);

            $this->endParticipantUnresolvable($participant);

            return;
        }

        if ($project->role_code !== null) {
            $role = Role::where('code', $project->role_code)
                ->where('revision_id', $revisionId)
                ->first();

            if ($role === null) {
                Log::error('ScoreEvaluationJob: project.role_code has no matching Role in the pinned revision — aborting before any further write', [
                    'participant_id' => $this->participantId,
                    'project_id' => $project->id,
                    'role_code' => $project->role_code,
                    'revision_id' => $revisionId,
                ]);

                $this->endParticipantUnresolvable($participant);

                return;
            }

            $roleId = (int) $role->id;
        }

        $projectLocale = (string) ($project->language ?? 'en');
        $competencies = $project->competencies()->get();

        if ($competencies->isEmpty()) {
            // D5 CC1 invariant: 0 project_competencies → log + mark participant errore.
            // resolveEvaluationTerminalState will catch ZeroCompetenciesInvariantException.
            Log::error('ScoreEvaluationJob: no competencies configured for project — running gate for invariant guard', [
                'project_id' => $project->id,
                'participant_id' => $this->participantId,
            ]);

            // Ensure an Evaluation row exists before resolving (it was created at START).
            (new ResolveEvaluationTerminalState)->resolve($evaluation, $participant, $project);

            return;
        }

        // Resolve services (allows overriding via the container in tests).
        $llmProvider = app(LLMProvider::class);
        $transcriptAssembler = new TranscriptAssembler;
        $promptBuilder = new PromptBuilder;
        $barsIndicatorLoader = new BarsIndicatorLoader;
        $evaluationParser = new EvaluationParser;
        $indicatorValidator = new IndicatorValidator;
        $excerptValidator = new ExcerptValidator;
        $meanCalculator = new MeanCalculator;
        // split-score-evaluation-job: extracted collaborators (design.md D1),
        // instantiated once and reused across the loop — same lifetime as the
        // service instances above.
        $scoreCompetency = new ScoreCompetency;
        $resolveTerminalState = new ResolveEvaluationTerminalState;

        // PR3: injectable strategies (bound in AppServiceProvider, overridable in tests).
        $reliabilityStrategy = app(ReliabilityStrategy::class);
        $validityPredicate = app(ValidityPredicate::class);

        foreach ($competencies as $competency) {
            $competencyCode = (string) $competency->code;

            // ── Resume-skip ───────────────────────────────────────────────
            $alreadyScored = CompetencyResult::withoutGlobalScopes()
                ->where('evaluation_id', $evaluation->id)
                ->where('competency_code', $competencyCode)
                ->exists();

            if ($alreadyScored) {
                Log::info('ScoreEvaluationJob: resume-skip — competency already scored', [
                    'evaluation_id' => $evaluation->id,
                    'competency_code' => $competencyCode,
                ]);

                continue;
            }

            // ── Load interview session ────────────────────────────────────
            $session = InterviewSession::withoutGlobalScopes()
                ->where('participant_id', $this->participantId)
                ->where('competency_code', $competencyCode)
                ->first();

            if ($session === null) {
                Log::warning('ScoreEvaluationJob: no interview session for competency — skipping', [
                    'evaluation_id' => $evaluation->id,
                    'competency_code' => $competencyCode,
                ]);

                continue;
            }

            // ── Load BARS indicators ──────────────────────────────────────
            // Scoped by BOTH role_id AND competency_id through the single shared
            // lookup C8 already uses. `framework_bars_indicators` is UNIQUE
            // (role_id, competency_id, position), so one competency carries 3
            // rows PER ROLE: a competency-only query returned every other role's
            // anchors too, attached scores by array position to indicator text
            // belonging to other roles, and inflated the reliability denominator
            // from 3 to 3 x roles. `$roleId` is null only for a role-less
            // (`potential`) project, where the loader emits whereNull.
            $indicators = $barsIndicatorLoader->forRoleCompetency(
                roleId: $roleId,
                competencyId: (int) $competency->id,
                revisionId: $revisionId,
            );

            // ── Score this competency ─────────────────────────────────────
            try {
                $scoreCompetency->score(
                    evaluation: $evaluation,
                    competencyCode: $competencyCode,
                    competencyId: (int) $competency->id,
                    projectLocale: $projectLocale,
                    indicators: $indicators,
                    session: $session,
                    transcriptAssembler: $transcriptAssembler,
                    promptBuilder: $promptBuilder,
                    llmProvider: $llmProvider,
                    evaluationParser: $evaluationParser,
                    indicatorValidator: $indicatorValidator,
                    excerptValidator: $excerptValidator,
                    meanCalculator: $meanCalculator,
                    reliabilityStrategy: $reliabilityStrategy,
                    validityPredicate: $validityPredicate,
                    participantId: $this->participantId,
                );
            } catch (UniqueConstraintViolationException $e) {
                // CW5: CompetencyResult INSERT race → skip (already persisted by another path).
                Log::warning('ScoreEvaluationJob: CompetencyResult unique-violation — skip (CW5)', [
                    'evaluation_id' => $evaluation->id,
                    'competency_code' => $competencyCode,
                ]);
            }
        }

        Log::info('ScoreEvaluationJob: scoring pipeline complete — running gate', [
            'evaluation_id' => $evaluation->id,
        ]);

        // ── PR3: CompletionGate + lifecycle resolution ────────────────────
        $resolveTerminalState->resolve(
            evaluation: $evaluation,
            participant: $participant,
            project: $project,
        );
    }

    /**
     * Resolve the framework_version_id for this participant.
     */
    private function resolveFrameworkVersionId(Participant $participant): int
    {
        $project = $participant->project()->withoutGlobalScopes()->first();

        if ($project === null) {
            throw new \RuntimeException(
                "ScoreEvaluationJob: cannot resolve framework_version_id for participant {$this->participantId} — project not found."
            );
        }

        return (int) $project->framework_version_id;
    }

    /**
     * Handle job failure after all queue retries are exhausted (D9 CC5).
     *
     * (a) Guard: transition participant in_valutazione → errore ONLY if currently in_valutazione.
     *     If participant is already errore (e.g. race with concurrent failed()), skip transition.
     * (b) ALWAYS emit EvaluationFailed($participantId) regardless of transition outcome.
     *
     * This ensures PRs 1–2 cannot leave participants orphaned in in_valutazione on failure.
     *
     * Tenant context (queued-job-tenancy PR2): failed() performs zero tenant-scoped
     * writes today (Participant is a plain Model, not TenantModel), but it emits
     * EvaluationFailed, which C10's tenant-scoped webhook listeners will attach to.
     * When the org is derivable from the participant, the whole body runs inside
     * TenantContextScope::runFor(). When it is NOT derivable, D9's "ALWAYS emit"
     * outranks tenant context — the event still fires, unwrapped (design D3).
     */
    public function failed(\Throwable $e): void
    {
        Log::error('ScoreEvaluationJob: job exhausted retries', [
            'participant_id' => $this->participantId,
            'error' => $e->getMessage(),
        ]);

        $participant = Participant::withoutGlobalScopes()->find($this->participantId);
        $orgId = $participant?->organization_id;

        if ($participant !== null && $orgId !== null && $orgId >= 1) {
            TenantContextScope::runFor($orgId, function () use ($participant, $orgId): void {
                $this->transitionParticipantToErrore($participant);
                // $orgId (pre-commit gate, round 5, finding 2): threaded onto the
                // event itself — this IS the trusted derivation
                // App\Listeners\SendEvaluationWebhook::handleFailed() needs to
                // org-scope its own Participant read, independent of that
                // listener's own (unscoped) lookup by participantId alone.
                event(new EvaluationFailed($this->participantId, $orgId));
            });

            return;
        }

        Log::error('ScoreEvaluationJob: cannot derive organization context in failed() — emitting EvaluationFailed unwrapped', [
            'participant_id' => $this->participantId,
        ]);

        if ($participant !== null) {
            $this->transitionParticipantToErrore($participant);
        }

        // (b) ALWAYS emit EvaluationFailed, regardless of transition outcome or context
        // derivability. No trustworthy organizationId exists on this branch — left null.
        event(new EvaluationFailed($this->participantId));
    }

    /**
     * End a participant whose project cannot be resolved against the catalogue.
     *
     * Mirrors failed() rather than the `orgId < 1` guard: this runs AFTER the
     * Evaluation row exists, so the calling system must be told the evaluation
     * will never arrive. Emitting EvaluationFailed is what makes the outcome
     * observable instead of a silent, permanently-resumable no-op.
     *
     * Runs exclusively from inside handle()'s own `TenantContextScope::runFor($orgId,
     * …)` boundary (established at Step 2/3, above), so `$participant->organization_id`
     * here IS that same already-validated `$orgId` — not a fresh untrusted read — and is
     * threaded onto EvaluationFailed for the identical reason failed() now does
     * (pre-commit gate, round 5, finding 2).
     */
    private function endParticipantUnresolvable(Participant $participant): void
    {
        $participant->refresh();
        $this->transitionParticipantToErrore($participant);
        event(new EvaluationFailed($this->participantId, $participant->organization_id));
    }

    /**
     * Guard transition: in_valutazione → errore, only if currently in_valutazione.
     * Skips silently if already errore (race with a concurrent failed() call).
     *
     * The save() runs inside DB::transaction() so a failure here (e.g. the
     * participant row itself is corrupted — organization_id fails FK
     * revalidation on ANY update to the row, not only when that column
     * changes) rolls back to a SAVEPOINT instead of poisoning whatever
     * outer transaction the caller may be inside. This keeps the guard
     * genuinely "no throw" as required by the fail-closed org guard.
     */
    private function transitionParticipantToErrore(Participant $participant): void
    {
        if ($participant->status === 'in_valutazione') {
            try {
                DB::transaction(function () use ($participant): void {
                    $participant->status = 'errore';
                    $participant->save();
                });

                Log::info('ScoreEvaluationJob: participant transitioned to errore', [
                    'participant_id' => $this->participantId,
                ]);

                // public-api step 6, G-38: the ONE shared transition both
                // `failed()` and `endParticipantUnresolvable()` route
                // through — recording it here, once, covers both seams
                // rather than each call site recording its own.
                InterviewEventRecorder::error($participant->organization_id, $participant->id);
            } catch (\Throwable $transitionException) {
                Log::error('ScoreEvaluationJob: failed to transition participant to errore', [
                    'participant_id' => $this->participantId,
                    'error' => $transitionException->getMessage(),
                ]);
            }
        } elseif ($participant->status === 'errore') {
            Log::info('ScoreEvaluationJob: participant already errore — skipping transition', [
                'participant_id' => $this->participantId,
            ]);
        }
    }
}
