<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\InterviewSession;
use App\Models\Participant;
use App\Services\Proctoring\SessionCostEstimator;
use App\Support\Interview\CompetencyTally;

/**
 * The five missing facts, derived once, at read time, over one pass of a
 * participant's InterviewSession rows (operator-participant-visibility
 * D3/D4/D6).
 *
 * Progress, elapsed and cost all iterate the same rows. Three independent
 * queries in `ParticipantDetailResource` would be three chances to disagree
 * about which sessions count. Elapsed and cost are therefore computed
 * together, in ONE pass over ONE raw session load. Progress delegates its
 * numerator and denominator to `CompetencyTally` — never re-derived here,
 * per D5: a second implementation of "how many competencies has this
 * participant ended" is precisely the divergent-tally defect this change
 * exists to close.
 *
 * Each figure carries its OWN coverage counts, adjacent to the number,
 * because they genuinely differ: cost also excludes unrecognised providers;
 * elapsed does not. One shared "n of m" would be wrong for at least one of
 * them.
 *
 * Ambient `TenantContext` scope only — never `withoutGlobalScopes()`
 * (arch-tested, `AdminTenancySafetyArchTest`).
 *
 * REQ: Participant Detail Summary Fields
 *      (openspec/changes/operator-participant-visibility/specs/admin-read-api/spec.md)
 */
final class ParticipantInterviewAggregator
{
    /**
     * @return array{
     *     progress: array{done: int, total: int},
     *     elapsed: array{seconds: int|null, sessions_counted: int, sessions_total: int},
     *     cost: array{amount: float|null, currency: string, is_estimate: bool, sessions_estimated: int, sessions_total: int},
     * }
     */
    public function aggregate(Participant $participant): array
    {
        $participantId = (int) $participant->id;
        $projectId = (int) $participant->project_id;

        $tally = new CompetencyTally;

        // ONE raw session load — elapsed and cost are both derived from it in
        // the same pass below. Progress's numerator/denominator are sourced
        // from CompetencyTally instead (D5) — a COUNT-aggregate query, a
        // different concern from this row-by-row pass.
        $sessions = InterviewSession::where('participant_id', $participantId)
            ->where('project_id', $projectId)
            ->get();

        $estimator = new SessionCostEstimator;

        $elapsedSeconds = 0;
        $sessionsCounted = 0;
        $costTotal = 0.0;
        $sessionsEstimated = 0;

        foreach ($sessions as $session) {
            // D4: sum only sessions where BOTH timestamps exist and the delta
            // is positive. An open session (started_at, no ended_at)
            // contributes 0 and is excluded — never clamped with now(),
            // which would report weeks for an abandoned tab.
            if ($session->started_at !== null && $session->ended_at !== null) {
                $delta = $session->ended_at->getTimestamp() - $session->started_at->getTimestamp();

                if ($delta > 0) {
                    $elapsedSeconds += $delta;
                    $sessionsCounted++;
                }
            }

            // D6: reuse the estimator unmodified, sum its already-rounded
            // values. Sessions it declines (unfinished, non-positive
            // duration, unrecognised provider) are excluded and counted.
            $estimate = $estimator->estimate($session);

            if ($estimate !== null) {
                $costTotal += $estimate['usd'];
                $sessionsEstimated++;
            }
        }

        $sessionsTotal = $sessions->count();

        return [
            'progress' => [
                'done' => $tally->ended($participantId, $projectId),
                'total' => $tally->total($projectId),
            ],
            'elapsed' => [
                // Absent, never 0: 0 asserts "it took no time", which is
                // false for a candidate whose sessions simply have not
                // finished yet (D4).
                'seconds' => $sessionsCounted > 0 ? $elapsedSeconds : null,
                'sessions_counted' => $sessionsCounted,
                'sessions_total' => $sessionsTotal,
            ],
            'cost' => [
                // Absent, never 0: 0 reads as "this was free" (D6,
                // SessionCostEstimator's own refusal doctrine).
                'amount' => $sessionsEstimated > 0 ? round($costTotal, 4) : null,
                'currency' => 'USD',
                'is_estimate' => true,
                'sessions_estimated' => $sessionsEstimated,
                'sessions_total' => $sessionsTotal,
            ],
        ];
    }
}
