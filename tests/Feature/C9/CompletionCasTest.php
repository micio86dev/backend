<?php

declare(strict_types=1);

/**
 * Completion CAS — a participant whose competencies are all terminal MUST reach
 * `in_valutazione` (interview-continuous-flow, D1/D5).
 *
 * The defect this pins: `/end` counted only `completed|timeout|skipped`, while
 * `resolveNextCompetency()` treats `error` as terminal and skips it. A participant
 * with one errored competency therefore exhausted every competency while the tally
 * stayed at `total - 1`, so the CAS never fired — no scoring, no webhook, no
 * notification, and nothing anywhere said so.
 *
 * `/end` alone could never have repaired it: a session reaches `error` inside
 * `/start`'s provider-failure path, and `/end` is never called for that competency.
 * The repair is the two additional call sites.
 *
 * The tally and the re-offer are ONE behaviour, and these tests are why we know.
 * An `error` counts only once it has spent its re-offer:
 *   - count it earlier and a single transient 4xx — our own payload bug — ends the
 *     interview with no second chance. `InterviewStartTest`'s ratified "participant
 *     status UNCHANGED" guard goes red on exactly that.
 *   - count it later and a competency the resolver skips is never tallied, which is
 *     the original stranding wearing a different name.
 * Both halves shipped together in `api` v0.23.0.
 */

use App\Actions\Participant\RecoverFailedParticipant;
use App\Jobs\FinalizeInterview;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Participant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ─── error_count is written where the error is ────────────────────────────────

test('markSessionError increments error_count', function (): void {
    Http::fake(casClientErrorFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 1);
    $participant = casParticipant($org, $project);

    $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(500); // ClientError — our bug, not the upstream's

    $session = casInTenant($org, fn () => InterviewSession::where('participant_id', $participant->id)->first());

    expect($session)->not->toBeNull();
    expect($session->status)->toBe('error');
    expect($session->error_count)->toBe(1, 'the attempt must be recorded where the error is marked');
});

test('error_count accumulates across the re-offer, on the same row', function (): void {
    // The bound is only a bound if it survives the reset that re-offers the
    // competency. `ResetSessionForRetry` clears status, ref, reason and ended_at
    // and deliberately leaves this column alone — a counter cleared by its own
    // reset would grant unlimited attempts.
    Http::fake(casClientErrorFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 2);
    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    // Both calls hit the SAME competency: the first failure re-offers it rather
    // than moving on, because it still has an attempt left.
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(500);
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(500);

    $sessions = casInTenant($org, fn () => InterviewSession::where('participant_id', $participant->id)->get());

    expect($sessions)->toHaveCount(1, 'the re-offer reuses the row; the unique constraint forbids a second');
    expect($sessions->first()->error_count)->toBe(InterviewSession::MAX_ERROR_ATTEMPTS);
});

test('a third attempt is never offered — the competency is closed and the next one begins', function (): void {
    Http::fake(casClientErrorFake());
    Queue::fake();

    $org = casOrg();
    [$project, $comps] = casProject($org, 2);
    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    // Burn the first competency's two attempts.
    foreach ([1, 2] as $ignored) {
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/candidate/interview/start')->assertStatus(500);
    }

    // The third call must move ON, not offer a third attempt at the same one.
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(500);

    $sessions = casInTenant($org, fn () => InterviewSession::where('participant_id', $participant->id)
        ->pluck('error_count', 'competency_code'));

    expect($sessions)->toHaveCount(2, 'the second competency was reached');
    expect($sessions[$comps[0]->code])->toBe(InterviewSession::MAX_ERROR_ATTEMPTS);
    expect($sessions[$comps[1]->code])->toBe(1);
});

test('a 4xx with an attempt left does NOT end the interview — our bug must not burn the candidate', function (): void {
    // The ratified D4 guarantee: a ClientError is a payload bug on our side. The
    // candidate keeps their competency and their interview.
    Http::fake(casClientErrorFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 1);
    $participant = casParticipant($org, $project);

    $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')->assertStatus(500);

    expect($participant->fresh()->status)->toBe('in_corso');
    Queue::assertNotPushed(FinalizeInterview::class);
});

// ─── The stranding this change exists to end ──────────────────────────────────

test('a terminal error on the last competency reaches in_valutazione and dispatches scoring', function (): void {
    Http::fake(casClientErrorFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 1);
    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    // Two failures: the first re-offers, the second exhausts the bound.
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(500);
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(500);

    expect($participant->fresh()->status)->toBe(
        'in_valutazione',
        'the only competency has spent both attempts, so the interview is over and must be settled'
    );

    Queue::assertPushed(FinalizeInterview::class);
});

test('an upstream failure does NOT dispatch scoring and lands the participant at errore', function (): void {
    // The CAS predicate `where('status','in_corso')` is the guard: markParticipantFailed()
    // has already moved the participant to `errore`, so the CAS matches zero rows.
    // This is why the settle call site sits AFTER the classification switch and not
    // inside markSessionError() — settling first would dispatch scoring and then have
    // the participant overwritten to `errore`, leaving a scored, webhooked candidate
    // sitting at a failure status.
    Http::fake(casUpstreamFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 1);
    $participant = casParticipant($org, $project);

    $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(502);

    expect($participant->fresh()->status)->toBe('errore');
    Queue::assertNotPushed(FinalizeInterview::class);
});

