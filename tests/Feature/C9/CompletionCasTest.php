<?php

declare(strict_types=1);

/**
 * Completion CAS — a participant whose competencies are all terminal MUST reach
 * `in_valutazione` (interview-continuous-flow, D1/D5).
 *
 * The defect this pins: `/end` counted only `completed|timeout|skipped`, while
 * `resolveNextCompetency()` treats `error` as terminal and skips it. A participant
 * with one errored competency therefore exhausted every competency while the tally
 * stayed at `total - 1`, so the CAS never fired — no scoring, no webhook, no
 * notification, and nothing anywhere said so.
 *
 * `/end` alone could never have repaired it: a session reaches `error` inside
 * `/start`'s provider-failure path, and `/end` is never called for that competency.
 * The repair is the two additional call sites.
 *
 * SCOPE NOTE (PR 1 of 4): the automatic re-offer does not exist yet, so EVERY
 * `error` is terminal here and every `error` counts. PR 2 adds the re-offer branch
 * to `resolveNextCompetency()` and narrows this tally to
 * `error_count >= MAX_ERROR_ATTEMPTS` in the same commit — narrowing it earlier
 * would leave a session at `error_count = 1` skipped by the resolver but uncounted
 * by the tally, which is the same stranding under a new name.
 */

use App\Jobs\FinalizeInterview;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ─── Fixtures (namespaced `cas*` so they never collide with the C7a helpers) ──

function casOrg(): Organization
{
    return Organization::factory()->create();
}

/**
 * A project with `$count` competencies, each with a composition-sufficient
 * BarsIndicator so `/start` can compose a prompt.
 *
 * @return array{0: Project, 1: list<Competency>}
 */
function casProject(Organization $org, int $count = 1): array
{
    // Tenant context must exist before creating tenant-scoped models — the project
    // factory pulls a FrameworkVersion with it.
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create([
        'status' => 'active',
        'assessment_type' => 'standard',
    ]);

    // firstOrCreate, not create: roles are a platform-wide catalogue with a unique
    // code, so two organizations in one test share the same role row.
    $role = Role::firstOrCreate(
        ['code' => $project->role_code],
        Role::factory()->make(['code' => $project->role_code])->getAttributes(),
    );

    $competencies = [];
    for ($i = 0; $i < $count; $i++) {
        $comp = Competency::factory()->create();
        DB::table('project_competencies')->insert([
            'project_id' => $project->id,
            'competency_id' => $comp->id,
            'position' => $i + 1,
        ]);

        $ind = new BarsIndicator;
        $ind->forceFill([
            'role_id' => $role->id,
            'competency_id' => $comp->id,
            'text' => ['en' => "CAS fixture indicator {$i}"],
            'anchor_5' => ['en' => "Excellent {$i}"],
            'anchor_3' => ['en' => "Adequate {$i}"],
            'anchor_1' => ['en' => "Insufficient {$i}"],
            'position' => 0,
        ]);
        $ind->save();

        $competencies[] = $comp;
    }

    return [$project, $competencies];
}

function casParticipant(Organization $org, Project $project, string $status = 'in_corso'): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'cas-'.uniqid(),
        'display_name' => 'CAS Test Candidate',
        'status' => $status,
        'started_at' => now(),
    ]);
    $p->save();

    return $p->fresh();
}

function casBearer(Participant $participant): string
{
    return CandidateTokenFactory::mintCandidateToken($participant);
}

/** Provider returns 4xx → ProviderFailureClass::ClientError → session error, participant untouched. */
function casClientErrorFake(): array
{
    return ['*liveavatar*' => Http::response(['message' => 'malformed request'], 422)];
}

/** Provider returns 5xx → ProviderFailureClass::Upstream → session error AND participant errore. */
function casUpstreamFake(): array
{
    return ['*liveavatar*' => Http::response(['message' => 'upstream exploded'], 503)];
}

function casInTenant(Organization $org, callable $fn): mixed
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return $fn();
}

