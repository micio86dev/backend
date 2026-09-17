<?php

declare(strict_types=1);

/**
 * `AvatarSilenceDetector::inspect()` is observation-only (its own docblock
 * says so) and all three of its call sites — `/end`, `/suspend`, and the
 * resume half of `/start`, the last two through
 * `harvestOutgoingTranscript()` — run it AFTER the transcript/session write
 * that request exists to make has already committed. A failure inside it (a
 * DB read, or the log sink its Sentry breadcrumbs depend on) must never turn
 * into a 500 for a candidate whose interview state is otherwise fine.
 *
 * One test per call site, and each one drives the endpoint whose name it
 * carries: the detector fires during THAT request's own harvest, so the
 * single throwing `Log::warning()` expectation is consumed by the endpoint
 * under test and nothing else.
 *
 * Simulates that failure at its only real failure surface — `Log::warning()`,
 * the call `AvatarSilenceDetector::inspect()` makes once a silent stretch is
 * found — by making that ONE call throw. `Log::partialMock()` is
 * deliberately NOT used here: it swaps the facade for a Mockery partial mock
 * of `LogManager` that was never constructed through the container, so any
 * passed-through (non-expected) call reaches real `LogManager` code running
 * on an uninitialized object and fails with its own unrelated
 * "Trying to access array offset on null". Both Log calls this flow actually
 * makes — the detector's `warning()` and the guard's own `error()` — are
 * stubbed explicitly instead.
 */

use App\Models\InterviewSession;
use App\Models\ProjectQuestion;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * A HeyGen fake whose transcript is `$transcript` — a silent stretch (four
 * candidate turns, no avatar reply after the opening) by default, so
 * `AvatarSilenceDetector::inspect()` actually reaches its `Log::warning()`
 * call and the guard under test has something to catch.
 *
 * @param  list<array{role: string, transcript: string, time_ms: int}>  $transcript
 */
function silenceGuardFake(array $transcript): void
{
    Http::fake(function ($request) use ($transcript) {
        $url = $request->url();

        if (str_contains($url, '/contexts')) {
            return Http::response(['data' => ['id' => 'ctx-'.uniqid()]], 200);
        }

        if (str_contains($url, '/sessions/token')) {
            return Http::response(['data' => [
                'session_id' => 'heygen-silence-guard-'.uniqid(),
                'session_token' => 'tok-'.uniqid(),
            ]], 200);
        }

        if (str_contains($url, '/transcript')) {
            return Http::response(['data' => ['transcript_data' => $transcript]], 200);
        }

        return Http::response([], 200);
    });
}

/**
 * @return array{session_id: int, bearer: string}
 */
function silenceGuardStart(): array
{
    Queue::fake();

    $org = casOrg();
    [$project, $comps] = casProject($org, 1);

    ProjectQuestion::where('project_id', $project->id)
        ->where('competency_id', $comps[0]->id)
        ->update(['text' => ['en' => 'Ciao! Come ti chiami?']]);

    $participant = casParticipant($org, $project, 'in_attesa');
    $bearer = casBearer($participant);

    $start = test()->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    app(TenantResolver::class)->setOrgId($org->id);
    app(TenantResolver::class)->setBypass(false);

    return [
        'session_id' => (int) $start->json('session_id'),
        'bearer' => $bearer,
    ];
}

/**
 * A silent stretch: an opening the avatar spoke, then four candidate turns
 * and no avatar reply — exactly what makes `AvatarSilenceDetector::inspect()`
 * call `Log::warning('provider_avatar_silent', ...)`.
 *
 * @return list<array{role: string, transcript: string, time_ms: int}>
 */
function silenceGuardSilentTranscript(): array
{
    return [
        ['role' => 'assistant', 'transcript' => 'Ciao! Come ti chiami?', 'time_ms' => 9000],
        ['role' => 'user', 'transcript' => 'Marco.', 'time_ms' => 8000],
        ['role' => 'user', 'transcript' => 'Hello?', 'time_ms' => 7000],
        ['role' => 'user', 'transcript' => 'Are you there?', 'time_ms' => 6000],
        ['role' => 'user', 'transcript' => 'Can you hear me?', 'time_ms' => 5000],
    ];
}

test('/suspend still suspends when the silence detector throws', function (): void {
    silenceGuardFake(silenceGuardSilentTranscript());
    $started = silenceGuardStart();

    // The ONE 'provider_avatar_silent' call throws; the guard's own catch
    // logs an 'error' in response. No other Log call is expected on this
    // path (teardown succeeds, so its own warning never fires).
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'provider_avatar_silent')
        ->andThrow(new RuntimeException('log sink unavailable'));
    Log::shouldReceive('error')->atLeast()->once();

    test()->withHeaders(['Authorization' => 'Bearer '.$started['bearer']])
        ->postJson('/api/candidate/interview/suspend', ['session_id' => $started['session_id']])
        ->assertOk();

    // The suspend completed its whole tail despite the swallowed exception:
    // the session stays resumable and points at no provider session.
    $session = InterviewSession::findOrFail($started['session_id']);
    expect($session->status)->toBe('in_corso')
        ->and($session->provider_session_ref)->toBeNull();
});

test('a resume /start still resumes when the silence detector throws', function (): void {
    silenceGuardFake(silenceGuardSilentTranscript());
    $started = silenceGuardStart();

    // NO /suspend first. The session is already `in_corso` WITH a live
    // provider ref, so the resume's OWN harvest is what reaches the detector
    // — the single throwing expectation below is consumed by this /start and
    // by nothing before it.
    $outgoingRef = InterviewSession::findOrFail($started['session_id'])->provider_session_ref;
    expect($outgoingRef)->not->toBeNull();

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'provider_avatar_silent')
        ->andThrow(new RuntimeException('log sink unavailable'));
    Log::shouldReceive('error')->atLeast()->once();

    // The resume itself — the request the throwing detector runs inside of —
    // must still succeed and hand back the SAME session, unaffected by the
    // swallowed exception.
    test()->withHeaders(['Authorization' => 'Bearer '.$started['bearer']])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201)
        ->assertJsonPath('session_id', $started['session_id']);

    $session = InterviewSession::findOrFail($started['session_id']);
    expect($session->status)->toBe('in_corso')
        // A fresh provider session was issued: the resume ran past the
        // detector, not around it.
        ->and($session->provider_session_ref)->not->toBe($outgoingRef);
});

test('/end still ends the session when the silence detector throws', function (): void {
    silenceGuardFake(silenceGuardSilentTranscript());
    $started = silenceGuardStart();

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'provider_avatar_silent')
        ->andThrow(new RuntimeException('log sink unavailable'));
    Log::shouldReceive('error')->atLeast()->once();

    test()->withHeaders(['Authorization' => 'Bearer '.$started['bearer']])
        ->postJson('/api/candidate/interview/end', ['session_id' => $started['session_id'], 'ended_reason' => 'completed'])
        ->assertOk();

    $session = InterviewSession::findOrFail($started['session_id']);
    expect($session->status)->toBe('completed')
        ->and($session->ended_at)->not->toBeNull();
});
