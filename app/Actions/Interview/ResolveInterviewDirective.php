<?php

declare(strict_types=1);

namespace App\Actions\Interview;

use App\Models\Project;
use App\Support\Interview\CompetencyTally;

/**
 * ResolveInterviewDirective — what the client should do next (D7).
 *
 * A MOVE of `InterviewController::buildDirective()`, verbatim (split-interview-
 * controller). Called from `/end`, INSIDE its explicit DB transaction — this class
 * opens no transaction of its own, so that placement is unaffected by the move.
 *
 * Computed on the SERVER because the SA-04 pause cadence is TENANT CONFIGURATION
 * (`projects.pause_every_n_competencies`; `null` = never pause). A browser must not
 * re-derive tenant policy, the numbers are already in hand here, and client-side
 * arithmetic is the documented cause of the defect this change removes — the page
 * carried an empty competency list and concluded every interview was over after one
 * question.
 *
 * `done` is evaluated FIRST so a pause is never due on the final competency: a
 * candidate must not be shown a break screen for an interview that is over.
 *
 * A null project fails closed to "no pause" rather than guessing a cadence.
 */
final class ResolveInterviewDirective
{
    /**
     * @return array{ended_competencies: int, total_competencies: int, next_action: string}
     */
    public function handle(int $participantId, int $projectId): array
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
}
