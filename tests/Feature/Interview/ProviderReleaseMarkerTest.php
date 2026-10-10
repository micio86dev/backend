<?php

declare(strict_types=1);

/**
 * RED/GREEN — tavus-single-session-interview API-04 (hard requirement from the API-07 review).
 *
 * The server must KNOW that a provider conversation was already released, so a late boundary
 * `/start` cannot be granted a continuation into a dead conversation. The explicit marker is
 * `interview_sessions.provider_released_at`, written on every row of the organization that shares
 * the ref, only when a release was really attempted.
 */

use App\Actions\Interview\ReleaseProviderSession;
use App\Jobs\ReleaseEndedProviderSessionJob;
use App\Models\AvatarTemplate;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Provider\TavusProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake(['*tavusapi*/v2/conversations/*' => Http::response([], 200)]);
});

function prmRow(Organization $org, Project $project, string $status, ?string $ref, string $code = 'COL'): InterviewSession
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

function prmReleased(InterviewSession $row): bool
{
    return InterviewSession::withoutGlobalScopes()->whereKey($row->id)->value('provider_released_at') !== null;
}

test('the deferred job marks every row of the organization that shares the released ref', function (): void {
    $org = casOrg();
    [$project] = casProject($org, 2);
    [$other, $otherProject] = [casOrg(), null];
    [$otherProject] = casProject($other, 1);
    $ended = prmRow($org, $project, 'completed', 'conv-shared');
    $sibling = prmRow($org, $project, 'completed', 'conv-shared', 'INN');
    $unrelated = prmRow($org, $project, 'completed', 'conv-other', 'DRV');
    $foreign = prmRow($other, $otherProject, 'completed', 'conv-shared');

    ReleaseEndedProviderSessionJob::dispatchSync($ended->id, $org->id, 'tavus', 'conv-shared', null);

    expect(prmReleased($ended))->toBeTrue()
        ->and(prmReleased($sibling))->toBeTrue()
        ->and(prmReleased($unrelated))->toBeFalse()
        ->and(prmReleased($foreign))->toBeFalse();
});

test('a release skipped for a live sibling writes no marker', function (): void {
    $org = casOrg();
    [$project] = casProject($org, 2);
    $ended = prmRow($org, $project, 'completed', 'conv-shared');
    $live = prmRow($org, $project, 'in_corso', 'conv-shared', 'INN');

    ReleaseEndedProviderSessionJob::dispatchSync($ended->id, $org->id, 'tavus', 'conv-shared', null);

    expect(prmReleased($ended))->toBeFalse()->and(prmReleased($live))->toBeFalse();
});

test('a job whose row no longer holds a ref releases nothing and writes no marker', function (): void {
    $org = casOrg();
    [$project] = casProject($org, 1);
    $ended = prmRow($org, $project, 'completed', null);

    ReleaseEndedProviderSessionJob::dispatchSync($ended->id, $org->id, 'tavus', 'conv-gone', null);

    expect(prmReleased($ended))->toBeFalse();
});

test('the synchronous release of an ended row marks its ref too', function (): void {
    $org = casOrg();
    [$project] = casProject($org, 2);
    $ended = prmRow($org, $project, 'completed', 'conv-sync');
    $sibling = prmRow($org, $project, 'completed', 'conv-sync', 'INN');

    casInTenant($org, fn () => (new ReleaseProviderSession)($ended));

    expect(prmReleased($ended))->toBeTrue()->and(prmReleased($sibling))->toBeTrue();
});

/**
 * A live Tavus row (started over HTTP, gate closed) plus an ENDED sibling sharing its ref.
 *
 * @return array{0: Organization, 1: array<string, string>, 2: InterviewSession, 3: InterviewSession}
 */
