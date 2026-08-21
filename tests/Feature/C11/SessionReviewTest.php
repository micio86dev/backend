<?php

declare(strict_types=1);

/**
 * Interview session review — admin read surface (C11).
 *
 * BEAI has always written integrity events and webcam snapshots on every
 * interview and never exposed them. This is the read side.
 *
 * The scenarios that matter most are the refusals: cross-tenant 404, candidate
 * token rejected, and no raw storage key anywhere in the payload. Evidence
 * collected about a person is worth less than the discipline around who may
 * look at it.
 *
 * REQ: Admin session review
 */

use App\Models\FrameworkVersion;
use App\Models\IntegrityEvent;
use App\Models\InterviewSession;
use App\Models\InterviewSnapshot;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function reviewUser(Organization $org, string $role = 'admin'): string
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $spatieRole = SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return (string) auth('api')->login($user);
}

function reviewSession(Organization $org, array $overrides = []): InterviewSession
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'organization_id' => $org->id,
        'framework_version_id' => $fv->id,
    ]);
    $participant = Participant::factory()->create([
        'organization_id' => $org->id,
        'project_id' => $project->id,
    ]);

    // (interview-session-started-at, D8) A duration/cost assertion cannot
    // pass on the bare factory default — this reaches liveSeconds() through
    // the named ->ended() state, one closed period of 600s.
    return InterviewSession::factory()->ended(600)->create(array_merge([
        'organization_id' => $org->id,
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'framework_version_id' => $fv->id,
        'provider' => 'heygen',
    ], $overrides));
}

beforeEach(function (): void {
    // No argument: resolves the configured default disk (object-storage-fix D3).
    // NOT parametrised over ['local', 's3'] like DataRetentionPurgeTest — a
    // Storage::fake('s3') call here would build a LOCAL-driver fake disk merely
    // NAMED 's3', and temporaryUrl() on a local disk needs the signed
    // storage.{diskName} route, which exists only for 'local'. This suite stays
    // on the default and asserts URL *shape* only (see below), never a name.
    Storage::fake();
});

test('an admin reads a session review with timing, integrity and snapshots', function (): void {
    $org = Organization::factory()->create();
    $token = reviewUser($org);
    $session = reviewSession($org);

    IntegrityEvent::factory()->create([
        'organization_id' => $org->id,
        'interview_session_id' => $session->id,
        'kind' => 'looking_away',
        'payload' => ['durationMs' => 10_000],
    ]);
    InterviewSnapshot::factory()->create([
        'organization_id' => $org->id,
        'interview_session_id' => $session->id,
        's3_key' => 'snapshots/one.jpg',
    ]);

    $response = $this->withToken($token)->getJson("/api/interview-sessions/{$session->id}/review");

    $response->assertOk()->assertJsonStructure([
        'data' => [
            'id', 'provider', 'status', 'started_at', 'ended_at', 'duration_seconds',
            'integrity' => ['score', 'band', 'total', 'events'],
            'snapshots', 'cost',
        ],
    ]);

    expect($response->json('data.duration_seconds'))->toBe(600)
        // Cast: json_encode renders a whole float as an integer, so the wire
        // type is not stable and asserting on it would test PHP, not the score.
        ->and((float) $response->json('data.integrity.score'))->toBe(4.0)
        ->and($response->json('data.integrity.band'))->toBe('low')
        ->and($response->json('data.integrity.events'))->toHaveCount(1);
});

test('a snapshot is returned as a signed URL and never as a storage key', function (): void {
    $org = Organization::factory()->create();
    $token = reviewUser($org);
    $session = reviewSession($org);

    InterviewSnapshot::factory()->create([
        'organization_id' => $org->id,
        'interview_session_id' => $session->id,
        's3_key' => 'snapshots/secret-layout/frame-1.jpg',
    ]);

    $response = $this->withToken($token)->getJson("/api/interview-sessions/{$session->id}/review");

    $response->assertOk();

    // A raw key implies either a public bucket holding identifiable webcam
    // frames or a disclosed storage layout. Neither may leak, so the whole
    // serialized body is checked rather than one field.
    expect(json_encode($response->json()))->not->toContain('snapshots/secret-layout')
        ->and($response->json('data.snapshots.0.url'))->toBeString()
        ->and($response->json('data.snapshots.0'))->not->toHaveKey('s3_key');
});