// ─── error_count is written where the error is ────────────────────────────────

test('markSessionError increments error_count', function (): void {
    Http::fake(casClientErrorFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 1);
    $participant = casParticipant($org, $project);

    $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(500); // ClientError — our bug, not the upstream's

    $session = casInTenant($org, fn () => InterviewSession::where('participant_id', $participant->id)->first());

    expect($session)->not->toBeNull();
    expect($session->status)->toBe('error');
    expect($session->error_count)->toBe(1, 'the attempt must be recorded where the error is marked');
});

test('error_count accumulates across the re-offer, on the same row', function (): void {
    // The bound is only a bound if it survives the reset that re-offers the
    // competency. `ResetSessionForRetry` clears status, ref, reason and ended_at
    // and deliberately leaves this column alone — a counter cleared by its own
    // reset would grant unlimited attempts.
    Http::fake(casClientErrorFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 2);
    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    // Both calls hit the SAME competency: the first failure re-offers it rather
    // than moving on, because it still has an attempt left.
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(500);
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(500);

    $sessions = casInTenant($org, fn () => InterviewSession::where('participant_id', $participant->id)->get());

    expect($sessions)->toHaveCount(1, 'the re-offer reuses the row; the unique constraint forbids a second');
    expect($sessions->first()->error_count)->toBe(InterviewSession::MAX_ERROR_ATTEMPTS);
});

test('a third attempt is never offered — the competency is closed and the next one begins', function (): void {
    Http::fake(casClientErrorFake());
    Queue::fake();

    $org = casOrg();
    [$project, $comps] = casProject($org, 2);
    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    // Burn the first competency's two attempts.
    foreach ([1, 2] as $ignored) {
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/candidate/interview/start')->assertStatus(500);
    }

    // The third call must move ON, not offer a third attempt at the same one.
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(500);

    $sessions = casInTenant($org, fn () => InterviewSession::where('participant_id', $participant->id)
        ->pluck('error_count', 'competency_code'));

    expect($sessions)->toHaveCount(2, 'the second competency was reached');
    expect($sessions[$comps[0]->code])->toBe(InterviewSession::MAX_ERROR_ATTEMPTS);
    expect($sessions[$comps[1]->code])->toBe(1);
});

test('a 4xx with an attempt left does NOT end the interview — our bug must not burn the candidate', function (): void {
    // The ratified D4 guarantee: a ClientError is a payload bug on our side. The
    // candidate keeps their competency and their interview.
    Http::fake(casClientErrorFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 1);
    $participant = casParticipant($org, $project);

    $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')->assertStatus(500);

    expect($participant->fresh()->status)->toBe('in_corso');
    Queue::assertNotPushed(FinalizeInterview::class);
});

// ─── The stranding this change exists to end ──────────────────────────────────

test('a terminal error on the last competency reaches in_valutazione and dispatches scoring', function (): void {
    Http::fake(casClientErrorFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 1);
    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    // Two failures: the first re-offers, the second exhausts the bound.
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(500);
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(500);

    expect($participant->fresh()->status)->toBe(
        'in_valutazione',
        'the only competency has spent both attempts, so the interview is over and must be settled'
    );

    Queue::assertPushed(FinalizeInterview::class);
});

test('an upstream failure does NOT dispatch scoring and lands the participant at errore', function (): void {
    // The CAS predicate `where('status','in_corso')` is the guard: markParticipantFailed()
    // has already moved the participant to `errore`, so the CAS matches zero rows.
    // This is why the settle call site sits AFTER the classification switch and not
    // inside markSessionError() — settling first would dispatch scoring and then have
    // the participant overwritten to `errore`, leaving a scored, webhooked candidate
    // sitting at a failure status.
    Http::fake(casUpstreamFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 1);
    $participant = casParticipant($org, $project);

    $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(502);

    expect($participant->fresh()->status)->toBe('errore');
    Queue::assertNotPushed(FinalizeInterview::class);
});

