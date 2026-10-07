<?php

declare(strict_types=1);

namespace App\Actions\Scoring;

use App\Enums\EvaluationStatus;
use App\Events\EvaluationCompleted;
use App\Exceptions\Scoring\ZeroCompetenciesInvariantException;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\Participant;
use App\Models\Project;
use App\Services\Scoring\CompletionGate;
use App\Support\PublicApi\InterviewEventRecorder;
use Illuminate\Support\Facades\Log;

/**
 * ResolveEvaluationTerminalState — post-scoring: compute the gate, persist the
 * terminal Evaluation status, transition the participant lifecycle
 * (split-score-evaluation-job).
 *
 * MOVED verbatim out of `App\Jobs\ScoreEvaluationJob::resolveEvaluationTerminalState()`
 * (design.md D1) — zero behavior change; this method never referenced job-instance
 * state (`$this->participantId`), so the move is a straight cut-and-paste-and-rename.
 *
 * D5 CC1 gate:
 *   valid_competencies / total_competencies >= 0.90 → completed; else → pending.
 *   Invariant guard: total_competencies == 0 → ZeroCompetenciesInvariantException.
 *   RT-B (scoring-retry-rt-b D9): an Evaluation with `retry_attempt = true` skips the
 *   gate and the invariant guard and is ALWAYS persisted `completed` (the single
 *   retry is the definitive run, whatever the ratio).
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
final class ResolveEvaluationTerminalState
{
    public function resolve(
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
            // ScoreCompetency::score(). Count valid ones for the gate.
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

        // ── RT-B: the retry run is definitive (design D9) ─────────────────
        // The database row decides, never a caller-supplied flag: a crash-resumed job
        // reaches this point through the `processing` path with no payload hint.
        // A retry skips the gate entirely, and with it the ZeroCompetencies `errore`
        // arm: a project whose composition was emptied between the attempts must not
        // strand a retry in `errore`. The counts are still logged below.
        $isRetry = (bool) Evaluation::withoutGlobalScope('tenant')
            ->whereKey($evaluation->id)
            ->value('retry_attempt');

        if ($isRetry) {
            $terminalStatus = EvaluationStatus::Completed;
        } else {
            // ── Invariant guard: total_competencies == 0 ─────────────────
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

                    // public-api step 6, G-38.
                    InterviewEventRecorder::error($participant->organization_id, $participant->id);
                }

                // Do NOT emit EvaluationCompleted — invariant error, no valid Evaluation.
                return;
            }
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
            'retry' => $isRetry,
        ]);

        // ── Lifecycle: in_valutazione → completato (D9 FIX-7 race guard) ──
        $participant->refresh();

        if ($participant->status === 'in_valutazione') {
            $participant->status = 'completato';
            $participant->save();

            Log::info('ScoreEvaluationJob: participant transitioned to completato', [
                'participant_id' => $participant->id,
            ]);

            // public-api step 6, G-38: `completed` fires only on the REAL
            // in_valutazione → completato transition — never in the race-
            // guard branch below, where the participant is already `errore`
            // and never becomes `completato` at all.
            InterviewEventRecorder::completed($participant->organization_id, $participant->id);
        } else {
            // Race guard: participant is already errore (concurrent failed() ran).
            // DO NOT attempt errore → completato (forbidden by C7a lifecycle map).
            // STILL emit EvaluationCompleted below.
            Log::warning('ScoreEvaluationJob: race guard — participant not in_valutazione; skipping completato transition', [
                'participant_id' => $participant->id,
                'current_status' => $participant->status,
            ]);
        }

        // public-api step 6, G-38: `scoring_ready` fires unconditionally
        // alongside `EvaluationCompleted` below — the evaluation itself
        // reached a terminal state (`completed`/`pending`) either way, even
        // when the race guard above skipped the participant's own lifecycle
        // transition.
        InterviewEventRecorder::scoringReady($participant->organization_id, $participant->id);

        // ── Emit EvaluationCompleted unconditionally (D9) ─────────────────
        // Fired for both completed and pending. Also fired when race guard skipped lifecycle.
        event(new EvaluationCompleted($evaluation->id));
    }
}