test('a returning participant with everything terminal is settled on /start, before the 422', function (): void {
    // Site 3 — the idempotent self-heal. It cannot be the primary mechanism (after a
    // terminal error the client shows an error screen and may never call anything
    // again), but it is what rescues participants stranded before this change, and
    // it must not double-dispatch.
    Queue::fake();

    $org = casOrg();
    [$project, $competencies] = casProject($org, 1);
    $participant = casParticipant($org, $project);

    // Hand-build the stranded state: the one competency is terminal at `error`,
    // exactly as a pre-change participant was left.
    casInTenant($org, function () use ($org, $project, $participant, $competencies): void {
        $s = new InterviewSession;
        $s->forceFill([
            'organization_id' => $org->id,
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => 0,
            'competency_code' => $competencies[0]->code,
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'heygen',
            'status' => 'error',
            'ended_reason' => 'error',
            'error_count' => InterviewSession::MAX_ERROR_ATTEMPTS,
            'ended_at' => now(),
        ]);
        $s->save();
    });

    $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(422)
        ->assertJson(['error' => 'no_competency_remaining']);

    expect($participant->fresh()->status)->toBe('in_valutazione');
    Queue::assertPushed(FinalizeInterview::class, 1);
});

test('the settle is idempotent — a second /start does not dispatch scoring twice', function (): void {
    Queue::fake();

    $org = casOrg();
    [$project, $competencies] = casProject($org, 1);
    $participant = casParticipant($org, $project);

    casInTenant($org, function () use ($org, $project, $participant, $competencies): void {
        $s = new InterviewSession;
        $s->forceFill([
            'organization_id' => $org->id,
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => 0,
            'competency_code' => $competencies[0]->code,
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'heygen',
            'status' => 'error',
            'ended_reason' => 'error',
            'error_count' => InterviewSession::MAX_ERROR_ATTEMPTS,
            'ended_at' => now(),
        ]);
        $s->save();
    });

    $token = casBearer($participant);
    $headers = ['Authorization' => 'Bearer '.$token];

    $this->withHeaders($headers)->postJson('/api/candidate/interview/start')->assertStatus(422);
    $this->withHeaders($headers)->postJson('/api/candidate/interview/start')->assertStatus(422);

    // The CAS predicate is the single-winner guard: the second pass finds the
    // participant already at `in_valutazione` and updates zero rows.
    Queue::assertPushed(FinalizeInterview::class, 1);
});

// ─── The destructive step, asserted where it happens ─────────────────────────

test('the re-offer discards the first attempt transcript at /start, before the provider is called', function (): void {
    // The ratified destructive step. Asserted directly at /start rather than
    // inferred from a later transcript read, because the later read is exactly
    // where it can hide: replaceUtterances() returns early on an empty provider
    // transcript, which is what a still-degraded provider returns — so a stale
    // first attempt would survive and be scored as the second one's answer.
    Http::fake(casClientErrorFake());
    Queue::fake();

    $org = casOrg();
    [$project, $comps] = casProject($org, 1);
    $participant = casParticipant($org, $project);

    // Attempt 1 fails and leaves a transcript behind.
    $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')->assertStatus(500);

    $session = casInTenant($org, fn () => InterviewSession::where('participant_id', $participant->id)->first());

    foreach (['candidate', 'avatar'] as $i => $speaker) {
        DB::table('utterances')->insert([
            'interview_session_id' => $session->id,
            'organization_id' => $org->id,
            'speaker' => $speaker,
            'text' => "attempt one turn {$i}",
            'ts' => now(),
        ]);
    }

    expect(DB::table('utterances')->where('interview_session_id', $session->id)->count())->toBe(2);

    // Attempt 2 — the re-offer. The provider fails again, so nothing downstream
    // could have cleaned up: whatever is gone was deleted by the reset itself.
    $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')->assertStatus(500);

    expect(DB::table('utterances')->where('interview_session_id', $session->id)->count())
        ->toBe(0, 'the previous attempt must not survive into the re-offer');

    expect($comps)->toHaveCount(1);
});

// ─── The operator path is deliberately different ─────────────────────────────

test('operator recovery stays UNBOUNDED — it does not consult error_count', function (): void {
    // D4. An operator acting on a known incident is not the failure mode the
    // candidate-facing ceiling exists to contain. This asymmetry is deliberate;
    // pinned so a future reader does not "harmonise" it away.
    $org = casOrg();
    [$project, $comps] = casProject($org, 1);
    $participant = casParticipant($org, $project, 'errore');

    $session = casInTenant($org, function () use ($org, $project, $participant, $comps) {
        $s = new InterviewSession;
        $s->forceFill([
            'organization_id' => $org->id,
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => 0,
            'competency_code' => $comps[0]->code,
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'heygen',
            'status' => 'error',
            'ended_reason' => 'error',
            // Already at the ceiling: the automatic path would refuse.
            'error_count' => InterviewSession::MAX_ERROR_ATTEMPTS,
            'ended_at' => now(),
        ]);
        $s->save();

        return $s;
    });

    casInTenant($org, fn () => app(RecoverFailedParticipant::class)
        ->handle($participant->id, null, 'operator recovery under test'));

    expect($session->fresh()->status)->toBe(
        'pending',
        'the operator path recovers a session the automatic bound has already closed'
    );
});

