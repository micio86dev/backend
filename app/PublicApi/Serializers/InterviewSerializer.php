<?php

declare(strict_types=1);

namespace App\PublicApi\Serializers;

use App\Models\Participant;
use App\Models\Project;
use App\Support\PublicApi\InterviewStatus;
use App\Support\PublicApi\PublicId;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Public-safe `Interview` shape for the BEAI Public API (`/v1`) — SPEC.md
 * §3.3/§3.4, `public-api/openapi.yaml`'s `Interview` schema. `Interview` IS
 * the `participants` enrolment (ruling 8, G-02) — one serializer, shared by
 * `createInterview`, `listInterviews` and `getInterview` (SPEC.md §3.4
 * "one serializer per resource").
 *
 * `hosted_url` is ALWAYS `null` here — a judgement call (see this class's
 * own report entry): a hosted URL is only meaningful paired with a FRESH,
 * unconsumed session token, and a plain read has none to embed. §3.3's own
 * contract note documents the alternative source: `POST /interviews` and
 * `POST /interviews/{id}/session-tokens` each carry their OWN `hosted_url`,
 * built by `App\Support\PublicApi\SessionTokenMinter::mint()`'s caller from
 * the token it just minted — never reconstructed from a stored value,
 * because none is stored (SPEC.md §3.5: minting again revokes the
 * previous token, so a stored URL would go stale the instant a new one is
 * minted). This is schema-compliant either way: `hosted_url` is declared,
 * not required, in `Interview` (only `SessionToken.hosted_url` is
 * required).
 */
final class InterviewSerializer
{
    /**
     * `$participant->project` should be eager-loaded by the caller when
     * `$expandProject` is true (mirrors `ProjectSerializer::toArray()`'s own
     * "unloaded relation renders absent, never a lazy-load" discipline —
     * see `App\Support\PublicApi\Expand`). Whether eager-loaded or lazily
     * resolved by `self::project()` below, it is ALWAYS resolved
     * `withTrashed()` (gga round 3 finding 1): `Project` uses `SoftDeletes`,
     * and the enrolment is the CALLING SYSTEM's data, not the project's — an
     * admin archiving/soft-deleting a project later must never make an
     * already-created interview 404 or 500 on read.
     *
     * `$progress`, when given, is used VERBATIM instead of running
     * `self::progress()`'s own per-participant query — gga finding 4:
     * `App\Http\Controllers\PublicApi\InterviewController::index()` batches
     * a whole page's progress in `progressForMany()` (two queries total,
     * never one per row) and passes each participant's slice in here. `null`
     * (the default, and the ONLY thing `getInterview`/`createInterview` — a
     * single row each — ever pass) falls back to the original per-row query,
     * which is the right cost for exactly one row.
     *
     * @param  list<array{competency_code: string, answers: list<array{question_index: int, answered_at: string}>}>|null  $progress
     * @return array{id: string, project_id: string, project?: array<string, mixed>, candidate_ref: string, email: string, display_name: string, role_code: string|null, language: string, status: string, livemode: bool, metadata: array<string, string>, exit_redirect_url: string|null, hosted_url: null, progress: list<array{competency_code: string, answers: list<array{question_index: int, answered_at: string}>}>, started_at: string|null, completed_at: string|null, transcript_ready: bool, scoring_ready: bool, recording_ready: bool, created_at: string, updated_at: string}
     */
    public static function toArray(Participant $participant, bool $expandProject = false, ?array $progress = null): array
    {
        $status = InterviewStatus::fromStored($participant->status);
        $project = self::project($participant);

        $data = [
            'id' => PublicId::encode($participant),
            'project_id' => PublicId::encode($project),
            'candidate_ref' => $participant->candidate_ref,
            'email' => $participant->email,
            'display_name' => $participant->display_name,
            'role_code' => $participant->role_code,
            'language' => (string) $participant->language,
            'status' => $status->value,
            'livemode' => $participant->mode->value === 'live',
            'metadata' => $participant->metadata ?? [],
            'exit_redirect_url' => $participant->exit_redirect_url,
            'hosted_url' => null,
            'progress' => $progress ?? self::progress($participant),
            'started_at' => $participant->started_at?->toISOString(),
            'completed_at' => $participant->completed_at?->toISOString(),
            'transcript_ready' => in_array($status, [InterviewStatus::UnderEvaluation, InterviewStatus::Completed], true),
            'scoring_ready' => $status === InterviewStatus::Completed,
            // G-01 (binding): video recordings are backoffice-only, never
            // exposed via the public API — audio readiness is not yet wired
            // in step 5 (no recording pipeline read here), so this is
            // always false until a later step adds it.
            'recording_ready' => false,
            'created_at' => (string) $participant->created_at->toISOString(),
            'updated_at' => (string) $participant->updated_at->toISOString(),
        ];

        if ($expandProject && $participant->relationLoaded('project')) {
            $data['project'] = ProjectSerializer::toArray($project);
        }

        return $data;
    }

