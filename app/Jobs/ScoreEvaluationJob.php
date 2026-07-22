<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\LLMProvider;
use App\DTOs\Scoring\IndicatorRef;
use App\DTOs\Scoring\IndicatorScoreDTO;
use App\Enums\EvaluationStatus;
use App\Events\EvaluationFailed;
use App\Exceptions\Scoring\AnchorTranslationMissingException;
use App\Exceptions\Scoring\ExcerptNotVerbatimException;
use App\Exceptions\Scoring\IndicatorCountMismatchException;
use App\Exceptions\Scoring\InvalidIndicatorScoreException;
use App\Exceptions\Scoring\JsonParseException;
use App\Exceptions\Scoring\RoleNoBarsException;
use App\Models\AiRequest;
use App\Models\BarsIndicator;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\IndicatorScore;
use App\Models\InterviewSession;
use App\Models\Participant;
use App\Services\Scoring\EvaluationParser;
use App\Services\Scoring\ExcerptValidator;
use App\Services\Scoring\IndicatorValidator;
use App\Services\Scoring\MeanCalculator;
use App\Services\Scoring\PromptBuilder;
use App\Services\Scoring\TranscriptAssembler;
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
 * listener — wired in PR3). Runs on Horizon; processes all competencies sequentially.
 *
 * PR2 SCOPE: Full scoring pipeline wired into runScoringPipeline():
 *   CassetteLLMProvider / FakeLLMProvider (real binding blocked by D25 gap D7).
 *   TranscriptAssembler → PromptBuilder → LLMProvider.complete() → EvaluationParser
 *   → IndicatorValidator → ExcerptValidator → MeanCalculator → DB transaction persist.
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
     *
     * Value mirrors the Horizon configuration for the scoring queue.
     */
    public int $tries = 3;

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

        // ── Step 2: Evaluation row guard ─────────────────────────────────────
        $this->enterEvaluationGuard($participant);
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
                $evaluation = Evaluation::withoutGlobalScopes()->create([
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
     * PR3 will add: ReliabilityStrategy, ValidityPredicate, CompletionGate, lifecycle transition.
     *
     * REQ: Per-competency scoring loop (C9 D2/D3/D4)
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
            Log::warning('ScoreEvaluationJob: no competencies configured for project', [
                'project_id' => $project->id,
            ]);

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
                );
            } catch (UniqueConstraintViolationException $e) {
                // CW5: CompetencyResult INSERT race → skip (already persisted by another path).
                Log::warning('ScoreEvaluationJob: CompetencyResult unique-violation — skip (CW5)', [
                    'evaluation_id' => $evaluation->id,
                    'competency_code' => $competencyCode,
                ]);
            }
        }

        Log::info('ScoreEvaluationJob: scoring pipeline complete', [
            'evaluation_id' => $evaluation->id,
        ]);
    }

    /**
     * Score a single competency: assemble transcript → build prompt → call LLM
     * → parse → validate → persist in one transaction.
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

            // DO NOT queue-retry: persist as llm_parse_error immediately (D4 FIX-9).
            $this->persistUnscorable($evaluation, $competencyCode, 'llm_parse_error');

            return;
        }

        // ── Compute mean ─────────────────────────────────────────────────
        $scores = array_map(static fn (IndicatorScoreDTO $dto): int => $dto->score, $dtos);
        $score = $meanCalculator->compute($scores);

        // ── Persist: ai_requests + CompetencyResult + IndicatorScores in ONE transaction ──
        DB::transaction(function () use (
            $evaluation, $competencyCode, $score, $dtos,
            $llmResponse, $latencyMs, $options
        ): void {
            // 1. Append ai_requests row (evaluation_id always known — Evaluation was created at START).
            AiRequest::withoutGlobalScopes()->create([
                'evaluation_id' => $evaluation->id,
                'competency_code' => $competencyCode,
                'model' => $llmResponse->model,
                'prompt_version' => $options['prompt_version'] ?? config('scoring.prompt_version'),
                'input_tokens' => $llmResponse->inputTokens,
                'output_tokens' => $llmResponse->outputTokens,
                'finish_reason' => $llmResponse->finishReason,
                'latency_ms' => $latencyMs,
            ]);

            // 2. Persist CompetencyResult (unique constraint guards CW5 at caller level).
            // reliability = 0.0 placeholder (PR3 will compute AssessableFractionReliability).
            $cr = CompetencyResult::withoutGlobalScopes()->create([
                'evaluation_id' => $evaluation->id,
                'competency_code' => $competencyCode,
                'score' => $score,
                'reliability' => 0.0,
                'valid' => false, // PR3 wires ValidityPredicate
                'unscorable_reason' => null,
            ]);

            // 3. Persist IndicatorScore rows.
            foreach ($dtos as $dto) {
                IndicatorScore::withoutGlobalScopes()->create([
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
            CompetencyResult::withoutGlobalScopes()->create([
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
     */
    public function failed(\Throwable $e): void
    {
        Log::error('ScoreEvaluationJob: job exhausted retries', [
            'participant_id' => $this->participantId,
            'error' => $e->getMessage(),
        ]);

        // (a) Guard transition: only if currently in_valutazione.
        $participant = Participant::withoutGlobalScopes()->find($this->participantId);

        if ($participant !== null && $participant->status === 'in_valutazione') {
            try {
                $participant->status = 'errore';
                $participant->save();

                Log::info('ScoreEvaluationJob: participant transitioned to errore', [
                    'participant_id' => $this->participantId,
                ]);
            } catch (\Throwable $transitionException) {
                Log::error('ScoreEvaluationJob: failed to transition participant to errore', [
                    'participant_id' => $this->participantId,
                    'error' => $transitionException->getMessage(),
                ]);
            }
        } elseif ($participant !== null && $participant->status === 'errore') {
            Log::info('ScoreEvaluationJob: participant already errore — skipping transition', [
                'participant_id' => $this->participantId,
            ]);
        }

        // (b) ALWAYS emit EvaluationFailed, regardless of transition outcome.
        event(new EvaluationFailed($this->participantId));
    }
}
