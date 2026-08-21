<?php

declare(strict_types=1);

namespace App\Support\Interview;

use App\Models\InterviewSession;
use Illuminate\Support\Facades\DB;

/**
 * Competencies that have reached a terminal state for a participant, and the
 * project's total competency count (operator-participant-visibility D5).
 *
 * ONE class owns BOTH halves of "how far has this participant progressed" —
 * the numerator (`ended()`) and the denominator (`total()`) — so they can
 * never be sourced apart. Before this class, the numerator lived as a
 * private method on `InterviewController` (candidate `/end` directive and
 * the completion CAS) and the denominator was inlined TWICE (`InterviewController.php`
 * `buildDirective()` and `settleCompletionIfFinished()`). The admin surface
 * could not call the private method, and re-implementing the predicate would
 * have created the exact divergent-tally defect this class exists to close:
 * the admin's `done` count silently disagreeing with the candidate's own
 * `ended_competencies`.
 *
 * `ended()` is `InterviewController::endedCompetencyCount()`'s body, moved
 * VERBATIM — this is a rename, not a re-implementation. `InterviewController`
 * has no behaviour change: both its call sites now delegate here.
 *
 * REQ: Participant Detail Summary Fields
 *      (openspec/changes/operator-participant-visibility/specs/admin-read-api/spec.md)
 */
final class CompetencyTally
{
    /**
     * Competencies that have reached a terminal state for this participant.
     *
     * ONE definition, shared by the candidate completion CAS, the /end
     * directive, and the admin summary read: two copies of this predicate
     * would be two chances to disagree about when an interview is over,
     * which is the defect class this class exists to close.
     *
     * An `error` counts only once it has spent its re-offer. Count it
     * earlier and a single transient 4xx — our own payload bug — ends the
     * interview with no second chance. Count it later and a competency the
     * resolver skips is never tallied, which is the original stranding
     * under another name.
     */
    public function ended(int $participantId, int $projectId): int
    {
        return InterviewSession::where('participant_id', $participantId)
            ->where('project_id', $projectId)
            ->where(function ($q): void {
                $q->whereIn('status', ['completed', 'timeout', 'skipped'])
                    // An `error` counts only once it has spent its re-offer. Below
                    // the ceiling the competency is still offerable, so counting it
                    // would end an interview the candidate can still finish.
                    ->orWhere(fn ($e) => $e->where('status', 'error')
                        ->where('error_count', '>=', InterviewSession::MAX_ERROR_ATTEMPTS));
            })
            ->count();
    }

    /**
     * How many competencies the project configures — the denominator every
     * "done / total" figure divides by, on both the candidate and admin
     * surfaces.
     */
    public function total(int $projectId): int
    {
        return DB::table('project_competencies')->where('project_id', $projectId)->count();
    }
}