function prmLiveWithEndedSibling(): array
{
    config(['interview.tavus.single_session' => false, 'interview.tavus.single_session_projects' => []]);
    $org = casOrg();
    [$project] = casProject($org, 2);
    $project->forceFill(['avatar_template_id' => AvatarTemplate::create([
        'name' => 'prm '.uniqid(), 'provider' => 'tavus', 'config' => [],
    ])->id])->save();
    $headers = ['Authorization' => 'Bearer '.casBearer(casParticipant($org, $project, 'in_attesa'))];
    Http::fake([
        '*tavusapi*/v2/conversations' => fn () => Http::response([
            'conversation_id' => 'conv-'.uniqid('', true),
            'conversation_url' => 'https://tavus.io/conv',
        ], 200),
        '*tavusapi*/v2/conversations/*' => Http::response([], 200),
    ]);
    test()->withHeaders($headers)->postJson('/api/candidate/interview/start')->assertStatus(201);
    $live = casInTenant($org, fn () => InterviewSession::where('status', 'in_corso')->firstOrFail());
    $ended = casInTenant($org, fn () => InterviewSession::factory()->create([
        'participant_id' => $live->participant_id,
        'project_id' => $project->id,
        'framework_version_id' => $project->framework_version_id,
        'organization_id' => $org->id,
        'provider' => 'tavus',
        'status' => 'completed',
        'provider_session_ref' => $live->provider_session_ref,
        'competency_code' => 'ZZZ',
    ]));

    return [$org, $headers, $live, $ended];
}

test('/suspend marks the conversation it released on the rows sharing it', function (): void {
    [, $headers, $live, $ended] = prmLiveWithEndedSibling();

    test()->withHeaders($headers)->postJson('/api/candidate/interview/suspend', ['session_id' => $live->id])->assertOk();

    expect(prmReleased($ended))->toBeTrue();
});

test('a resume that tears down its old conversation marks it on the rows sharing it', function (): void {
    [, $headers, $live, $ended] = prmLiveWithEndedSibling();

    test()->withHeaders($headers)->postJson('/api/candidate/interview/start')->assertStatus(201);

    expect($live->fresh()->provider_session_ref)->not->toBe($ended->provider_session_ref)
        ->and(prmReleased($ended))->toBeTrue();
});

/**
 * Make `forRefs` itself throw: the provider teardown throws AND the failure log it swallows into
 * throws too (a broken log sink), so the exception escapes `forRefs`.
 */
function prmBreakForRefs(): void
{
    $provider = Mockery::mock(TavusProvider::class);
    $provider->shouldReceive('teardown')->andThrow(new RuntimeException('teardown blew up'));
    app()->instance(TavusProvider::class, $provider);
    Log::partialMock()->shouldReceive('warning')->andReturnUsing(function (string $message): void {
        if ($message === 'interview.provider_release.failed') {
            throw new RuntimeException('log sink down');
        }
    });
}

test('the synchronous release writes the marker even when the teardown throws', function (): void {
    $org = casOrg();
    [$project] = casProject($org, 1);
    $ended = prmRow($org, $project, 'completed', 'conv-throw');

    prmBreakForRefs();

    expect(fn () => casInTenant($org, fn () => (new ReleaseProviderSession)($ended)))->toThrow(RuntimeException::class);

    expect(prmReleased($ended))->toBeTrue();
});

test('the deferred job writes the marker even when the teardown throws, and swallows the failure', function (): void {
    $org = casOrg();
    [$project] = casProject($org, 1);
    $ended = prmRow($org, $project, 'completed', 'conv-throw');
    prmBreakForRefs();

    $job = new ReleaseEndedProviderSessionJob($ended->id, $org->id, 'tavus', 'conv-throw', null);
    app()->call([$job, 'handle']);

    expect(prmReleased($ended))->toBeTrue();
});

test('a release with no ref to release writes no marker', function (): void {
    $org = casOrg();
    [$project] = casProject($org, 1);
    $ended = prmRow($org, $project, 'completed', null);

    casInTenant($org, fn () => (new ReleaseProviderSession)($ended));

    expect(prmReleased($ended))->toBeFalse();
});

test('a /suspend whose teardown the provider did not confirm still marks the conversation as attempted', function (): void {
    [, $headers, $live, $ended] = prmLiveWithEndedSibling();
    Http::swap(new HttpFactory);
    Http::fake(['*' => Http::response([], 500)]);

    test()->withHeaders($headers)->postJson('/api/candidate/interview/suspend', ['session_id' => $live->id])->assertOk();

    expect(prmReleased($ended))->toBeTrue();
});
