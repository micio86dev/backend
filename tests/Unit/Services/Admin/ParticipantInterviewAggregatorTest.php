<?php

declare(strict_types=1);

/**
 * RED — ParticipantInterviewAggregator (operator-participant-visibility
 * D3/D4/D6, tasks 2.8-2.14).
 *
 * One pass over a participant's InterviewSession rows produces THREE
 * figures — progress, elapsed, cost — each carrying its OWN coverage
 * counts, because cost and elapsed genuinely exclude different sessions
 * (an open session contributes 0 to elapsed; an unrecognised-provider
 * session contributes nothing to cost, and both are true independently).
 *
 * Absence doctrine (D4/D6): `seconds`/`amount` are `null`, never `0`, when
 * nothing could be measured/estimated — `0` asserts a false claim ("no time
 * elapsed" / "this was free") rather than an honest "unknown".
 *
 * REQ: Participant Detail Summary Fields
 *      (openspec/changes/operator-participant-visibility/specs/admin-read-api/spec.md)
 */

use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\Admin\ParticipantInterviewAggregator;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;

function aggOrg(): Organization
{
    return Organization::factory()->create();
}

/**
 * @return array{Project, list<Competency>}
 */
function aggProjectWithCompetencies(Organization $org, int $count): array
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active']);
    $competencies = [];

    for ($i = 0; $i < $count; $i++) {
        $comp = Competency::factory()->create();
        DB::table('project_competencies')->insert([
            'project_id' => $project->id,
            'competency_id' => $comp->id,
            'position' => $i + 1,
        ]);
        $competencies[] = $comp;
    }

    return [$project, $competencies];
}

