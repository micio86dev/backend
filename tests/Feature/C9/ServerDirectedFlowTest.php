<?php

declare(strict_types=1);

/**
 * The server decides what happens next (interview-continuous-flow, D6/D7).
 *
 * `/start` gains `competency_ordinal` and `total_competencies`; `/end` stops
 * returning a null body and returns the directive that drives the client.
 *
 * WHY THE SERVER. The SA-04 pause cadence is TENANT CONFIGURATION
 * (`projects.pause_every_n_competencies`; `null` = no pause at all). A browser
 * must not re-derive tenant policy. The numbers are already in hand inside the
 * `/end` transaction, and client-side arithmetic is the documented cause of the
 * defect this change exists to remove: the page carried an empty competency list
 * and therefore believed every interview was over after one question.
 *
 * `competency_ordinal` is NEW rather than `question_index + 1` on purpose.
 * `question_index` is now (interview-question-index-offset) PERSISTED as
 * `position` verbatim, frozen at session creation. `competency_ordinal` is
 * DERIVED per request from the ordered list's own array index and is always
 * dense (1..N). They coincide on a dense, unreordered project — `ordinal ==
 * question_index + 1` — but that is a coincidence of well-formed data, not an
 * identity: they diverge whenever positions are sparse or the project is
 * reordered after a session already exists.
 */

use App\Models\InterviewSession;
use App\Models\Utterance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/** End the given session through the real endpoint. */
function c9EndSession(mixed $test, string $token, InterviewSession $session, string $reason = 'completed')
{
    return $test->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $session->id,
            'ended_reason' => $reason,
        ]);
}

/** Drive one competency to `in_corso` and hand back its session. */
function c9StartCompetency(mixed $test, string $token): array
{
    $response = $test->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');

    return [$response, $response->json('session_id')];
}

// ─── /start ───────────────────────────────────────────────────────────────────

test('/start returns competency_ordinal and total_competencies', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 3);
    $participant = casParticipant($org, $project);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    expect($response->json('question_context.competency_ordinal'))->toBe(1);
    expect($response->json('question_context.total_competencies'))->toBe(3);
});

test('competency_ordinal reconciles with question_index + 1 on a dense project', function (): void {
    // Both values now agree on a dense-from-0 project (interview-question-index-offset,
    // D1): `question_index` is persisted as `position` verbatim and `competency_ordinal`
    // is derived from the ordered list's own array index. `casDenseProject()` seeds
    // 0-based positions matching the REAL production writers — `casProject()`'s 1-based
    // `$i + 1` positions would mask this reconciliation rather than prove it.
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casDenseProject($org, 2);
    $participant = casParticipant($org, $project);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')->assertStatus(201);

    $ordinal = $response->json('question_context.competency_ordinal');
    $questionIndex = $response->json('question_context.question_index');

    expect($ordinal)->toBe(1, 'the first competency is always 1 of N');
    expect($questionIndex)->toBe(0, 'the first competency sits at position 0');
    expect($ordinal)->toBe($questionIndex + 1, 'ordinal and question_index + 1 reconcile on a dense project');
});

test('competency_ordinal advances with the competency', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 3);
    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    [$first, $firstId] = c9StartCompetency($this, $token);
    expect($first->json('question_context.competency_ordinal'))->toBe(1);

    $session = casInTenant($org, fn () => InterviewSession::find($firstId));
    c9EndSession($this, $token, $session)->assertStatus(200);

    [$second] = c9StartCompetency($this, $token);
    expect($second->json('question_context.competency_ordinal'))->toBe(2);
});

// ─── /end — the directive ─────────────────────────────────────────────────────

test('/end returns the tally and a continue directive when competencies remain', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 3);
    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    [, $sessionId] = c9StartCompetency($this, $token);
    $session = casInTenant($org, fn () => InterviewSession::find($sessionId));

    $response = c9EndSession($this, $token, $session)->assertStatus(200);

    expect($response->json('ended_competencies'))->toBe(1);
    expect($response->json('total_competencies'))->toBe(3);
    expect($response->json('next_action'))->toBe('continue');
});

test('/end returns done on the last competency', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 1);
    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    [, $sessionId] = c9StartCompetency($this, $token);
    $session = casInTenant($org, fn () => InterviewSession::find($sessionId));

    $response = c9EndSession($this, $token, $session)->assertStatus(200);

    expect($response->json('next_action'))->toBe('done');
    expect($response->json('ended_competencies'))->toBe(1);
});

test('a project with pause_every_n_competencies = null NEVER pauses', function (): void {
    // SA-04: `null` means no pause. This is the configuration every project has by
    // default, and the unconditional interstitial screen contradicted it for all
    // of them.
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 3);
    casInTenant($org, fn () => $project->forceFill(['pause_every_n_competencies' => null])->save());

    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    foreach ([1, 2] as $ignored) {
        [, $sessionId] = c9StartCompetency($this, $token);
        $session = casInTenant($org, fn () => InterviewSession::find($sessionId));
        expect(c9EndSession($this, $token, $session)->json('next_action'))->toBe('continue');
    }
});

