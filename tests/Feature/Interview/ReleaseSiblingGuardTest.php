<?php

declare(strict_types=1);

/**
 * RED/GREEN — tavus-single-session-interview API-07.2 (design N12).
 *
 * The deferred job, the reaper release and the resume teardown must not end a provider
 * conversation another `in_corso` row of the SAME organization still shares.
 */

use App\Jobs\ReleaseEndedProviderSessionJob;
use App\Models\AvatarTemplate;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake([
        '*tavusapi*/v2/conversations' => fn () => Http::response([
            'conversation_id' => 'conv-'.uniqid('', true),
            'conversation_url' => 'https://tavus.io/conv',
        ], 200),
        '*tavusapi*/v2/conversations/*' => Http::response([], 200),
    ]);
});

/** @return array{0: Organization, 1: Project} */
function rsgTenant(): array
{
    $org = casOrg();
    [$project] = casProject($org, 2);

    return [$org, $project];
}

function rsgRow(Organization $org, Project $project, string $status, ?string $ref, string $code = 'COL'): InterviewSession
{
    return casInTenant($org, fn () => InterviewSession::factory()->create([
        'participant_id' => casParticipant($org, $project)->id,
        'project_id' => $project->id,
        'framework_version_id' => $project->framework_version_id,
        'organization_id' => $org->id,
        'provider' => 'tavus',
        'status' => $status,
        'provider_session_ref' => $ref,
        'competency_code' => $code,
    ]));
}

/** @return list<string> */
function rsgTeardowns(): array
{
    return Http::recorded()
        ->map(fn (array $pair): string => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH))
        ->filter(fn (string $call): bool => str_ends_with($call, '/end'))
        ->values()->all();
}

function rsgRunJob(InterviewSession $ended): void
{
    // dispatchSync runs the job inline; a faked queue would swallow it.
    ReleaseEndedProviderSessionJob::dispatchSync($ended->id, $ended->organization_id, 'tavus', $ended->provider_session_ref, null);
}

test('the deferred job skips the release while another in_corso row shares the ref', function (): void {
    [$org, $project] = rsgTenant();
    $ended = rsgRow($org, $project, 'completed', 'conv-shared');
    rsgRow($org, $project, 'in_corso', 'conv-shared', 'INN');

    rsgRunJob($ended);

    expect(rsgTeardowns())->toBe([]);
});

test('the deferred job releases when no in_corso row shares the ref', function (): void {
    [$org, $project] = rsgTenant();
    $ended = rsgRow($org, $project, 'completed', 'conv-shared');
    rsgRow($org, $project, 'completed', 'conv-shared', 'INN');
    rsgRow($org, $project, 'in_corso', 'conv-other', 'DRV');

    rsgRunJob($ended);

    expect(rsgTeardowns())->toBe(['POST /v2/conversations/conv-shared/end']);
});

test('an already-ended conversation is benign for the deferred job', function (): void {
    [$org, $project] = rsgTenant();
    $ended = rsgRow($org, $project, 'completed', 'conv-gone');
    Http::fake(['*tavusapi*/v2/conversations/*' => Http::response([], 404)]);

    rsgRunJob($ended);

    expect(rsgTeardowns())->toBe(['POST /v2/conversations/conv-gone/end']);
});

test('a row of ANOTHER organization sharing the ref never counts as a sibling', function (): void {
    [$org, $project] = rsgTenant();
    [$other, $otherProject] = rsgTenant();
    $ended = rsgRow($org, $project, 'completed', 'conv-shared');
    rsgRow($other, $otherProject, 'in_corso', 'conv-shared');

    rsgRunJob($ended);

    expect(rsgTeardowns())->toBe(['POST /v2/conversations/conv-shared/end']);
});

test('the reaper skips the release while a live sibling shares the ref, and releases when none does', function (): void {
    Queue::fake();
    [$org, $project] = rsgTenant();
    $stale = rsgRow($org, $project, 'in_corso', 'conv-shared');
    $stale->forceFill(['started_at' => now()->subHours(3)])->save();
    $sibling = rsgRow($org, $project, 'in_corso', 'conv-shared', 'INN');
    $sibling->forceFill(['started_at' => now()])->save();
    config(['interview.stale_after_minutes' => 30]);

    app(TenantResolver::class)->setOrgId(null);
    Artisan::call('beai:reap-stale-interviews');

    expect($stale->fresh()->status)->toBe('timeout')
        ->and($sibling->fresh()->status)->toBe('in_corso')
        ->and(rsgTeardowns())->toBe([]);

    // The sibling goes quiet too: nothing live shares the ref any more, so this sweep releases it once.
    $sibling->forceFill(['started_at' => now()->subHours(3)])->save();
    app(TenantResolver::class)->setOrgId(null);
    Artisan::call('beai:reap-stale-interviews');

    expect(rsgTeardowns())->toBe(['POST /v2/conversations/conv-shared/end']);
});

test('a resume with a live sibling skips the teardown, still issues a fresh ref and closes the old period', function (): void {
    Queue::fake();
    config(['interview.tavus.single_session' => false]);
    $org = casOrg();
    [$project] = casProject($org, 2);
    $project->forceFill(['avatar_template_id' => AvatarTemplate::create([
        'name' => 'rsg '.uniqid(), 'provider' => 'tavus', 'config' => [],
    ])->id])->save();
    $participant = casParticipant($org, $project, 'in_attesa');
    $headers = ['Authorization' => 'Bearer '.casBearer($participant)];

    test()->withHeaders($headers)->postJson('/api/candidate/interview/start')->assertStatus(201);
    $row = casInTenant($org, fn () => InterviewSession::where('participant_id', $participant->id)->firstOrFail());
    $oldRef = $row->provider_session_ref;
    rsgRow($org, $project, 'in_corso', $oldRef, 'INN');

    test()->withHeaders($headers)->postJson('/api/candidate/interview/start')->assertSuccessful();

    $row = $row->fresh();
    expect(rsgTeardowns())->toBe([])
        ->and($row->provider_session_ref)->not->toBe($oldRef)
        ->and($row->livePeriods()->where('provider_session_ref', $oldRef)->whereNull('ended_at')->count())->toBe(0)
        ->and($row->livePeriods()->where('provider_session_ref', $row->provider_session_ref)->whereNull('ended_at')->count())->toBe(1);
});

test('a resume without a sibling tears the old conversation down as before', function (): void {
    Queue::fake();
    $org = casOrg();
    [$project] = casProject($org, 2);
    $project->forceFill(['avatar_template_id' => AvatarTemplate::create([
        'name' => 'rsg '.uniqid(), 'provider' => 'tavus', 'config' => [],
    ])->id])->save();
    $participant = casParticipant($org, $project, 'in_attesa');
    $headers = ['Authorization' => 'Bearer '.casBearer($participant)];

    test()->withHeaders($headers)->postJson('/api/candidate/interview/start')->assertStatus(201);
    $oldRef = casInTenant($org, fn () => InterviewSession::where('participant_id', $participant->id)->firstOrFail())->provider_session_ref;

    test()->withHeaders($headers)->postJson('/api/candidate/interview/start')->assertSuccessful();

    expect(rsgTeardowns())->toBe(['POST /v2/conversations/'.$oldRef.'/end']);
});
