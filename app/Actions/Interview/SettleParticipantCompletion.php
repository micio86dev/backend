<?php

declare(strict_types=1);

namespace App\Actions\Interview;

use App\Jobs\FinalizeInterview;
use App\Models\InterviewSession;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Utterance;
use App\Support\Interview\CompetencyTally;
use Illuminate\Support\Facades\Log;

/**
 * Advancing a participant off `in_corso` (stale-interview-reaper D1/D2).
 *
 * A MOVE of `InterviewController::settleCompletionIfFinished()`, whose own
 * docblock already said it existed "so the two other paths that can finish an
 * interview reach the same code". A scheduled sweep is the fourth such path
 * and could not call a private method. Re-implementing the predicate would be
 * the divergent-tally defect `CompetencyTally` records having been fixed once
 * already, so this is a move: `settleIfFinished()` is that body verbatim, and
 * the controller now delegates to it.
 *
 * TWO entry points, and collapsing them would be wrong in both directions.
 * The browser path must not advance a participant who still has competencies
 * to answer; the reaper must, because nobody is coming back.
 */
final class SettleParticipantCompletion
{
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
    public function settleIfFinished(int $participantId, int $projectId): void
    {
        $tally = new CompetencyTally;
        $endedCount = $tally->ended($participantId, $projectId);
        $totalCompetencies = $tally->total($projectId);

        if ($endedCount !== $totalCompetencies || $totalCompetencies === 0) {
            return;
        }

        // `organization_id` is a PREDICATE here, not an argument about call
        // sites. `Participant` extends plain Model — no global scope — and the
        // repo rule is that it is never queried without this filter. Every
        // other Participant access in app/ carries it; this was the one that
        // did not, and it is a WRITE.
        //
        // Not exploitable today, because `$participantId` arrives either from
        // the authenticated candidate or from a session resolved through
        // `ResolvesOwnedSession` (InterviewSession IS a TenantModel). But that
        // is an argument about three call sites, and the next call site added
        // is the one that breaks it. Read from the project rather than trusted
        // from the caller, so the invariant is local to this query.
        $orgId = Project::whereKey($projectId)->value('organization_id');

        if ($orgId === null) {
            // The filter is correct and stays. What must not be silent is the
            // case where it cannot be resolved: `Project` soft-deletes and runs
            // through the tenant scope, so a null here makes the update match
            // zero rows, `$won !== 1`, and the participant stays `in_corso`
            // with no scoring, no webhook and no notification — verbatim the
            // stranding this method exists to end, reached by another route.
            Log::error('settle: cannot settle completion — project did not resolve', [
                'participant_id' => $participantId,
                'project_id' => $projectId,
            ]);

            return;
        }

        $won = Participant::where('id', $participantId)
            ->where('organization_id', $orgId)
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
     * The candidate is GONE — settle on whatever they did say (D2).
     *
     * Not `settleIfFinished` with a looser tally: the precondition is the
     * whole difference. Here the interview is over because nobody is coming
     * back, not because every competency was answered, and the completion gate
     * downstream is built for exactly that — a partial interview scores, is
     * reported `pending` rather than `completed`, and carries a reliability
     * figure saying so.
     *
     * ONE refusal: no candidate speech at all. There is nothing to score, and
     * an evaluation assembled from the avatar's own opening line would be
     * worse than a reported failure. Those participants land in `errore`,
     * which an operator can already recover.
     *
     * "At least one candidate utterance" is deliberately not a quality
     * judgement. Whether an answer is good enough is the scoring engine's
     * question, and it already has a completion gate and a reliability value
     * to answer it with.
     *
     * @return 'in_valutazione'|'errore'|null What it settled to, or null when
     *                                        the CAS found no `in_corso` row —
     *                                        someone else settled first.
     */
    public function settleAbandoned(int $participantId, int $projectId): ?string
    {
        $orgId = Project::whereKey($projectId)->value('organization_id');

        if ($orgId === null) {
            Log::error('settle: cannot settle abandoned participant — project did not resolve', [
                'participant_id' => $participantId,
                'project_id' => $projectId,
            ]);

            return null;
        }

        $spoke = Utterance::whereIn(
            'interview_session_id',
            InterviewSession::where('participant_id', $participantId)
                ->where('project_id', $projectId)
                ->select('id')
        )->where('speaker', 'candidate')->exists();

        $next = $spoke ? 'in_valutazione' : 'errore';

        // The same compare-and-set the finished path uses, and for the same
        // reason: a participant who completed normally in this very moment
        // matches zero rows, so this can never overwrite them or spend a
        // second scoring job on them.
        $won = Participant::where('id', $participantId)
            ->where('organization_id', $orgId)
            ->where('status', 'in_corso')
            ->update(['status' => $next]);

        if ($won !== 1) {
            return null;
        }

        if ($next === 'in_valutazione') {
            FinalizeInterview::dispatch($participantId)->afterCommit();
        }

        return $next;
    }
}
