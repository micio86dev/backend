<?php

declare(strict_types=1);

/**
 * SessionLiveClock's three exits, absence, and re-offer
 * (interview-session-started-at, D4/D6, tasks 6.2-6.4).
 *
 * The plain issue-pending path (6.1) is already proven end to end by
 * `SessionLiveDurationAccumulationTest`'s first `/start` call: a non-null
 * `started_at` and one open period, asserted through the endpoint.
 *
 * REQ: interview-session "A live session records when it became live, at
 *      both sites that grant it"; "An absent recorded duration is never
 *      coerced to zero"
 */

use App\Models\InterviewSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ── D4a: the cap is a CEILING, never a FLOOR ───────────────────────────────

test('a stretch that closes normally, well inside the provider ceiling, records its real uncapped duration', function (): void {
    Queue::fake();
    Http::fake(heygenOkFake());

    $org = casOrg();
    [$project] = casProject($org, 1);
    $participant = casParticipant($org, $project, 'in_attesa');
    $token = casBearer($participant);

    $start = $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');
    $start->assertStatus(201);
    $sessionId = $start->json('session_id');

    // 7 real minutes, nowhere near the 1200s (20 min) HeyGen ceiling — a
    // normal, uninterrupted interview stretch.
    $this->travel(7)->minutes();

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', ['session_id' => $sessionId, 'ended_reason' => 'completed'])
        ->assertStatus(200);

    $seconds = casInTenant($org, fn () => InterviewSession::with('livePeriods')->findOrFail($sessionId)->liveSeconds());

    // The real, observed 7 minutes — never capped, never rounded to the
    // ceiling. A cap that also floors a normal interview would silently
    // inflate every ordinary session's cost.
    expect($seconds)->toBe(7 * 60);
});

// ── 6.2: absence ────────────────────────────────────────────────────────

test('a session that stays pending (429 provider_busy) has no started_at, no period, and every duration/cost consumer is absent', function (): void {
    Queue::fake();
    Http::fake(['*liveavatar*' => Http::response(['message' => 'busy'], 429)]);

    $org = casOrg();
    [$project] = casProject($org, 1);
    $participant = casParticipant($org, $project, 'in_attesa');
    $token = casBearer($participant);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(429)
        ->assertJson(['error' => 'provider_busy']);

    $session = casInTenant($org, fn () => InterviewSession::with('livePeriods')->firstOrFail());

    expect($session->status)->toBe('pending')
        ->and($session->started_at)->toBeNull()
        ->and($session->livePeriods)->toHaveCount(0)
        ->and($session->liveSeconds())->toBeNull();
});

// ── 6.3: the three exits close with the correct closed_reason ─────────────

test('/end closes the open period with closed_reason=end, and a second /end closes nothing a second time', function (): void {
    Queue::fake();
    Http::fake(heygenOkFake());

    $org = casOrg();
    [$project] = casProject($org, 1);
    $participant = casParticipant($org, $project, 'in_attesa');
    $token = casBearer($participant);

    $start = $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');
    $start->assertStatus(201);
    $sessionId = $start->json('session_id');

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', ['session_id' => $sessionId, 'ended_reason' => 'completed'])
        ->assertStatus(200);

    $period = casInTenant($org, fn () => InterviewSession::findOrFail($sessionId)->livePeriods()->firstOrFail());
    expect($period->closed_reason)->toBe('end');
    expect($period->ended_at)->not->toBeNull();
    $endedAtFirst = $period->ended_at;

    // FIX-3 idempotency guard: a second /end call on an already-ended
    // session 409s before it reaches SessionLiveClock::close() — the period
    // row's ended_at is unchanged (content-based, never a call-count guard).
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', ['session_id' => $sessionId, 'ended_reason' => 'completed'])
        ->assertStatus(409);

    $period->refresh();
    expect($period->ended_at->equalTo($endedAtFirst))->toBeTrue();
});

