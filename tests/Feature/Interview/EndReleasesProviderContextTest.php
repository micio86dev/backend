<?php

declare(strict_types=1);

use App\Jobs\ReleaseEndedProviderSessionJob;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Start the first competency of a `$competencies`-competency HeyGen project and give its row
 * known provider refs, so what a release sends is assertable.
 *
 * @return array{0: Organization, 1: Participant, 2: array<string, string>, 3: InterviewSession}
 */
function endReleaseScenario(int $competencies, int $stopStatus = 200, ?int $pauseEvery = null): array
{
    $org = casOrg();
    [$project] = casProject($org, $competencies);
    if ($pauseEvery !== null) {
        casInTenant($org, fn () => Project::whereKey($project->id)->update(['pause_every_n_competencies' => $pauseEvery]));
    }
    $participant = casParticipant($org, $project);
    $headers = ['Authorization' => 'Bearer '.casBearer($participant)];

    // One fake for the whole scenario: stubs from a second Http::fake() would sit BEHIND these.
    Http::fake(['*liveavatar*/sessions/stop' => Http::response([], $stopStatus)] + heygenOkFake());
    test()->withHeaders($headers)->postJson('/api/candidate/interview/start')->assertStatus(201);
    $session = casInTenant($org, fn () => InterviewSession::where('participant_id', $participant->id)->firstOrFail());
    $session->forceFill(['provider_session_ref' => 'hg-ref', 'provider_context_ref' => 'hg-ctx'])->save();

    return [$org, $participant, $headers, $session];
}

/** @return list<string> the release-shaped provider calls recorded so far, in order. */
function recordedReleaseCalls(): array
{
    return Http::recorded()
        ->map(fn (array $pair): string => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH))
        ->filter(fn (string $call): bool => str_ends_with($call, '/sessions/stop') || str_starts_with($call, 'DELETE '))
        ->values()->all();
}

test('/end stops the HeyGen session and deletes its context at once on the last competency; a provider failure never fails /end', function (int $stopStatus): void {
    Queue::fake();
    [, , $headers, $session] = endReleaseScenario(1, $stopStatus);

    test()->withHeaders($headers)
        ->postJson('/api/candidate/interview/end', ['session_id' => $session->id, 'ended_reason' => 'completed'])
        ->assertOk()
        ->assertJsonPath('next_action', 'done');

    expect(recordedReleaseCalls())->toBe($stopStatus === 200 ? ['POST /v1/sessions/stop', 'DELETE /v1/contexts/hg-ctx'] : ['POST /v1/sessions/stop']);
    Queue::assertNotPushed(ReleaseEndedProviderSessionJob::class);
})->with([200, 500]);

test('/end keeps the outgoing HeyGen session alive when the competency handed over to the next, and defers its release', function (): void {
    Queue::fake();
    [$org, , $headers, $session] = endReleaseScenario(2);

    test()->withHeaders($headers)
        ->postJson('/api/candidate/interview/end', ['session_id' => $session->id, 'ended_reason' => 'completed'])
        ->assertOk()
        ->assertJsonPath('next_action', 'continue');

    expect(recordedReleaseCalls())->toBe([]);
    Queue::assertPushed(ReleaseEndedProviderSessionJob::class, 1);
    Queue::assertPushed(ReleaseEndedProviderSessionJob::class, fn (ReleaseEndedProviderSessionJob $job): bool => $job->delay === config('interview.provider_release_delay_seconds')
        && $job->sessionId === $session->id
        && $job->organizationId === $org->id
        && $job->provider === 'heygen'
        && $job->providerSessionRef === 'hg-ref'
        && $job->providerContextRef === 'hg-ctx');
});

test('the deferred release delay is longer than the frontend handover bound plus its connecting ceiling', function (): void {
    // useInterviewSession.ts: HANDOVER_BOUND_MS (10 s) + CONNECTING_CEILING_MS (20 s).
    expect(config('interview.provider_release_delay_seconds'))->toBe(45)->toBeGreaterThan(10 + 20);
});

test('the deferred job releases exactly the refs it was dispatched with: stop, then context delete', function (): void {
    [$org, , , $session] = endReleaseScenario(2);

    ReleaseEndedProviderSessionJob::dispatchSync($session->id, $org->id, 'heygen', 'hg-ref', 'hg-ctx');

    expect(recordedReleaseCalls())->toBe(['POST /v1/sessions/stop', 'DELETE /v1/contexts/hg-ctx']);
});

test('the deferred job never stops a newer session the row moved on to', function (): void {
    [$org, , , $session] = endReleaseScenario(2);
    // A resume issued a fresh session after the competency ended.
    $session->forceFill(['provider_session_ref' => 'hg-newer-ref', 'provider_context_ref' => 'hg-newer-ctx'])->save();

    ReleaseEndedProviderSessionJob::dispatchSync($session->id, $org->id, 'heygen', 'hg-ref', 'hg-ctx');

    $stops = Http::recorded()->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/sessions/stop'));
    expect($stops)->toHaveCount(1)
        ->and($stops->first()[0]->data())->toMatchArray(['session_id' => 'hg-ref'])
        ->and(recordedReleaseCalls())->toBe(['POST /v1/sessions/stop', 'DELETE /v1/contexts/hg-ctx']);
});

test('the deferred job does nothing when its refs are empty', function (): void {
    [$org, , , $session] = endReleaseScenario(2);
    $before = count(Http::recorded());

    ReleaseEndedProviderSessionJob::dispatchSync($session->id, $org->id, 'heygen', null, null);

    expect(count(Http::recorded()))->toBe($before);
});

test('the deferred job does nothing once the row no longer holds any ref', function (): void {
    [$org, , , $session] = endReleaseScenario(2);
    $session->forceFill(['provider_session_ref' => null, 'provider_context_ref' => null])->save();
    $before = count(Http::recorded());

    ReleaseEndedProviderSessionJob::dispatchSync($session->id, $org->id, 'heygen', 'hg-ref', 'hg-ctx');

    expect(count(Http::recorded()))->toBe($before);
});

test('a provider failure never fails the deferred job', function (): void {
    [$org, , , $session] = endReleaseScenario(2, 500);

    ReleaseEndedProviderSessionJob::dispatchSync($session->id, $org->id, 'heygen', 'hg-ref', 'hg-ctx');

    expect(recordedReleaseCalls())->toBe(['POST /v1/sessions/stop']);
});

test('/end releases at once when the competency did not complete', function (string $endedReason): void {
    Queue::fake();
    [, , $headers, $session] = endReleaseScenario(2);

    test()->withHeaders($headers)
        ->postJson('/api/candidate/interview/end', ['session_id' => $session->id, 'ended_reason' => $endedReason])
        ->assertOk()
        ->assertJsonPath('next_action', 'continue');

    expect(recordedReleaseCalls())->toBe(['POST /v1/sessions/stop', 'DELETE /v1/contexts/hg-ctx']);
    Queue::assertNotPushed(ReleaseEndedProviderSessionJob::class);
})->with(['timeout', 'skipped']);

test('/end releases at once when the interview pauses after the competency', function (): void {
    Queue::fake();
    [, , $headers, $session] = endReleaseScenario(2, 200, 1);

    test()->withHeaders($headers)
        ->postJson('/api/candidate/interview/end', ['session_id' => $session->id, 'ended_reason' => 'completed'])
        ->assertOk()
        ->assertJsonPath('next_action', 'pause');

    expect(recordedReleaseCalls())->toBe(['POST /v1/sessions/stop', 'DELETE /v1/contexts/hg-ctx']);
    Queue::assertNotPushed(ReleaseEndedProviderSessionJob::class);
});
