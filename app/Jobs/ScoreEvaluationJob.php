<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\LLMProvider;
use App\DTOs\LLMResponse;
use App\DTOs\Scoring\IndicatorRef;
use App\DTOs\Scoring\IndicatorScoreDTO;
use App\Enums\AiRequestFailureReason;
use App\Enums\EvaluationStatus;
use App\Events\EvaluationCompleted;
use App\Events\EvaluationFailed;
use App\Exceptions\Scoring\AnchorTranslationMissingException;
use App\Exceptions\Scoring\ExcerptNotVerbatimException;
use App\Exceptions\Scoring\IndicatorCountMismatchException;
use App\Exceptions\Scoring\InvalidIndicatorScoreException;
use App\Exceptions\Scoring\JsonParseException;
use App\Exceptions\Scoring\RoleNoBarsException;
use App\Exceptions\Scoring\ZeroCompetenciesInvariantException;
use App\Models\AiRequest;
use App\Models\BarsIndicator;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\IndicatorScore;
use App\Models\InterviewSession;
use App\Models\Participant;
use App\Models\Project;
use App\Services\Scoring\CompletionGate;
use App\Services\Scoring\Contracts\ReliabilityStrategy;
use App\Services\Scoring\Contracts\ValidityPredicate;
use App\Services\Scoring\EvaluationParser;
use App\Services\Scoring\ExcerptValidator;
use App\Services\Scoring\IndicatorValidator;
use App\Services\Scoring\MeanCalculator;
use App\Services\Scoring\PromptBuilder;
use App\Services\Scoring\TranscriptAssembler;
use App\Support\Observability\AiRequestCostEstimator;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
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
 *     - {completed|pending} + retry_attempt=false → no-op.
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

        // Terminal status ({completed|pending}) with retryAttempt=false → no-op.
        if (! $this->retryAttempt) {
            Log::info('ScoreEvaluationJob: Evaluation already terminal — no-op', [
                'participant_id' => $this->participantId,
                'evaluation_id' => $evaluation->id,
                'status' => $status->value,
            ]);

            return;
        }

        // retryAttempt=true + pending → domain retry RT-B path (PR4).
        // Not implemented in PR2.
        Log::info('ScoreEvaluationJob: domain retry path — deferred to PR4', [
            'participant_id' => $this->participantId,
        ]);
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
            $this->resolveEvaluationTerminalState($evaluation, $participant, $project);

            return;
        }

        // Resolve services (allows overriding via the container in tests).
        $llmProvider = app(LLMProvider::class);
        $transcriptAssembler = new TranscriptAssembler;
        $promptBuilder = new PromptBuilder;
        $evaluationParser = new EvaluationParser;
        $indicatorValidator = new IndicatorValidator;
        $excerptValidator = new ExcerptValidator;
        $meanCalculator = new MeanCalculator;

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
            // Indicators are loaded scoped by competency only (not role_id),
            // because in the current data model BarsIndicator.competency_id identifies
            // the competency and the indicators are associated by competency across roles.
            // TODO(PR3): if multi-role projects are needed, filter by role_id too.
            $indicators = BarsIndicator::where('competency_id', $competency->id)
                ->orderBy('position')
                ->get();

            // ── Score this competency ─────────────────────────────────────
            try {
                $this->scoreCompetency(
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
        $this->resolveEvaluationTerminalState(
            evaluation: $evaluation,
            participant: $participant,
            project: $project,
        );
    }

    /**
     * Post-scoring: compute gate, persist terminal Evaluation status, transition lifecycle.
     *
     * D5 CC1 gate:
     *   valid_competencies / total_competencies >= 0.90 → completed; else → pending.
     *   Invariant guard: total_competencies == 0 → ZeroCompetenciesInvariantException.
     *
     * D9 lifecycle:
     *   Both completed and pending resolve participant in_valutazione → completato.
     *   Race guard (FIX-7): if participant is already errore (concurrent failed()),
     *   skip the lifecycle transition but STILL persist Evaluation + emit EvaluationCompleted.
     *
     * D5 unscorable policy (gate.count_unscorable_against_total = true by default):
     *   Unscorable competencies are NOT valid and ARE counted in the denominator.
     *   They reduce the ratio. Config-flaggable: when false, they are excluded from both.
     *
     * REQ: D5 CC1 gate + D9 lifecycle (C9 PR3)
     */
    private function resolveEvaluationTerminalState(
        Evaluation $evaluation,
        Participant $participant,
        Project $project,
    ): void {
        // ── Count competency results for gate ─────────────────────────────
        $allResults = CompetencyResult::withoutGlobalScopes()
            ->where('evaluation_id', $evaluation->id)
            ->get();

        $countUnscorableAgainstTotal = (bool) config('scoring.gate.count_unscorable_against_total', true);

        // Compute valid count from actually-scored results using injectable strategies.
        // For unscorable CompetencyResults (no IndicatorScores), reliability = 0.0 → invalid.
        $validCount = 0;

        foreach ($allResults as $result) {
            // Unscorable results: valid = false (persisted inline per-competency); skip.
            // Scored results: reliability + valid are now computed and persisted inline in
            // scoreCompetency() (PR3). Count valid ones for the gate.
            if ((bool) $result->valid) {
                $validCount++;
            }
        }

        // ── Determine totalCount from project_competencies ────────────────
        // Fixed at project creation; not affected by unscorable policy for the denominator.
        if ($countUnscorableAgainstTotal) {
            // Default policy: all project competencies count in the denominator.
            $totalCount = $project->competencies()->count();
        } else {
            // Alt policy: exclude unscorable competencies from both numerator and denominator.
            $totalCount = $allResults
                ->where('unscorable_reason', null)
                ->count();
        }

        // ── Invariant guard: total_competencies == 0 ─────────────────────
        $projectId = (int) $project->id;
        $gate = new CompletionGate;

        try {
            $terminalStatus = $gate->evaluate($validCount, $totalCount);
        } catch (ZeroCompetenciesInvariantException) {
            Log::error('ScoreEvaluationJob: invariant — project has 0 competencies; marking participant errore', [
                'evaluation_id' => $evaluation->id,
                'project_id' => $projectId,
            ]);

            // Transition participant to errore (guard: only if in_valutazione).
            $participant->refresh();
            if ($participant->status === 'in_valutazione') {
                $participant->status = 'errore';
                $participant->save();
            }

            // Do NOT emit EvaluationCompleted — invariant error, no valid Evaluation.
            return;
        }

        // ── Persist terminal Evaluation state ─────────────────────────────
        Evaluation::withoutGlobalScopes()
            ->where('id', $evaluation->id)
            ->update([
                'status' => $terminalStatus->value,
                'evaluated_at' => now(),
            ]);

        Log::info('ScoreEvaluationJob: evaluation finalized', [
            'evaluation_id' => $evaluation->id,
            'status' => $terminalStatus->value,
            'valid_count' => $validCount,
            'total_count' => $totalCount,
        ]);

        // ── Lifecycle: in_valutazione → completato (D9 FIX-7 race guard) ──
        $participant->refresh();

        if ($participant->status === 'in_valutazione') {
            $participant->status = 'completato';
            $participant->save();

            Log::info('ScoreEvaluationJob: participant transitioned to completato', [
                'participant_id' => $participant->id,
            ]);
        } else {
            // Race guard: participant is already errore (concurrent failed() ran).
            // DO NOT attempt errore → completato (forbidden by C7a lifecycle map).
            // STILL emit EvaluationCompleted below.
            Log::warning('ScoreEvaluationJob: race guard — participant not in_valutazione; skipping completato transition', [
                'participant_id' => $participant->id,
                'current_status' => $participant->status,
            ]);
        }

        // ── Emit EvaluationCompleted unconditionally (D9) ─────────────────
        // Fired for both completed and pending. Also fired when race guard skipped lifecycle.
        event(new EvaluationCompleted($evaluation->id));
    }

    /**
     * Score a single competency: assemble transcript → build prompt → call LLM
     * → parse → validate → persist in one transaction.
     *
     * PR3: reliability and valid are now computed inline per-competency via injectable strategies.
     *
     * Error handling:
     * - RoleNoBarsException → CompetencyResult(unscorable_reason=role_no_bars), no LLM call.
     * - AnchorTranslationMissingException → CompetencyResult(anchor_translation_missing), no LLM call.
     * - Parsing/validation errors → CompetencyResult(llm_parse_error), no queue retry.
     *
     * @param  Collection<int, BarsIndicator>  $indicators
     *
     * @throws UniqueConstraintViolationException When CompetencyResult INSERT races (caught by caller CW5).
     */
    private function scoreCompetency(
        Evaluation $evaluation,
        string $competencyCode,
        int $competencyId,
        string $projectLocale,
        Collection $indicators,
        InterviewSession $session,
        TranscriptAssembler $transcriptAssembler,
        PromptBuilder $promptBuilder,
        LLMProvider $llmProvider,
        EvaluationParser $evaluationParser,
        IndicatorValidator $indicatorValidator,
        ExcerptValidator $excerptValidator,
        MeanCalculator $meanCalculator,
        ReliabilityStrategy $reliabilityStrategy,
        ValidityPredicate $validityPredicate,
    ): void {
        // ── Handle: no BARS catalog (role_no_bars) ───────────────────────
        if ($indicators->isEmpty()) {
            Log::info('ScoreEvaluationJob: no BARS indicators — role_no_bars', [
                'evaluation_id' => $evaluation->id,
                'competency_code' => $competencyCode,
            ]);

            $this->persistUnscorable(
                evaluation: $evaluation,
                competencyCode: $competencyCode,
                reason: 'role_no_bars',
            );

            return;
        }

        // ── Assemble transcript ──────────────────────────────────────────
        $transcript = $transcriptAssembler->assemble($session);

        // ── Build prompt (triggers AnchorTranslationMissingException on L-2 fail) ──
        try {
            $promptPayload = $promptBuilder->build(
                evaluation: $evaluation,
                competencyCode: $competencyCode,
                competencyId: $competencyId,
                roleId: 0, // role_id not needed; PromptBuilder uses the $indicators collection
                projectLocale: $projectLocale,
                indicators: $indicators,
                transcript: $transcript,
            );
        } catch (AnchorTranslationMissingException $e) {
            Log::info('ScoreEvaluationJob: anchor translation missing — anchor_translation_missing', [
                'evaluation_id' => $evaluation->id,
                'competency_code' => $competencyCode,
                'error' => $e->getMessage(),
            ]);

            $this->persistUnscorable(
                evaluation: $evaluation,
                competencyCode: $competencyCode,
                reason: 'anchor_translation_missing',
            );

            return;
        } catch (RoleNoBarsException $e) {
            $this->persistUnscorable($evaluation, $competencyCode, 'role_no_bars');

            return;
        }

        // ── Call LLM ─────────────────────────────────────────────────────
        // Pass competency_code in options so CassetteLLMProvider can look it up.
        $options = array_merge($promptPayload->options, [
            'competency_code' => $competencyCode,
        ]);

        $fullPrompt = $promptPayload->systemPrompt."\n\n".$promptPayload->userMessage;

        $startMs = (int) round(microtime(true) * 1000);
        $llmResponse = $llmProvider->complete($fullPrompt, $options);
        $latencyMs = (int) round(microtime(true) * 1000) - $startMs;

        // ── Parse + validate ──────────────────────────────────────────────
        // Convert BarsIndicator collection to IndicatorRefs for the parser.
        // indicator_text is cast to string; position is already int from the model.
        // array_values() ensures the result is a list (contiguous int keys starting at 0).
        /** @var list<IndicatorRef> $indicatorList */
        $indicatorList = array_values(
            $indicators->map(
                static fn (BarsIndicator $ind): IndicatorRef => new IndicatorRef(
                    position: (int) $ind->position,
                    text: (string) $ind->getTranslation('text', $projectLocale),
                )
            )->all()
        );

        try {
            $dtos = $evaluationParser->parse($llmResponse->content, $indicatorList);

            foreach ($dtos as $dto) {
                $indicatorValidator->validate($dto);
                $excerptValidator->validate($dto, $transcript);
            }
        } catch (JsonParseException|IndicatorCountMismatchException|InvalidIndicatorScoreException|ExcerptNotVerbatimException $e) {
            Log::warning('ScoreEvaluationJob: parse/validation error — llm_parse_error', [
                'evaluation_id' => $evaluation->id,
                'competency_code' => $competencyCode,
                'error' => $e->getMessage(),
            ]);

            // C13: the call above was MADE and BILLED. Returning without a row
            // here is how four classes of paid failure left no trace at all.
            // The reason is a machine key, never $e->getMessage(): a provider
            // error can echo prompt content, and prompts contain candidate
            // answers.
            $this->recordAiRequest(
                $evaluation,
                $competencyCode,
                $llmResponse,
                $latencyMs,
                $options,
                success: false,
                failureReason: match (true) {
                    $e instanceof JsonParseException => AiRequestFailureReason::ParseError,
                    $e instanceof IndicatorCountMismatchException => AiRequestFailureReason::IndicatorCountMismatch,
                    $e instanceof InvalidIndicatorScoreException => AiRequestFailureReason::InvalidIndicatorScore,
                    default => AiRequestFailureReason::ExcerptNotVerbatim,
                },
            );

            // DO NOT queue-retry: persist as llm_parse_error immediately (D4 FIX-9).
            $this->persistUnscorable($evaluation, $competencyCode, 'llm_parse_error');

            return;
        }

        // ── Compute mean + reliability + validity (PR3: real values) ─────
        $scores = array_map(static fn (IndicatorScoreDTO $dto): int => $dto->score, $dtos);
        $score = $meanCalculator->compute($scores);
        $reliability = $reliabilityStrategy->compute($scores);
        $valid = $validityPredicate->isValid($reliability);

        // ── Record the billed call BEFORE the results transaction (C13 D1) ──
        //
        // This deliberately REVERSES C9's D2 CW, which required the ai_requests
        // row and the CompetencyResult INSERT to share a transaction. A provider
        // call is external, irreversible and billed; the results are local and
        // revocable. Nesting the first inside the second meant any later failure
        // in that transaction erased the record of money already spent — and it
        // failed in the direction that HIDES cost, at exactly the moment
        // something else had gone wrong.
        //
        // Safe on C9's own terms: that design states the resume-skip signal is
        // the CompetencyResult row ("do NOT use the presence of an ai_requests
        // row as a skip signal") and that an extra ai_requests row is "valid
        // audit data", not an anomaly. Nothing depended on the coupling.
        $this->recordAiRequest(
            $evaluation,
            $competencyCode,
            $llmResponse,
            $latencyMs,
            $options,
            success: true,
            failureReason: null,
        );

        // ── Persist: CompetencyResult + IndicatorScores in ONE transaction ──
        DB::transaction(function () use (
            $evaluation, $competencyCode, $score, $reliability, $valid, $dtos
        ): void {
            // Persist CompetencyResult with real reliability + valid (PR3).
            $cr = CompetencyResult::create([
                'evaluation_id' => $evaluation->id,
                'competency_code' => $competencyCode,
                'score' => $score,
                'reliability' => $reliability,
                'valid' => $valid,
                'unscorable_reason' => null,
            ]);

            // 3. Persist IndicatorScore rows.
            foreach ($dtos as $dto) {
                IndicatorScore::create([
                    'competency_result_id' => $cr->id,
                    'indicator_text' => $dto->indicatorText,
                    'score' => $dto->score,
                    'explanation' => $dto->explanation,
                    'excerpts' => $dto->excerpts,
                    'position' => $dto->position,
                ]);
            }
        });

        Log::info('ScoreEvaluationJob: competency scored', [
            'evaluation_id' => $evaluation->id,
            'competency_code' => $competencyCode,
            'score' => $score,
            'reliability' => $reliability,
            'valid' => $valid,
        ]);
    }

    /**
     * Append the cost record for one provider call (C13, observability delta).
     *
     * Called on EVERY path that follows a completed call — success and each
     * failure class alike — and deliberately never from inside a transaction
     * that persists scoring results. See the call sites and design D1.
     *
     * `estimated_cost_usd` is computed here, at write time, from the config
     * rate table. Computing it on read would let a later price change silently
     * rewrite history: last quarter's spend would move because this quarter's
     * rates did.
     *
     * @param  array<string, mixed>  $options
     */
    private function recordAiRequest(
        Evaluation $evaluation,
        string $competencyCode,
        LLMResponse $llmResponse,
        int $latencyMs,
        array $options,
        bool $success,
        ?AiRequestFailureReason $failureReason,
    ): void {
        $estimator = app(AiRequestCostEstimator::class);

        AiRequest::create([
            'evaluation_id' => $evaluation->id,
            'competency_code' => $competencyCode,
            'provider' => (string) config('scoring.provider', 'anthropic'),
            'model' => $llmResponse->model,
            'prompt_version' => $options['prompt_version'] ?? config('scoring.prompt_version'),
            'input_tokens' => $llmResponse->inputTokens,
            'output_tokens' => $llmResponse->outputTokens,
            'estimated_cost_usd' => $estimator->estimate(
                $llmResponse->model,
                $llmResponse->inputTokens,
                $llmResponse->outputTokens,
            ),
            'success' => $success,
            'failure_reason' => $failureReason?->value,
            'finish_reason' => $llmResponse->finishReason,
            'latency_ms' => $latencyMs,
        ]);
    }

    /**
     * Persist a CompetencyResult as unscorable (no LLM call, no ai_requests row).
     */
    private function persistUnscorable(
        Evaluation $evaluation,
        string $competencyCode,
        string $reason,
    ): void {
        try {
            CompetencyResult::create([
                'evaluation_id' => $evaluation->id,
                'competency_code' => $competencyCode,
                'score' => null,
                'reliability' => 0.0,
                'valid' => false,
                'unscorable_reason' => $reason,
            ]);
        } catch (UniqueConstraintViolationException) {
            // CW5: already persisted (race condition) → skip.
            Log::warning('ScoreEvaluationJob: unscorable CompetencyResult already exists — skip', [
                'evaluation_id' => $evaluation->id,
                'competency_code' => $competencyCode,
            ]);
        }
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
            TenantContextScope::runFor($orgId, function () use ($participant): void {
                $this->transitionParticipantToErrore($participant);
                event(new EvaluationFailed($this->participantId));
            });

            return;
        }

        Log::error('ScoreEvaluationJob: cannot derive organization context in failed() — emitting EvaluationFailed unwrapped', [
            'participant_id' => $this->participantId,
        ]);

        if ($participant !== null) {
            $this->transitionParticipantToErrore($participant);
        }

        // (b) ALWAYS emit EvaluationFailed, regardless of transition outcome or context derivability.
        event(new EvaluationFailed($this->participantId));
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