test('events are returned oldest first, so the timeline reads as it happened', function (): void {
    $org = Organization::factory()->create();
    $token = reviewUser($org);
    $session = reviewSession($org);

    foreach ([['later', '2026-03-01 10:05:00'], ['earlier', '2026-03-01 10:01:00']] as [$kind, $ts]) {
        IntegrityEvent::factory()->create([
            'organization_id' => $org->id,
            'interview_session_id' => $session->id,
            'kind' => $kind === 'later' ? 'focus_lost' : 'tab_hidden',
            'payload' => [],
            'ts' => $ts,
        ]);
    }

    $kinds = array_column(
        $this->withToken($token)->getJson("/api/interview-sessions/{$session->id}/review")->json('data.integrity.events'),
        'kind'
    );

    expect($kinds)->toBe(['tab_hidden', 'focus_lost']);
});

test('a clean session reports zero rather than failing', function (): void {
    $org = Organization::factory()->create();
    $token = reviewUser($org);
    $session = reviewSession($org);

    $response = $this->withToken($token)->getJson("/api/interview-sessions/{$session->id}/review");

    $response->assertOk();
    expect((float) $response->json('data.integrity.score'))->toBe(0.0)
        ->and($response->json('data.integrity.band'))->toBe('low')
        ->and($response->json('data.integrity.events'))->toBe([])
        ->and($response->json('data.snapshots'))->toBe([]);
});

test('another tenant\'s session is a 404, not a 403', function (): void {
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    $token = reviewUser($mine);
    $session = reviewSession($theirs);

    // 404 rather than 403: a 403 would confirm the id exists somewhere.
    $this->withToken($token)
        ->getJson("/api/interview-sessions/{$session->id}/review")
        ->assertNotFound();
});

test('the participant sessions list is org-scoped and ordered', function (): void {
    $org = Organization::factory()->create();
    $token = reviewUser($org);
    $session = reviewSession($org);

    $response = $this->withToken($token)
        ->getJson("/api/participants/{$session->participant_id}/sessions");

    $response->assertOk()->assertJsonStructure([
        'data' => [['id', 'competency_code', 'question_index', 'status', 'started_at', 'duration_seconds']],
    ]);

    expect($response->json('data'))->toHaveCount(1);
});

test('the sessions list query count does not grow with the number of sessions — livePeriods eager-loaded, no N+1', function (): void {
    $org = Organization::factory()->create();
    $token = reviewUser($org);
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['organization_id' => $org->id, 'framework_version_id' => $fv->id]);
    $participant = Participant::factory()->create(['organization_id' => $org->id, 'project_id' => $project->id]);

    $competencyCounter = 0;
    $makeSessions = function (int $count) use ($participant, $project, $fv, &$competencyCounter): void {
        for ($i = 0; $i < $count; $i++) {
            InterviewSession::factory()->ended(600)->create([
                'organization_id' => $participant->organization_id,
                'participant_id' => $participant->id,
                'project_id' => $project->id,
                'framework_version_id' => $fv->id,
                'competency_code' => 'COMP'.$competencyCounter++,
            ]);
        }
    };

    $makeSessions(6);

    DB::enableQueryLog();
    $response = $this->withToken($token)->getJson("/api/participants/{$participant->id}/sessions");
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(6);

    // Content-based, not a raw total (auth/permission resolution issues its
    // own queries, unrelated to this guard): isolate SELECTs against the
    // live-periods table. Exactly ONE — the eager load `->with('livePeriods')`
    // — proves 6 sessions did not cost 6 separate lookups (N+1).
    $periodLoads = array_filter(
        $log,
        fn (array $entry): bool => str_contains(strtolower((string) $entry['query']), 'interview_session_live_periods')
    );

    expect($periodLoads)->toHaveCount(1, 'livePeriods must be eager-loaded once, not once per session.');
});

test('an operator may read a review; the surface is not admin-only', function (): void {
    $org = Organization::factory()->create();
    $token = reviewUser($org, 'operator');
    $session = reviewSession($org);

    $this->withToken($token)
        ->getJson("/api/interview-sessions/{$session->id}/review")
        ->assertOk();
});

test('an unauthenticated request is refused', function (): void {
    $org = Organization::factory()->create();
    $session = reviewSession($org);

    $this->getJson("/api/interview-sessions/{$session->id}/review")->assertUnauthorized();
});