    /**
     * `project_id` is a required, NOT NULL foreign key — the relation is
     * never genuinely null at runtime — but `Participant::project()`'s
     * static return type is nullable `BelongsTo`, so this resolves it
     * explicitly and fails loud (a real, honest runtime guard, never an
     * `@var` override of PHPStan's inferred type) rather than silently
     * trusting an orphaned FK.
     *
     * The EAGER-LOADED case (`relationLoaded('project')` — every real `/v1`
     * caller loads it `withTrashed()`, see
     * `App\Http\Controllers\PublicApi\InterviewController`) is used
     * verbatim. The LAZY fallback (a caller that never eager-loaded it —
     * none of this API's own controllers hit this branch, but a future one
     * might) re-queries `withTrashed()` itself rather than through the
     * magic `->project` accessor, which applies `Project`'s default
     * `SoftDeletingScope` and would wrongly return `null` — and therefore
     * throw the "orphaned FK" exception below — for a project that is
     * merely soft-deleted, not actually gone (gga round 3 finding 1).
     *
     * @throws LogicException when the FK is genuinely orphaned (no row at
     *                        all, trashed or not).
     */
    private static function project(Participant $participant): Project
    {
        $project = $participant->relationLoaded('project')
            ? $participant->getRelation('project')
            : $participant->project()->withTrashed()->first();

        if (! $project instanceof Project) {
            throw new LogicException(sprintf(
                'Participant %d: project_id %d does not resolve to a Project row.',
                $participant->id,
                $participant->project_id,
            ));
        }

        return $project;
    }