test('a returning participant with everything terminal is settled on /start, before the 422', function (): void {
    // Site 3 — the idempotent self-heal. It cannot be the primary mechanism (after a
    // terminal error the client shows an error screen and may never call anything
    // again), but it is what rescues participants stranded before this change, and
    // it must not double-dispatch.
    Queue::fake();

    $org = casOrg();
    [$project, $competencies] = casProject($org, 1);
    $participant = casParticipant($org, $project);

    // Hand-build the stranded state: the one competency is terminal at `error`,
    // exactly as a pre-change participant was left.
    casInTenant($org, function () use ($org, $project, $participant, $competencies): void {
        $s = new InterviewSession;
        $s->forceFill([
            'organization_id' => $org->id,
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => 0,
            'competency_code' => $competencies[0]->code,
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'heygen',
            'status' => 'error',
            'ended_reason' => 'error',
            'error_count' => InterviewSession::MAX_ERROR_ATTEMPTS,
            'ended_at' => now(),
        ]);
        $s->save();
    });

    $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(422)
        ->assertJson(['error' => 'no_competency_remaining']);

    expect($participant->fresh()->status)->toBe('in_valutazione');
    Queue::assertPushed(FinalizeInterview::class, 1);
});

test('the settle is idempotent — a second /start does not dispatch scoring twice', function (): void {
    Queue::fake();

    $org = casOrg();
    [$project, $competencies] = casProject($org, 1);
    $participant = casParticipant($org, $project);

    casInTenant($org, function () use ($org, $project, $participant, $competencies): void {
        $s = new InterviewSession;
        $s->forceFill([
            'organization_id' => $org->id,
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => 0,
            'competency_code' => $competencies[0]->code,
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'heygen',
            'status' => 'error',
            'ended_reason' => 'error',
            'error_count' => InterviewSession::MAX_ERROR_ATTEMPTS,
            'ended_at' => now(),
        ]);
        $s->save();
    });

    $token = casBearer($participant);
    $headers = ['Authorization' => 'Bearer '.$token];

    $this->withHeaders($headers)->postJson('/api/candidate/interview/start')->assertStatus(422);
    $this->withHeaders($headers)->postJson('/api/candidate/interview/start')->assertStatus(422);

    // The CAS predicate is the single-winner guard: the second pass finds the
    // participant already at `in_valutazione` and updates zero rows.
    Queue::assertPushed(FinalizeInterview::class, 1);
});

// ─── Tenancy ──────────────────────────────────────────────────────────────────

test('the tally never counts another organization sessions', function (): void {
    Http::fake(casClientErrorFake());
    Queue::fake();

    $orgA = casOrg();
    [$projectA] = casProject($orgA, 2);
    $participantA = casParticipant($orgA, $projectA);

    $orgB = casOrg();
    [$projectB] = casProject($orgB, 1);
    $participantB = casParticipant($orgB, $projectB);

    // A burns both attempts on the first of its two competencies.
    $tokenA = casBearer($participantA);
    foreach ([1, 2] as $ignored) {
        $this->withHeaders(['Authorization' => 'Bearer '.$tokenA])
            ->postJson('/api/candidate/interview/start')->assertStatus(500);
    }

    // The auth guard caches its resolved user, and the test container survives
    // between requests — without this the next call would still be authenticated
    // as the first participant, silently testing one candidate twice. Production
    // never sees it: each request there gets its own container.
    $this->app['auth']->forgetGuards();

    // B exhausts its single competency and settles.
    $tokenB = casBearer($participantB);
    foreach ([1, 2] as $ignored) {
        $this->withHeaders(['Authorization' => 'Bearer '.$tokenB])
            ->postJson('/api/candidate/interview/start')->assertStatus(500);
    }

    expect($participantB->fresh()->status)->toBe('in_valutazione');

    expect($participantA->fresh()->status)->toBe(
        'in_corso',
        'one competency of two is terminal, so A is not finished'
    );
});