test('a session reset by operator recovery is RESUMED, not re-offered — participant-sso holds verbatim', function (): void {
    // The ratified "Resume, not restart" scenario justifies its reset by asserting
    // that resolveNextCompetency() keeps skipping already-answered competencies.
    // The new re-offer branch changes how that method treats `error`, so this pins
    // that the two do not collide: a reset session is `pending`, and `pending` is
    // caught by the RESUME branch ordered ABOVE the re-offer branch. It never
    // reaches the re-offer path, and no second attempt is consumed.
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project, $comps] = casProject($org, 1);
    $participant = casParticipant($org, $project, 'errore');

    $session = casInTenant($org, function () use ($org, $project, $participant, $comps) {
        $s = new InterviewSession;
        $s->forceFill([
            'organization_id' => $org->id,
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => 0,
            'competency_code' => $comps[0]->code,
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'heygen',
            'status' => 'error',
            'ended_reason' => 'error',
            'error_count' => 1,
            'ended_at' => now(),
        ]);
        $s->save();

        return $s;
    });

    casInTenant($org, fn () => app(RecoverFailedParticipant::class)
        ->handle($participant->id, null, 'operator recovery under test'));

    $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant->fresh())])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    expect($session->fresh()->error_count)->toBe(
        1,
        'resuming a pending session must not spend another attempt'
    );
});

// ─── Tenancy ──────────────────────────────────────────────────────────────────

test('the tally never counts another organization sessions', function (): void {
    Http::fake(casClientErrorFake());
    Queue::fake();

    $orgA = casOrg();
    [$projectA] = casProject($orgA, 2);
    $participantA = casParticipant($orgA, $projectA);

    $orgB = casOrg();
    [$projectB] = casProject($orgB, 1);
    $participantB = casParticipant($orgB, $projectB);

    // A burns both attempts on the first of its two competencies.
    $tokenA = casBearer($participantA);
    foreach ([1, 2] as $ignored) {
        $this->withHeaders(['Authorization' => 'Bearer '.$tokenA])
            ->postJson('/api/candidate/interview/start')->assertStatus(500);
    }

    // The auth guard caches its resolved user, and the test container survives
    // between requests — without this the next call would still be authenticated
    // as the first participant, silently testing one candidate twice. Production
    // never sees it: each request there gets its own container.
    $this->app['auth']->forgetGuards();

    // B exhausts its single competency and settles.
    $tokenB = casBearer($participantB);
    foreach ([1, 2] as $ignored) {
        $this->withHeaders(['Authorization' => 'Bearer '.$tokenB])
            ->postJson('/api/candidate/interview/start')->assertStatus(500);
    }

    expect($participantB->fresh()->status)->toBe('in_valutazione');

    expect($participantA->fresh()->status)->toBe(
        'in_corso',
        'one competency of two is terminal, so A is not finished'
    );
});

// ─── The candidate is told they are re-attempting (D10) ──────────────────────

test('a re-offered competency is announced with the retry greeting, not next', function (): void {
    // Ratified decision 6. The re-offer exists because something failed on OUR
    // side; repeating the question with the ordinary greeting reads to the
    // candidate as the avatar not having listened.
    Queue::fake();

    // One fake for both attempts: a second Http::fake() MERGES with the first
    // rather than replacing it, so the 422 stub would keep winning.
    $attempt = 0;
    Http::fake(function ($request) use (&$attempt) {
        if (str_contains($request->url(), '/contexts')) {
            $attempt++;

            if ($attempt === 1) {
                return Http::response(['message' => 'malformed request'], 422);
            }

            return Http::response(['data' => ['id' => 'ctx-retry']], 200);
        }

        if (str_contains($request->url(), '/sessions/token')) {
            return Http::response(['data' => [
                'session_id' => 'heygen-session-retry',
                'session_token' => 'heygen-token-retry',
            ]], 200);
        }

        return Http::response([], 200);
    });

    $org = casOrg();
    [$project, $comps] = casProject($org, 2);
    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    // Attempt 1 fails, leaving the competency re-offerable.
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(500);

    // Attempt 2 — the re-offer. The context body carries the spoken greeting.
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(201);

    $competencyName = $comps[0]->getTranslation('name', $project->language) ?? $comps[0]->code;
    $expected = trans('interview.opening.retry', ['competency' => $competencyName], $project->language);

    Http::assertSent(function ($request) use ($expected) {
        return str_contains($request->url(), '/contexts')
            && ($request->data()['opening_text'] ?? null) === $expected;
    });
});