    /**
     * `progress()`'s SAME fact, batched over a whole PAGE of participants in
     * exactly TWO queries total, never one per row (gga finding 4). Splits
     * cleanly because the two facts a per-row query joins together are
     * independently batchable: "every competency for this participant's
     * project" (one query, `WHERE project_id IN (...)`, since a page can mix
     * participants from several projects) and "every interview_sessions row
     * for this participant" (one query, `WHERE participant_id IN (...)`) —
     * joined back together in PHP instead of in SQL.
     *
     * @param  iterable<Participant>  $participants
     * @return array<int, list<array{competency_code: string, answers: list<array{question_index: int, answered_at: string}>}>> keyed by participant id
     */
    public static function progressForMany(iterable $participants): array
    {
        $participants = $participants instanceof \Traversable ? iterator_to_array($participants) : $participants;

        if ($participants === []) {
            return [];
        }

        $participantIds = [];
        $projectIds = [];

        foreach ($participants as $participant) {
            $participantIds[] = $participant->id;
            $projectIds[] = $participant->project_id;
        }

        $projectIds = array_values(array_unique($projectIds));

        $competenciesByProject = self::competencyCodesByProject($projectIds);
        $sessionsByParticipant = self::sessionsByParticipant($participantIds);

        $result = [];

        foreach ($participants as $participant) {
            $codes = $competenciesByProject[$participant->project_id] ?? [];
            $entries = [];

            foreach ($codes as $code) {
                $session = $sessionsByParticipant[$participant->id][$code] ?? null;
                $entries[] = [
                    'competency_code' => $code,
                    'answers' => $session === null ? [] : self::answersFromSession($session['question_index'], $session['ended_at']),
                ];
            }

            $result[$participant->id] = $entries;
        }

        return $result;
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, list<string>> keyed by project id, competency codes in project order
     */
    private static function competencyCodesByProject(array $projectIds): array
    {
        $rows = DB::table('project_competencies')
            ->join('framework_competencies', 'project_competencies.competency_id', '=', 'framework_competencies.id')
            ->whereIn('project_competencies.project_id', $projectIds)
            ->orderBy('project_competencies.position')
            ->select(['project_competencies.project_id as project_id', 'framework_competencies.code as code'])
            ->get();

        $byProject = [];

        foreach ($rows as $row) {
            /** @var array{project_id: mixed, code: mixed} $row */
            $row = (array) $row;
            $projectId = $row['project_id'];
            $code = $row['code'];

            if (! is_numeric($projectId) || ! is_string($code)) {
                continue;
            }

            $byProject[(int) $projectId][] = $code;
        }

        return $byProject;
    }

    /**
     * @param  list<int>  $participantIds
     * @return array<int, array<string, array{question_index: mixed, ended_at: mixed}>> keyed by [participant_id][competency_code]
     */
    private static function sessionsByParticipant(array $participantIds): array
    {
        $rows = DB::table('interview_sessions')
            ->whereIn('participant_id', $participantIds)
            ->select(['participant_id', 'competency_code', 'question_index', 'ended_at'])
            ->get();

        $byParticipant = [];

        foreach ($rows as $row) {
            /** @var array{participant_id: mixed, competency_code: mixed, question_index: mixed, ended_at: mixed} $row */
            $row = (array) $row;
            $participantId = $row['participant_id'];
            $code = $row['competency_code'];

            if (! is_numeric($participantId) || ! is_string($code)) {
                continue;
            }

            $byParticipant[(int) $participantId][$code] = [
                'question_index' => $row['question_index'],
                'ended_at' => $row['ended_at'],
            ];
        }

        return $byParticipant;
    }

    /**
     * A SINGLE mutable-array-push (never two `return` statements with
     * different literal shapes) — Scramble's static inference reads the
     * LITERAL return shapes, not just this docblock, and two differently-
     * shaped `return`s here previously turned the contract's clean
     * `CompetencyProgress.answers: array of {question_index, answered_at}`
     * into a spurious `anyOf` union (an empty-tuple branch alongside the
     * real one) the very first time this method existed — caught via the
     * `scramble:export` diff, not a test.
     *
     * @return list<array{question_index: int, answered_at: string}>
     */
    private static function answersFromSession(mixed $questionIndex, mixed $endedAt): array
    {
        $answers = [];

        if ($endedAt !== null && (is_string($endedAt) || $endedAt instanceof \DateTimeInterface) && is_numeric($questionIndex)) {
            $answers[] = [
                'question_index' => (int) $questionIndex,
                'answered_at' => Carbon::parse($endedAt)->utc()->toIso8601String(),
            ];
        }

        return $answers;
    }

    /**
     * Every project competency, LEFT JOINed to this participant's
     * `interview_sessions` row for it — a competency with no session yet
     * still appears, with an empty `answers` list (SPEC.md §3.3
     * `CompetencyProgress`). Mirrors
     * `App\Services\Webhooks\ProgressPayloadAssembler::assemble()`'s
     * identical query shape (same underlying fact: one `interview_sessions`
     * row per competency attempt, at most one `ended_at` per competency) —
     * deliberately NOT reused directly, since that class returns the
     * webhook's own payload envelope (`version`/`event`/`delivery_id`),
     * not this schema's `competency_code`/`answers` shape.
     *
     * @return list<array{competency_code: string, answers: list<array{question_index: int, answered_at: string}>}>
     */
    private static function progress(Participant $participant): array
    {
        $rows = DB::table('project_competencies')
            ->join('framework_competencies', 'project_competencies.competency_id', '=', 'framework_competencies.id')
            ->leftJoin('interview_sessions', function (JoinClause $join) use ($participant): void {
                $join->on('interview_sessions.competency_code', '=', 'framework_competencies.code')
                    ->where('interview_sessions.participant_id', '=', $participant->id);
            })
            ->where('project_competencies.project_id', $participant->project_id)
            ->orderBy('project_competencies.position')
            ->select([
                'framework_competencies.code as code',
                'interview_sessions.question_index as question_index',
                'interview_sessions.ended_at as ended_at',
            ])
            ->get();

        $entries = [];

        foreach ($rows as $row) {
            /** @var array{code: mixed, question_index: mixed, ended_at: mixed} $row */
            $row = (array) $row;

            $code = $row['code'];

            $entries[] = [
                'competency_code' => is_string($code) ? $code : '',
                'answers' => self::answersFromSession($row['question_index'], $row['ended_at']),
            ];
        }

        return $entries;
    }
}