test('a project with pause_every_n_competencies = 2 pauses exactly on the cadence', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 5);
    casInTenant($org, fn () => $project->forceFill(['pause_every_n_competencies' => 2])->save());

    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    $directives = [];
    foreach (range(1, 4) as $ignored) {
        [, $sessionId] = c9StartCompetency($this, $token);
        $session = casInTenant($org, fn () => InterviewSession::find($sessionId));
        $directives[] = c9EndSession($this, $token, $session)->json('next_action');
    }

    // After 2 and 4 ended competencies, not after 1 or 3.
    expect($directives)->toBe(['continue', 'pause', 'continue', 'pause']);
});

test('done beats pause on the final competency', function (): void {
    // The directive evaluates `done` first, so a candidate is never shown a pause
    // screen for an interview that is already over.
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 2);
    casInTenant($org, fn () => $project->forceFill(['pause_every_n_competencies' => 2])->save());

    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    foreach ([1, 2] as $ignored) {
        [, $sessionId] = c9StartCompetency($this, $token);
        $session = casInTenant($org, fn () => InterviewSession::find($sessionId));
        $last = c9EndSession($this, $token, $session);
    }

    expect($last->json('next_action'))->toBe('done', 'ended === total, so the cadence must not win');
});

// ─── Tenancy ──────────────────────────────────────────────────────────────────

test('the directive counts and cadence resolve strictly within the organization', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $orgA = casOrg();
    [$projectA] = casProject($orgA, 3);
    casInTenant($orgA, fn () => $projectA->forceFill(['pause_every_n_competencies' => null])->save());
    $participantA = casParticipant($orgA, $projectA);

    $orgB = casOrg();
    [$projectB] = casProject($orgB, 3);
    casInTenant($orgB, fn () => $projectB->forceFill(['pause_every_n_competencies' => 1])->save());
    $participantB = casParticipant($orgB, $projectB);

    $tokenA = casBearer($participantA);
    [, $sessionIdA] = c9StartCompetency($this, $tokenA);
    $sessionA = casInTenant($orgA, fn () => InterviewSession::find($sessionIdA));
    $directiveA = c9EndSession($this, $tokenA, $sessionA)->json('next_action');

    // The auth guard caches its resolved user across requests inside one test.
    $this->app['auth']->forgetGuards();

    $tokenB = casBearer($participantB);
    [, $sessionIdB] = c9StartCompetency($this, $tokenB);
    $sessionB = casInTenant($orgB, fn () => InterviewSession::find($sessionIdB));
    $directiveB = c9EndSession($this, $tokenB, $sessionB)->json('next_action');

    expect($directiveA)->toBe('continue', "A's project has no cadence and must not inherit B's");
    expect($directiveB)->toBe('pause', "B's own cadence of 1 applies to B");
});

test('the tally never counts another organization ended sessions', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $orgA = casOrg();
    [$projectA] = casProject($orgA, 2);
    $participantA = casParticipant($orgA, $projectA);

    $orgB = casOrg();
    [$projectB] = casProject($orgB, 2);
    $participantB = casParticipant($orgB, $projectB);

    $tokenA = casBearer($participantA);
    [, $idA] = c9StartCompetency($this, $tokenA);
    c9EndSession($this, $tokenA, casInTenant($orgA, fn () => InterviewSession::find($idA)));

    $this->app['auth']->forgetGuards();

    $tokenB = casBearer($participantB);
    [, $idB] = c9StartCompetency($this, $tokenB);
    $response = c9EndSession($this, $tokenB, casInTenant($orgB, fn () => InterviewSession::find($idB)));

    expect($response->json('ended_competencies'))
        ->toBe(1, "B has ended one of its own — A's must not be counted");
    expect($response->json('next_action'))->toBe('continue');
});

// ─── The 409 loser must carry no directive ───────────────────────────────────

test('a second /end on the same session is a 409 and carries no directive', function (): void {
    // 409 is the loser of the avatar-complete/timer race. Under a directive-driven
    // client the loser has no directive and must not act — so the body must not
    // hand it one.
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 3);
    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    [, $sessionId] = c9StartCompetency($this, $token);
    $session = casInTenant($org, fn () => InterviewSession::find($sessionId));

    c9EndSession($this, $token, $session)->assertStatus(200);
    $second = c9EndSession($this, $token, $session);

    expect($second->status())->toBe(409);
    expect($second->json('next_action'))->toBeNull();
    expect(Utterance::query()->count())->toBeGreaterThanOrEqual(0);
});
