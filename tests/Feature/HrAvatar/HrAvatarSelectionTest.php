<?php

declare(strict_types=1);

/**
 * Provider selection for `hr_avatar` (hr-avatar-adapter A2a).
 *
 * `hr_avatar` is reached ONLY by a pinned template, never by `provider_override`,
 * the environment default or test mode. A stored `hr_avatar` override or env
 * default is ignored as if absent. Tests drive the real POST /start.
 */

use App\Enums\ApiKeyMode;
use App\Models\Project;
use App\Services\Provider\HeygenProvider;
use App\Services\Provider\TavusProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['hr_avatar' => [
        'base_url' => 'https://hra.example.test',
        'secret_key' => 'hra_sk_live_SECRETVALUE',
        'publishable_key' => 'hra_pk_live_PUBLISHABLE',
    ]]);
    Queue::fake();
});

function hraFakeHrAvatar(): void
{
    Http::fake([
        'hra.example.test/v1/conversations' => Http::response(['id' => 'conv_01ABC', 'client_secret' => 'CS_VALUE'], 201),
        'hra.example.test/v1/conversations/*/end' => Http::response([], 200),
    ]);
    Http::preventStrayRequests();
}

function hraStart(string $token): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/candidate/interview/start');
}

/** The provider of the one session the participant now has. */
function hraSessionProvider(int $participantId): ?string
{
    return DB::table('interview_sessions')->where('participant_id', $participantId)->value('provider');
}

/**
 * Make the project's pin resolve to nothing, as it does once the template has been deleted
 * (the model refuses to delete a pinned template, so the row is stamped directly).
 * `provider_override` and the environment default then take over.
 */
function hraUnpin(Project $project): void
{
    DB::table('avatar_templates')->where('id', $project->avatar_template_id)->update(['deleted_at' => now()]);
}

test('a pinned hr_avatar template selects hr_avatar and creates exactly one HR-Avatar conversation', function (): void {
    hraFakeHrAvatar();
    [, , $participant, $token] = hraStartScenario('hr_avatar', ['hr_avatar_config_id' => 'avc_01HXYZ']);

    hraStart($token)->assertStatus(201)->assertJsonPath('provider', 'hr_avatar');

    expect(hraSessionProvider($participant->id))->toBe('hr_avatar');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hra.example.test/v1/conversations');
});

test('a test-mode participant on a pinned hr_avatar template still runs the mock provider', function (): void {
    Http::fake();
    Http::preventStrayRequests();
    [, , $participant, $token] = hraStartScenario('hr_avatar', ['hr_avatar_config_id' => 'avc_01HXYZ']);
    $participant->forceFill(['mode' => ApiKeyMode::Test])->save();

    hraStart($token)->assertStatus(201)->assertJsonPath('provider', 'mock');

    expect(hraSessionProvider($participant->id))->toBe('mock');
    Http::assertNothingSent();
});

test('a stored hr_avatar provider_override is ignored as if absent', function (?string $env, string $expected): void {
    config([
        'interview.heygen.avatar_id' => 'avatar-default',
        'interview.heygen.voice_id' => 'voice-default',
        'interview.tavus.replica_id' => 'replica-default',
        'interview.provider' => $env ?? 'heygen',
    ]);
    Http::fake([
        '*tavusapi*' => Http::response(['conversation_id' => 'c', 'conversation_url' => 'https://tavus.example.test/c'], 200),
        '*liveavatar*/contexts*' => Http::response(['data' => ['id' => 'ctx']], 200),
        '*liveavatar*/sessions/token*' => Http::response(['data' => ['session_id' => 's', 'session_token' => 't']], 200),
        '*liveavatar*' => Http::response([], 200),
    ]);
    Http::preventStrayRequests();
    [, $project, $participant, $token] = hraStartScenario('heygen');
    hraUnpin($project);
    DB::table('projects')->where('id', $project->id)->update(['provider_override' => 'hr_avatar']);

    hraStart($token)->assertStatus(201);

    expect(hraSessionProvider($participant->id))->toBe($expected);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'hra.example.test'));
})->with([
    'override ignored, env default heygen' => [null, 'heygen'],
    'override ignored, env default tavus' => ['tavus', 'tavus'],
    'override ignored, env default hr_avatar falls to heygen' => ['hr_avatar', 'heygen'],
]);

test('the environment default hr_avatar with no pinned template and no override selects heygen', function (): void {
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['data' => ['id' => 'ctx']], 200),
        '*liveavatar*/sessions/token*' => Http::response(['data' => ['session_id' => 's', 'session_token' => 't']], 200),
        '*liveavatar*' => Http::response([], 200),
    ]);
    Http::preventStrayRequests();
    config(['interview.provider' => 'hr_avatar', 'interview.heygen.avatar_id' => 'a', 'interview.heygen.voice_id' => 'v']);
    [, $project, $participant, $token] = hraStartScenario('heygen');
    hraUnpin($project);

    hraStart($token)->assertStatus(201);

    expect(hraSessionProvider($participant->id))->toBe('heygen');
});

test('a pinned tavus template wins over a heygen override', function (): void {
    Http::fake(['*tavusapi*' => Http::response(['conversation_id' => 'c', 'conversation_url' => 'https://tavus.example.test/c'], 200)]);
    Http::preventStrayRequests();
    [, $project, $participant, $token] = hraStartScenario('tavus');
    DB::table('projects')->where('id', $project->id)->update(['provider_override' => 'heygen']);

    hraStart($token)->assertStatus(201)->assertJsonPath('provider', 'tavus');

    expect(hraSessionProvider($participant->id))->toBe('tavus');
});

test('an hr_avatar session never constructs HeyGen or Tavus and calls no other host', function (): void {
    hraFakeHrAvatar();
    app()->bind(HeygenProvider::class, fn () => throw new RuntimeException('HeygenProvider must not be constructed'));
    app()->bind(TavusProvider::class, fn () => throw new RuntimeException('TavusProvider must not be constructed'));
    [, , , $token] = hraStartScenario('hr_avatar', ['hr_avatar_config_id' => 'avc_01HXYZ']);

    hraStart($token)->assertStatus(201);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => ! str_contains($request->url(), 'hra.example.test'));
});