test('markSessionError closes the open period with closed_reason=error', function (): void {
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 1);
    $participant = casParticipant($org, $project, 'in_attesa');
    $token = casBearer($participant);

    // A 422 (ClientError) reaches markSessionError WITHOUT ever having
    // opened a period — issue() throws before the txn that would open one.
    // Assert instead the harder case: an in_corso session whose RESUME
    // issue() throws (Upstream), closing the period it already held open.
    //
    // ONE stateful fake for the whole test: `Http::fake()` MERGES stubs in
    // registration order and matches the FIRST one that fits, so a second
    // `Http::fake([...])` call with an overlapping wildcard would never be
    // reached — the first-registered `heygenOkFake()` patterns would keep
    // winning. A token-request counter is what actually distinguishes
    // "first issue() succeeds" from "the RESUME's issue() fails".
    $tokenAttempts = 0;
    Http::fake(function ($request) use (&$tokenAttempts) {
        $url = $request->url();

        if (str_contains($url, '/contexts')) {
            return Http::response(['data' => ['id' => 'ctx-'.uniqid()]], 200);
        }

        if (str_contains($url, '/sessions/token')) {
            $tokenAttempts++;

            return $tokenAttempts === 1
                ? Http::response(['data' => ['session_id' => 'sess-1', 'session_token' => 'tok-1']], 200)
                : Http::response(['message' => 'exploded'], 503);
        }

        if (str_contains($url, '/transcript')) {
            return Http::response(['data' => ['transcript_data' => []]], 200);
        }

        return Http::response([], 200);
    });

    $start = $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');
    $start->assertStatus(201);
    $sessionId = $start->json('session_id');

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(502);

    $period = casInTenant($org, fn () => InterviewSession::findOrFail($sessionId)->livePeriods()->firstOrFail());
    expect($period->closed_reason)->toBe('error');
    expect($period->ended_at)->not->toBeNull();
});

test('a resume closes the outgoing period with closed_reason=resume and opens a new one', function (): void {
    Queue::fake();
    Http::fake(heygenOkFake());

    $org = casOrg();
    [$project] = casProject($org, 1);
    $participant = casParticipant($org, $project, 'in_attesa');
    $token = casBearer($participant);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(201);
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(201);

    $session = casInTenant($org, fn () => InterviewSession::with('livePeriods')->firstOrFail());
    $periods = $session->livePeriods->sortBy('id')->values();

    expect($periods)->toHaveCount(2);
    expect($periods[0]->closed_reason)->toBe('resume');
    expect($periods[0]->ended_at)->not->toBeNull();
    expect($periods[1]->ended_at)->toBeNull(); // the fresh stretch stays open
});

// ── 6.4: re-offer (D6) — periods and started_at survive the reset ─────────

test('a re-offered competency keeps its periods and started_at across the reset, and the next /start opens a second stretch', function (): void {
    Queue::fake();

    $org = casOrg();
    [$project, $comps] = casProject($org, 1);
    $participant = casParticipant($org, $project, 'in_attesa');
    $token = casBearer($participant);

    // ONE stateful fake for the whole test (see the markSessionError test
    // above for why: `Http::fake()` merges stubs in registration order and
    // matches the first fit, so a later `Http::fake([...])` call can never
    // override an earlier overlapping wildcard within the same test).
    $shouldFail = true;
    Http::fake(function ($request) use (&$shouldFail) {
        $url = $request->url();

        if ($shouldFail) {
            return Http::response(['message' => 'upstream exploded'], 503);
        }

        if (str_contains($url, '/contexts')) {
            return Http::response(['data' => ['id' => 'ctx-'.uniqid()]], 200);
        }

        if (str_contains($url, '/sessions/token')) {
            return Http::response(['data' => ['session_id' => 'sess-'.uniqid(), 'session_token' => 'tok']], 200);
        }

        if (str_contains($url, '/transcript')) {
            return Http::response(['data' => ['transcript_data' => []]], 200);
        }

        return Http::response([], 200);
    });

    // First attempt fails (Upstream) — session -> error, participant -> errore.
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(502);

    $session = casInTenant($org, fn () => InterviewSession::where('participant_id', $participant->id)->firstOrFail());
    expect($session->status)->toBe('error');
    // The failed attempt never went live — no period exists yet.
    expect(casInTenant($org, fn () => $session->livePeriods()->count()))->toBe(0);

    // Recover the participant so the errored competency is re-offered.
    DB::table('participants')->where('id', $participant->id)->update(['status' => 'in_attesa']);
    resetAuthGuardState();

    // Second attempt succeeds — opens the FIRST real stretch through :768.
    $shouldFail = false;
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    $session->refresh();
    $firstStartedAt = $session->started_at;
    expect($firstStartedAt)->not->toBeNull();
    expect(casInTenant($org, fn () => $session->livePeriods()->count()))->toBe(1);

    // Third call — RESUME (still in_corso) — opens a SECOND stretch, keeps
    // started_at unchanged (write-once, D2).
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    $session->refresh();
    expect($session->started_at->equalTo($firstStartedAt))->toBeTrue();
    expect(casInTenant($org, fn () => $session->livePeriods()->count()))->toBe(2);
});
