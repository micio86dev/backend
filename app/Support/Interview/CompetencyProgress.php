<?php

declare(strict_types=1);

namespace App\Support\Interview;

use App\Models\InterviewSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The competency order of a project and the "which competency is open" rule
 * (live-interview-call-chrome D2).
 *
 * ONE class owns both halves so `/start` and the interview outline can never
 * disagree about which competency is current. Before this class the join lived
 * inline in `InterviewController` (twice) and the open/closed rule lived in a
 * private method; the outline needs both, and a second copy of the rule is the
 * divergence this class exists to prevent.
 *
 * `resolveNext()` is `InterviewController::resolveNextCompetency()`, MOVED with
 * its behaviour unchanged: the first competency in project order whose session
 * is open. The `competency_ordinal` is the ARRAY INDEX of the ordered list, not
 * `position`; `question_index` is `position` verbatim (position is 0-based at
 * every writer).
 *
 * The re-offer branch is ordered AFTER pending|in_corso: a session already reset
 * to `pending` is caught first and never reaches it, so "Resume, not restart"
 * holds verbatim. This class resolves, it never mutates.
 *
 * REQ: interview-session "Interview outline"
 *      (openspec/changes/live-interview-call-chrome/specs/interview-session/spec.md)
 */
final class CompetencyProgress
{
    /**
     * The project's competencies by position. Raw join, NOT tenant scoped: the
     * caller passes the participant's own project id.
     *
     * Each row carries `competency_id`, `competency_code` and `position`.
     *
     * @return Collection<int, \stdClass>
     */
    public function ordered(int $projectId): Collection
    {
        return DB::table('project_competencies as pc')
            ->join('framework_competencies as fc', 'fc.id', '=', 'pc.competency_id')
            ->where('pc.project_id', $projectId)
            ->orderBy('pc.position')
            ->select('fc.id as competency_id', 'fc.code as competency_code', 'pc.position')
            ->get();
    }

    /**
     * Whether the competency still has to be (re)asked: no session yet, a pending or
     * in_corso session (resume), or an errored one with attempts left (re-offer).
     * completed | timeout | skipped | an exhausted error are closed.
     */
    public static function isOpen(?InterviewSession $session): bool
    {
        if ($session === null) {
            return true;
        }

        if (in_array($session->status, ['pending', 'in_corso'], true)) {
            return true;
        }

        return $session->status === 'error' && $session->error_count < InterviewSession::MAX_ERROR_ATTEMPTS;
    }

    /**
     * The next competency for this participant, or null when all are closed.
     *
     * @return array{competency_code: string, question_index: int, competency_ordinal: int, total_competencies: int, reoffer?: bool}|null
     */
    public function resolveNext(int $participantId, int $projectId): ?array
    {
        $all = $this->ordered($projectId);

        $existing = InterviewSession::where('participant_id', $participantId)
            ->where('project_id', $projectId)
            ->get(['competency_code', 'status', 'error_count'])
            ->keyBy('competency_code');

        $total = $all->count();

        foreach ($all->values() as $index => $row) {
            $session = $existing->get($row->competency_code);

            if (! self::isOpen($session)) {
                continue;
            }

            $payload = [
                'competency_code' => (string) $row->competency_code,
                'question_index' => (int) $row->position,
                'competency_ordinal' => $index + 1,
                'total_competencies' => $total,
            ];

            // Only an errored session is a re-offer; a missing or pending/in_corso one is a plain (re)start.
            if ($session?->status === 'error') {
                $payload['reoffer'] = true;
            }

            return $payload;
        }

        return null;
    }
}
