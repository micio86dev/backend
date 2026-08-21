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

// ── D4 — elapsed ─────────────────────────────────────────────────────────

test('elapsed: two finished sessions of 300s and 480s sum to 780s, with coverage counts', function (): void {
    $org = aggOrg();
    [$project, $comps] = aggProjectWithCompetencies($org, 2);
    $participant = aggParticipant($org, $project);

    aggSession($participant, $project, $comps[0]->code, [
        'started_at' => '2026-03-01 10:00:00',
        'ended_at' => '2026-03-01 10:05:00', // 300s
    ]);
    aggSession($participant, $project, $comps[1]->code, [
        'started_at' => '2026-03-01 11:00:00',
        'ended_at' => '2026-03-01 11:08:00', // 480s
    ]);

    $result = (new ParticipantInterviewAggregator)->aggregate($participant);

    expect($result['elapsed']['seconds'])->toBe(780);
    expect($result['elapsed']['sessions_counted'])->toBe(2);
    expect($result['elapsed']['sessions_total'])->toBe(2);
});

test('elapsed: no session has finished at all — seconds is null, never 0', function (): void {
    $org = aggOrg();
    [$project, $comps] = aggProjectWithCompetencies($org, 1);
    $participant = aggParticipant($org, $project);

    aggSession($participant, $project, $comps[0]->code, [
        'status' => 'in_corso',
        'started_at' => '2026-03-01 10:00:00',
        'ended_at' => null,
    ]);

    $result = (new ParticipantInterviewAggregator)->aggregate($participant);

    expect($result['elapsed']['seconds'])->toBeNull();
    expect($result['elapsed']['sessions_counted'])->toBe(0);
});

test('elapsed: an open session contributes 0 and is excluded from sessions_counted — no now() clamp', function (): void {
    $org = aggOrg();
    [$project, $comps] = aggProjectWithCompetencies($org, 2);
    $participant = aggParticipant($org, $project);

    // Finished session: 300s.
    aggSession($participant, $project, $comps[0]->code, [
        'started_at' => '2026-03-01 10:00:00',
        'ended_at' => '2026-03-01 10:05:00',
    ]);

    // Open session, started weeks ago — must NOT be clamped with now().
    aggSession($participant, $project, $comps[1]->code, [
        'status' => 'in_corso',
        'started_at' => now()->subWeeks(3),
        'ended_at' => null,
    ]);

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
    aggSession($participant, $project, $comps[0]->code, [
        'provider' => 'heygen',
        'started_at' => '2026-03-01 10:00:00',
        'ended_at' => '2026-03-01 10:10:00',
    ]);
    // Estimable: 5 min * 2 credits * $0.10 = $1.00
    aggSession($participant, $project, $comps[1]->code, [
        'provider' => 'heygen',
        'started_at' => '2026-03-01 11:00:00',
        'ended_at' => '2026-03-01 11:05:00',
    ]);
    // Not estimable: unfinished.
    aggSession($participant, $project, $comps[2]->code, [
        'status' => 'in_corso',
        'provider' => 'heygen',
        'started_at' => '2026-03-01 12:00:00',
        'ended_at' => null,
    ]);

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

    aggSession($participant, $project, $comps[0]->code, [
        'provider' => 'some_future_provider',
        'started_at' => '2026-03-01 10:00:00',
        'ended_at' => '2026-03-01 10:10:00',
    ]);

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
