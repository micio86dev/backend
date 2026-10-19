<?php

declare(strict_types=1);

/**
 * A template pinned to `hr_avatar` fails CLOSED (hr-avatar-adapter A2a).
 *
 * Through the existing pre-flight: a 422 `interview_configuration_invalid` with a
 * machine reason, raised before any session row exists, so an operator
 * misconfiguration never moves the candidate to `errore`, never falls back to
 * another provider, and never sends a request.
 */

use App\Enums\ApiKeyMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Http::fake();
    Http::preventStrayRequests();
});

function hraAvailable(): void
{
    config(['hr_avatar' => [
        'base_url' => 'https://hra.example.test',
        'secret_key' => 'hra_sk_live_SECRETVALUE',
        'publishable_key' => 'hra_pk_live_PUBLISHABLE',
    ]]);
}

function hraStartRequest(string $token): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/candidate/interview/start');
}

test('an unavailable integration refuses the start before any session exists', function (): void {
    [, , $participant, $token] = hraStartScenario('hr_avatar', ['hr_avatar_config_id' => 'avc_01HXYZ']);

    hraStartRequest($token)
        ->assertStatus(422)
        ->assertJson(['error' => 'interview_configuration_invalid', 'reasons' => [['key' => 'credentials', 'code' => 'provider_key_missing']]]);

    expect(DB::table('interview_sessions')->where('participant_id', $participant->id)->count())->toBe(0);
    expect($participant->fresh()->status)->toBe('in_attesa');
    Http::assertNothingSent();
});

test('an unavailable integration does not fall back to another provider', function (): void {
    config(['interview.provider' => 'heygen']);
    [, $project, , $token] = hraStartScenario('hr_avatar', ['hr_avatar_config_id' => 'avc_01HXYZ']);
    DB::table('projects')->where('id', $project->id)->update(['provider_override' => 'tavus']);

    hraStartRequest($token)->assertStatus(422)->assertJsonPath('error', 'interview_configuration_invalid');

    Http::assertNothingSent();
});

test('a pinned hr_avatar template without a config id refuses the start', function (): void {
    hraAvailable();
    [, , $participant, $token] = hraStartScenario('hr_avatar', ['hr_avatar_config_id' => null]);

    hraStartRequest($token)
        ->assertStatus(422)
        ->assertJson(['error' => 'interview_configuration_invalid', 'reasons' => [['key' => 'hr_avatar_config_id', 'code' => 'avatar_missing']]]);

    expect(DB::table('interview_sessions')->where('participant_id', $participant->id)->count())->toBe(0);
    expect($participant->fresh()->status)->toBe('in_attesa');
    Http::assertNothingSent();
});

test('a test-mode participant is unaffected by an unavailable pinned template', function (): void {
    [, , $participant, $token] = hraStartScenario('hr_avatar', ['hr_avatar_config_id' => 'avc_01HXYZ']);
    $participant->forceFill(['mode' => ApiKeyMode::Test])->save();

    hraStartRequest($token)->assertStatus(201)->assertJsonPath('provider', 'mock');

    Http::assertNothingSent();
});
