<?php

declare(strict_types=1);

namespace App\PublicApi\Serializers;

use App\Enums\ApiKeyMode;
use App\Models\Participant;
use App\Models\Project;
use App\Support\PublicApi\InterviewStatus;
use App\Support\PublicApi\PublicId;
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
 * `hosted_url` is ALWAYS `null` here — a judgement call: a hosted URL is
 * only meaningful paired with a FRESH,
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
     * `$recordingReady`, when given, is used VERBATIM instead of running a
     * per-participant query — the SAME batched-vs-single-row split
     * `$progress` already draws (gga review, step 6 follow-up, finding 5):
     * `InterviewController::index()`/`show()` batch it for the whole page
     * via `progressForMany()`; `null` (the default, and the only thing
     * `store()` — a brand-new enrolment with no recording possible yet —
     * ever passes) falls back to a per-row lookup.
     *
     * @param  list<array{competency_code: string, answers: list<array{question_index: int, answered_at: string}>}>|null  $progress
     * @return array{id: string, project_id: string, project?: array<string, mixed>, candidate_ref: string, email: string, display_name: string, role_code: string|null, language: string, status: string, livemode: bool, metadata: array<string, string>, exit_redirect_url: string|null, hosted_url: string|null, progress: list<array{competency_code: string, answers: list<array{question_index: int, answered_at: string}>}>, started_at: string|null, completed_at: string|null, transcript_ready: bool, scoring_ready: bool, recording_ready: bool, created_at: string, updated_at: string}
     */
    public static function toArray(Participant $participant, bool $expandProject = false, ?array $progress = null, ?bool $recordingReady = null): array
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
            'livemode' => $participant->mode === ApiKeyMode::Live,
            'metadata' => $participant->metadata ?? [],
            'exit_redirect_url' => $participant->exit_redirect_url,
            // ALWAYS null here — a plain read never has a fresh session
            // token to embed one for. Exported as `string|null` (not the
            // literal `null` this line alone would infer) by
            // `App\Support\Scramble\InterviewHostedUrlNullableExtension` —
            // see that class's own docblock; step 6 review follow-up, Part
            // A item 4 removed the runtime config hack this used to
            // route through.
            'hosted_url' => null,
            'progress' => $progress ?? self::progress($participant),
            'started_at' => $participant->started_at?->toISOString(),
            'completed_at' => $participant->completed_at?->toISOString(),
            'transcript_ready' => in_array($status, [InterviewStatus::UnderEvaluation, InterviewStatus::Completed], true),
            'scoring_ready' => $status === InterviewStatus::Completed,
            // G-01: true iff an `interview_recordings` row exists for this
            // participant (audio only — video is never exposed, see
            // `App\Http\Controllers\PublicApi\RecordingController`'s own
            // docblock). gga review, step 6 follow-up, finding 5: this used
            // to be a hardcoded `false` left over from before step 6 built
            // the recording pipeline.
            // (bool) cast — `self::recordingReady()`'s return flows through
            // a loop-populated array (`recordingReadyForMany()`'s own
            // `$result[...] = true;`), which Scramble's export could not
            // narrow precisely and rendered as `anyOf: [string, boolean]`
            // without this cast, despite the method's own native `: bool`
            // return type. Caught via the `scramble:export` diff, not a
            // test — same class of fix as
            // `App\Support\Scramble\InterviewHostedUrlNullableExtension`.
            'recording_ready' => (bool) ($recordingReady ?? self::recordingReady($participant)),
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
        $organizationIds = [];

        foreach ($participants as $participant) {
            $participantIds[] = $participant->id;
            $projectIds[] = $participant->project_id;
            $organizationIds[] = $participant->organization_id;
        }

        $projectIds = array_values(array_unique($projectIds));
        // gga review, step 6 follow-up, finding 6: threaded through to BOTH
        // batch queries below — see each one's own docblock for why.
        // Usually a single-element list (every real caller's page is one
        // organization's own `/v1/interviews`), but not assumed to be:
        // `array_unique()` so a deliberately multi-org batch (this class's
        // own unit tests) still filters correctly.
        $organizationIds = array_values(array_unique($organizationIds));

        $competenciesByProject = self::competencyCodesByProject($projectIds, $organizationIds);
        $sessionsByParticipant = self::sessionsByParticipant($participantIds, $organizationIds);

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
     * `project_competencies` itself carries no `organization_id` column —
     * its own migration documents that as a deliberate D22 EXEMPTION,
     * inheriting scoping implicitly through the `project_id` FK
     * (`projects` IS tenant-scoped). Joined to `projects` here (gga review,
     * step 6 follow-up, finding 6) so that inheritance is made EXPLICIT —
     * matching the SAME "stated explicitly, never relied on transitively"
     * discipline every other tenant-scoped query in this codebase already
     * follows for the identical reason — rather than trusting that
     * `$projectIds` was already correctly scoped by the caller.
     *
     * @param  list<int>  $projectIds
     * @param  list<int>  $organizationIds
     * @return array<int, list<string>> keyed by project id, competency codes in project order
     */
    private static function competencyCodesByProject(array $projectIds, array $organizationIds): array
    {
        $rows = DB::table('project_competencies')
            ->join('framework_competencies', 'project_competencies.competency_id', '=', 'framework_competencies.id')
            ->join('projects', 'project_competencies.project_id', '=', 'projects.id')
            ->whereIn('project_competencies.project_id', $projectIds)
            ->whereIn('projects.organization_id', $organizationIds)
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
     * `interview_sessions` DOES carry its own `organization_id` column
     * (`InterviewSession extends TenantModel`) — filtered directly here
     * (gga review, step 6 follow-up, finding 6), the same D22 discipline
     * `competencyCodesByProject()`'s own docblock explains.
     *
     * @param  list<int>  $participantIds
     * @param  list<int>  $organizationIds
     * @return array<int, array<string, array{question_index: mixed, ended_at: mixed}>> keyed by [participant_id][competency_code]
     */
    private static function sessionsByParticipant(array $participantIds, array $organizationIds): array
    {
        $rows = DB::table('interview_sessions')
            ->whereIn('participant_id', $participantIds)
            ->whereIn('organization_id', $organizationIds)
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
     * The single-row convenience `toArray()` falls back to when its own
     * `$progress` argument is `null` (e.g. `App\Http\Controllers\PublicApi\
     * InterviewController::store()`'s brand-new-enrolment response, which
     * has nothing of its own to batch). DELEGATES to `progressForMany()`
     * (step 5 review follow-up, item 9) rather than running its own,
     * separate per-row query — `App\Http\Controllers\PublicApi\
     * InterviewController::show()` now calls `progressForMany()` directly
     * for the identical reason, so every `/v1` interview read converges on
     * the SAME one implementation (`competencyCodesByProject()` +
     * `sessionsByParticipant()`) rather than two independently-written
     * queries that could silently drift apart.
     *
     * @return list<array{competency_code: string, answers: list<array{question_index: int, answered_at: string}>}>
     */
    private static function progress(Participant $participant): array
    {
        return self::progressForMany([$participant])[$participant->id] ?? [];
    }

    /**
     * `recording_ready`, batched over a whole PAGE of participants in
     * exactly ONE query, never one per row (gga review, step 6 follow-up,
     * finding 5) — the SAME "batch, don't N+1" discipline `progressForMany()`
     * already applies to its own two facts. A SEPARATE method rather than
     * folded into `progressForMany()` itself: that method's own return
     * shape (and every existing caller of it) is unchanged, so this adds a
     * new batched fact without widening an already-public contract three
     * call sites already depend on.
     *
     * @param  iterable<Participant>  $participants
     * @return array<int, bool> keyed by participant id — a participant with
     *                          no row is simply absent, never an explicit `false` entry
     */
    public static function recordingReadyForMany(iterable $participants): array
    {
        $participants = $participants instanceof \Traversable ? iterator_to_array($participants) : $participants;

        if ($participants === []) {
            return [];
        }

        $participantIds = [];
        $organizationIds = [];

        foreach ($participants as $participant) {
            $participantIds[] = $participant->id;
            $organizationIds[] = $participant->organization_id;
        }

        // organization_id filtered explicitly (finding 6's identical
        // discipline) even though `InterviewRecording` is itself a
        // TenantModel — this is a raw `DB::table()` read, which bypasses
        // the Eloquent global scope entirely.
        $rows = DB::table('interview_recordings')
            ->whereIn('participant_id', array_values(array_unique($participantIds)))
            ->whereIn('organization_id', array_values(array_unique($organizationIds)))
            ->select(['participant_id'])
            ->get();

        $result = [];

        foreach ($rows as $row) {
            /** @var array{participant_id: mixed} $row */
            $row = (array) $row;
            $participantId = $row['participant_id'];

            if (is_numeric($participantId)) {
                $result[(int) $participantId] = true;
            }
        }

        return $result;
    }

    private static function recordingReady(Participant $participant): bool
    {
        return self::recordingReadyForMany([$participant])[$participant->id] ?? false;
    }
}
