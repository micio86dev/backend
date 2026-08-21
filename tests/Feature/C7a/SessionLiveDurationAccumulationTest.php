<?php

declare(strict_types=1);

/**
 * THE CRUX (interview-session-started-at, D1/D2/D3/D4a).
 *
 * Recorded duration is ACCUMULATED LIVE TIME, never the wall-clock span
 * between `started_at` and `ended_at`. A resume issues a NEW provider
 * session on the SAME `interview_sessions` row, so a candidate who walks
 * away for three hours between two live stretches must never have that gap
 * counted, and the minutes already spent in the first stretch must never be
 * discarded either.
 *
 * D4a — THE CLOSE BOUNDARY IS CAP-BOUNDED, NOT `now()`. BEAI never learns
 * when an abandoned stretch actually stopped: the browser is gone, and the
 * only event that ever arrives is the resume request, hours later. Nothing
 * tears the outgoing provider session down on abandonment — production
 * evidence: a candidate's browser emitted `[SDK:SESSION_STOPPED] Server
 * stopped session, reason: MAX_DURATION_REACHED` — so the FIRST stretch
 * genuinely stayed live and billable until the provider's own ceiling
 * (`ProviderFieldSpecs::HEYGEN_MAX_SECONDS`, 1200s for a session with no
 * avatar template — the fixture below configures none). The recorded figure
 * for that stretch is a disclosed, bounded UPPER ESTIMATE of an abandoned
 * stretch, never a claim of observed candidate activity. The SECOND stretch
 * closes normally through `/end`, well inside the ceiling, and records its
 * real, uncapped 6 minutes — the cap is a ceiling, never a floor.
 *
 * This test drives the scenario through the REAL endpoints —
 * `POST /start` (issue) -> travel 4 real minutes -> travel a 3 hour
 * abandonment gap with no live provider session -> `POST /start` (resume,
 * same competency, still `in_corso`) -> travel 6 real minutes ->
 * `POST /end` — and reads the result back from `InterviewSession::liveSeconds()`
 * exactly as the endpoints left it. A test that hand-seeds
 * `interview_session_live_periods` rows and sums them would prove only that
 * addition works; it could never fail on a wrong RESUME decision, because
 * the resume decision is never exercised. No period row is ever constructed
 * directly in this file.
 *
 * The expected total is DERIVED from the resolved cap, never a hardcoded
 * magic number — this test asserts BEHAVIOUR (cap + uncapped second stretch),
 * not a number that happens to match today's constant.
 *
 * REQ: interview-session "Recorded duration is accumulated live time, not
 *      wall-clock span" — scenario "Two live stretches sum; the gap between
 *      them does not"
 */

use App\Models\InterviewSession;
use App\Support\AvatarTemplates\ProviderFieldSpecs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('4 min live, 3 h abandonment gap, 6 min live -> capped first stretch + real second stretch, never the raw span and never the discarded first stretch', function (): void {
    Queue::fake();
    Http::fake(heygenOkFake());

    $org = casOrg();
    [$project] = casProject($org, 1);
    $participant = casParticipant($org, $project, 'in_attesa');
    $token = casBearer($participant);

    // No AvatarTemplate configured for this org — the resolved cap for a
    // heygen session falls back to the platform ceiling.
    $capSeconds = ProviderFieldSpecs::HEYGEN_MAX_SECONDS;
    $secondStretchSeconds = 6 * 60;
    $expectedTotal = $capSeconds + $secondStretchSeconds;

    // ── First stretch: /start issues the provider session, flips to in_corso ──
    $start1 = $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');
    $start1->assertStatus(201);
    $sessionId = $start1->json('session_id');

    // 4 minutes of real live time.
    $this->travel(4)->minutes();

    // A 3 hour abandonment gap — the candidate holds no live provider
    // session during this stretch. Nothing in the real endpoints is called
    // here. The provider's own ceiling (well under 3 hours) is what actually
    // bounds this stretch, not the 4-minute figure — that is the whole point
    // of D4a: BEAI never observed the candidate stop at minute 4.
    $this->travel(3)->hours();

    // ── RESUME: same competency, still in_corso, a FRESH provider session ──
    $resume = $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');
    $resume->assertStatus(201);
    expect($resume->json('session_id'))->toBe($sessionId);

    // 6 minutes of real live time on the second stretch — comfortably under
    // the cap, so it closes normally through /end with its real duration.
    $this->travel($secondStretchSeconds / 60)->minutes();

    // ── /end closes the second (and only open) stretch ──
    $end = $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $sessionId,
            'ended_reason' => 'completed',
        ]);
    $end->assertStatus(200);

    $seconds = casInTenant($org, fn () => InterviewSession::with('livePeriods')->findOrFail($sessionId)->liveSeconds());

    expect($seconds)->toBe($expectedTotal)
        ->not->toBe(11_400) // the 3-hour gap counted in full (raw now(), leave-alone span)
        ->not->toBe(360); // the first stretch discarded entirely (reset-on-resume)
});
