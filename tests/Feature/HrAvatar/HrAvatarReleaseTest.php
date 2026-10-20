<?php

declare(strict_types=1);

/**
 * Releasing an `hr_avatar` conversation (hr-avatar-adapter A2b).
 *
 * `ReleaseProviderSession::forRefs()` used to send every unknown provider to the HeyGen default, so
 * the `/end` release, the deferred release job and the abandoned-interview sweep would have handed a
 * `conv_...` ref to `HeygenProvider::teardown()`. These tests pin the explicit arm.
 */

use App\Actions\Interview\ReleaseProviderSession;
use App\Jobs\ReleaseEndedProviderSessionJob;
use App\Services\Provider\HeygenProvider;
use App\Services\Provider\TavusProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['hr_avatar' => [
        'base_url' => 'https://hra.example.test',
        'secret_key' => 'hra_sk_live_SECRETVALUE',
        'publishable_key' => 'hra_pk_live_PUBLISHABLE',
    ]]);
    Http::fake([
        'hra.example.test/v1/conversations' => Http::response(['id' => 'conv_REL1', 'client_secret' => 'CS_REL1'], 201),
        'hra.example.test/v1/conversations/*/end' => Http::response(['status' => 'ended'], 200),
    ]);
    Http::preventStrayRequests();
    app()->bind(HeygenProvider::class, fn () => throw new RuntimeException('HeygenProvider must not be constructed'));
    app()->bind(TavusProvider::class, fn () => throw new RuntimeException('TavusProvider must not be constructed'));
});

/** A live `hr_avatar` session created through the real /start. */
function hraLiveSession(): array
{
    // No Queue::fake(): a faked queue also captures `dispatchSync`, and the release job must really run.
    [$org, , $participant, $token] = hraStartScenario('hr_avatar', ['hr_avatar_config_id' => 'avc_01HXYZ'], 1);
    test()->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/candidate/interview/start')->assertStatus(201);
    $session = DB::table('interview_sessions')->where('participant_id', $participant->id)->first();

    return [$org, $session];
}

function hraEndRequests(): int
{
    return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/conversations/conv_REL1/end'))->count();
}

test('forRefs ends the HR-Avatar conversation and never constructs HeyGen', function (): void {
    app(ReleaseProviderSession::class)->forRefs('hr_avatar', 'conv_REL1', null);

    expect(hraEndRequests())->toBe(1);
});

test('the deferred release job ends the HR-Avatar conversation', function (): void {
    [$org, $session] = hraLiveSession();
    DB::table('interview_sessions')->where('id', $session->id)->update(['status' => 'completed']);

    ReleaseEndedProviderSessionJob::dispatchSync($session->id, $org->id, 'hr_avatar', 'conv_REL1', null);

    expect(hraEndRequests())->toBe(1);
});

test('the abandoned-interview sweep ends a stale hr_avatar conversation', function (): void {
    [, $session] = hraLiveSession();
    DB::table('interview_sessions')->where('id', $session->id)->update(['started_at' => now()->subDay()]);

    expect(Artisan::call('beai:reap-stale-interviews'))->toBe(0);

    expect(hraEndRequests())->toBe(1);
    expect(DB::table('interview_sessions')->where('id', $session->id)->value('status'))->toBe('timeout');
});