function aggParticipant(Organization $org, Project $project, string $status = 'in_corso'): Participant
{
    return Participant::factory()->forProject($project)->withStatus($status)->create();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function aggSession(Participant $participant, Project $project, string $code, array $overrides = []): InterviewSession
{
    $session = InterviewSession::create(array_merge([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'provider_session_ref' => 'ref-'.uniqid(),
        'status' => 'completed',
    ], $overrides));

    if (array_key_exists('error_count', $overrides)) {
        DB::table('interview_sessions')
            ->where('id', $session->id)
            ->update(['error_count' => $overrides['error_count']]);
        $session->refresh();
    }

    return $session;
}

/**
 * A CLOSED live period for a session (interview-session-started-at, D8) —
 * duration/cost assertions in this suite MUST reach `liveSeconds()` through
 * a named period, never a bare `started_at`/`ended_at` pair on the session.
 */
function aggClosedPeriod(InterviewSession $session, string $started, string $ended): void
{
    $session->livePeriods()->create([
        'provider_session_ref' => 'ref-'.uniqid(),
        'started_at' => $started,
        'ended_at' => $ended,
        'closed_reason' => 'end',
    ]);
}

/** An OPEN live period (mid-flight, still `in_corso`) — excluded from every sum. */
function aggOpenPeriod(InterviewSession $session, string $started): void
{
    $period = $session->livePeriods()->create([
        'provider_session_ref' => 'ref-'.uniqid(),
        'started_at' => $started,
        'ended_at' => null,
        'closed_reason' => null,
    ]);
}

// ── D3 — progress ──────────────────────────────────────────────────────────

test('progress: 15 project competencies, 6 ended, reports 6/15', function (): void {
    $org = aggOrg();
    [$project, $comps] = aggProjectWithCompetencies($org, 15);
    $participant = aggParticipant($org, $project);

    for ($i = 0; $i < 6; $i++) {
        aggSession($participant, $project, $comps[$i]->code, ['status' => 'completed']);
    }
    // The remaining 9 competencies have no session at all — still 15 total.

    $result = (new ParticipantInterviewAggregator)->aggregate($participant);

    expect($result['progress'])->toBe(['done' => 6, 'total' => 15]);
});

test('progress: a competency detached from the project after completion never reports done > total', function (): void {
    // Reproduces the production defect (participant #43, backoffice):
    // ProjectController::update() lets an admin edit competency_ids on an
    // already-active project with NO lifecycle guard. If the admin detaches
    // a competency the candidate already completed a session for, the raw
    // historical `ended()` count would still include it while `total()`
    // (a live COUNT of project_competencies) has already shrunk — the
    // display must never show more done than total.
    //
    // `endedAmongAttached()` (admin-summary-progress-detach-reattach-fix)
    // scopes done to the SAME live set total() counts, so the detached
    // competency's session drops out of BOTH figures together: 2/2, not the
    // earlier "clamp done up to 3/3" hack that just hid the mismatch.
    $org = aggOrg();
    [$project, $comps] = aggProjectWithCompetencies($org, 3);
    $participant = aggParticipant($org, $project);

    foreach ($comps as $comp) {
        aggSession($participant, $project, $comp->code, ['status' => 'completed']);
    }

    // Admin detaches one competency post-completion — mirrors sync() in
    // ProjectController::update(), which carries no such guard today.
    DB::table('project_competencies')
        ->where('project_id', $project->id)
        ->where('competency_id', $comps[0]->id)
        ->delete();

    $result = (new ParticipantInterviewAggregator)->aggregate($participant);

    expect($result['progress']['total'])->toBeGreaterThanOrEqual($result['progress']['done']);
    expect($result['progress'])->toBe(['done' => 2, 'total' => 2]);
});

test('progress: detaching a done competency and attaching a fresh one never reports false-complete', function (): void {
    // The gap the clamp-based fix above left open: a detach that is also a
    // REPLACE. Three competencies are done; the admin detaches one of them
    // and attaches a competency the participant has never touched. `total()`
    // stays 3 (two survivors plus the new one), and the honest `done` figure
    // must stay at 2 — the two still-attached, still-finished competencies —
    // never 3, which would tell the operator the participant finished a
    // competency they have not even started.
    $org = aggOrg();
    [$project, $comps] = aggProjectWithCompetencies($org, 3);
    $participant = aggParticipant($org, $project);

    foreach ($comps as $comp) {
        aggSession($participant, $project, $comp->code, ['status' => 'completed']);
    }

    DB::table('project_competencies')
        ->where('project_id', $project->id)
        ->where('competency_id', $comps[0]->id)
        ->delete();

    $replacement = Competency::factory()->create();
    DB::table('project_competencies')->insert([
        'project_id' => $project->id,
        'competency_id' => $replacement->id,
        'position' => 99,
    ]);
    // No session ever created for $replacement — the participant has not
    // started it.

    $result = (new ParticipantInterviewAggregator)->aggregate($participant);

    expect($result['progress'])->toBe(['done' => 2, 'total' => 3]);
});

// ── D4 — elapsed ─────────────────────────────────────────────────────────

test('elapsed: two finished sessions of 300s and 480s sum to 780s, with coverage counts', function (): void {
    $org = aggOrg();
    [$project, $comps] = aggProjectWithCompetencies($org, 2);
    $participant = aggParticipant($org, $project);

    $s0 = aggSession($participant, $project, $comps[0]->code);
    aggClosedPeriod($s0, '2026-03-01 10:00:00', '2026-03-01 10:05:00'); // 300s

    $s1 = aggSession($participant, $project, $comps[1]->code);
    aggClosedPeriod($s1, '2026-03-01 11:00:00', '2026-03-01 11:08:00'); // 480s

    $result = (new ParticipantInterviewAggregator)->aggregate($participant);

    expect($result['elapsed']['seconds'])->toBe(780);
    expect($result['elapsed']['sessions_counted'])->toBe(2);
    expect($result['elapsed']['sessions_total'])->toBe(2);
});

test('elapsed: no session has finished at all — seconds is null, never 0', function (): void {
    $org = aggOrg();
    [$project, $comps] = aggProjectWithCompetencies($org, 1);
    $participant = aggParticipant($org, $project);

    $s0 = aggSession($participant, $project, $comps[0]->code, ['status' => 'in_corso']);
    aggOpenPeriod($s0, '2026-03-01 10:00:00');

    $result = (new ParticipantInterviewAggregator)->aggregate($participant);

    expect($result['elapsed']['seconds'])->toBeNull();
    expect($result['elapsed']['sessions_counted'])->toBe(0);
});

test('elapsed: an open session contributes 0 and is excluded from sessions_counted — no now() clamp', function (): void {
    $org = aggOrg();
    [$project, $comps] = aggProjectWithCompetencies($org, 2);
    $participant = aggParticipant($org, $project);

    // Finished session: 300s.
    $s0 = aggSession($participant, $project, $comps[0]->code);
    aggClosedPeriod($s0, '2026-03-01 10:00:00', '2026-03-01 10:05:00');

    // Open session, started weeks ago — must NOT be clamped with now().
    $s1 = aggSession($participant, $project, $comps[1]->code, ['status' => 'in_corso']);
    aggOpenPeriod($s1, now()->subWeeks(3)->toDateTimeString());

    $result = (new ParticipantInterviewAggregator)->aggregate($participant);

    expect($result['elapsed']['seconds'])->toBe(300);
    expect($result['elapsed']['sessions_counted'])->toBe(1);
    expect($result['elapsed']['sessions_total'])->toBe(2);
});

// ── D6 — cost ────────────────────────────────────────────────────────────

test('cost: 3 sessions, 2 estimable, sums the 2 and discloses 2/3 contributed', function (): void {
    config()->set('interview.rates.heygen.credits_per_minute', 2);
    config()->set('interview.rates.heygen.usd_per_credit', 0.10);

    $org = aggOrg();
    [$project, $comps] = aggProjectWithCompetencies($org, 3);
    $participant = aggParticipant($org, $project);

    // Estimable: 10 min * 2 credits * $0.10 = $2.00
    $s0 = aggSession($participant, $project, $comps[0]->code, ['provider' => 'heygen']);
    aggClosedPeriod($s0, '2026-03-01 10:00:00', '2026-03-01 10:10:00');

    // Estimable: 5 min * 2 credits * $0.10 = $1.00
    $s1 = aggSession($participant, $project, $comps[1]->code, ['provider' => 'heygen']);
    aggClosedPeriod($s1, '2026-03-01 11:00:00', '2026-03-01 11:05:00');

    // Not estimable: unfinished.
    $s2 = aggSession($participant, $project, $comps[2]->code, ['status' => 'in_corso', 'provider' => 'heygen']);
    aggOpenPeriod($s2, '2026-03-01 12:00:00');

    $result = (new ParticipantInterviewAggregator)->aggregate($participant);

    expect($result['cost']['amount'])->toBe(3.0);
    expect($result['cost']['currency'])->toBe('USD');
    expect($result['cost']['is_estimate'])->toBeTrue();
    expect($result['cost']['sessions_estimated'])->toBe(2);
    expect($result['cost']['sessions_total'])->toBe(3);
});

test('cost: no session yields an estimate — amount is null, never 0', function (): void {
    $org = aggOrg();
    [$project, $comps] = aggProjectWithCompetencies($org, 1);
    $participant = aggParticipant($org, $project);

    $s0 = aggSession($participant, $project, $comps[0]->code, ['provider' => 'some_future_provider']);
    aggClosedPeriod($s0, '2026-03-01 10:00:00', '2026-03-01 10:10:00');

    $result = (new ParticipantInterviewAggregator)->aggregate($participant);

    expect($result['cost']['amount'])->toBeNull();
    expect($result['cost']['sessions_estimated'])->toBe(0);
    expect($result['cost']['sessions_total'])->toBe(1);
});

// ── Query discipline ─────────────────────────────────────────────────────

test('aggregate() loads the participant\'s raw session rows exactly once', function (): void {
    $org = aggOrg();
    [$project, $comps] = aggProjectWithCompetencies($org, 3);
    $participant = aggParticipant($org, $project);

    aggSession($participant, $project, $comps[0]->code);
    aggSession($participant, $project, $comps[1]->code);
    aggSession($participant, $project, $comps[2]->code);

    DB::enableQueryLog();
    (new ParticipantInterviewAggregator)->aggregate($participant);
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    // Content-based, not an unrelated total: isolate raw SELECTs against
    // interview_sessions from any COUNT-aggregate query CompetencyTally
    // issues for the numerator — those are a different concern (the tally),
    // not part of the one-pass session LOAD this assertion pins.
    $rawSessionLoads = array_filter(
        $log,
        fn (array $entry): bool => str_contains(strtolower((string) $entry['query']), 'interview_sessions')
            && ! str_contains(strtolower((string) $entry['query']), 'count(')
    );

    expect($rawSessionLoads)->toHaveCount(1, 'Elapsed and cost must derive from ONE raw session load, not one query per figure.');
});
