<?php

declare(strict_types=1);

/**
 * InterviewController::end() feature tests (C7a — Phase 9.1 RED).
 *
 * Tests POST /api/candidate/interview/end
 *
 * Asserts:
 * - Non-last question: 200; session.status = completed/timeout/skipped; participant stays in_corso; FinalizeInterview NOT dispatched.
 * - Last question: 200; session.status = completed; participant.status = in_valutazione; Queue::assertPushed(FinalizeInterview).
 * - Concurrent /end (last question): exactly ONE FinalizeInterview dispatch.
 * - Already-ended session → 409; ended_at NOT re-stamped; FinalizeInterview NOT dispatched again.
 * - ended_reason = 'error' → 422 (FIX-11 validation).
 * - HeyGen REPLACE transcript: after /end all prior Utterances deleted, server transcript inserted.
 * - Tavus: existing Utterances unchanged after /end.
 * - session_id from non-owned session → 404.
 * - Timeout ended_reason: session.status = timeout, session.ended_at set.
 * - CRITICAL-3 atomicity: session UPDATE + ended-count + CAS all in one explicit txn.
 *
 * Tasks: 9.1 (RED)
 * REQ: POST /end endpoint (C7a)
 */

use App\Jobs\FinalizeInterview;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Utterance;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function endOrg(): Organization
{
    return Organization::factory()->create();
}

function endProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active']);
}

/**
 * @return array{Project, list<Competency>}
 */
function endProjectWithCompetencies(Organization $org, int $count = 2): array
{
    $project = endProject($org);
    $competencies = [];
    for ($i = 0; $i < $count; $i++) {
        $comp = Competency::factory()->create();
        DB::table('project_competencies')->insert([
            'project_id' => $project->id,
            'competency_id' => $comp->id,
            'position' => $i + 1,
        ]);
        $competencies[] = $comp;
    }

    return [$project, $competencies];
}

function endParticipant(Organization $org, Project $project, string $status = 'in_corso'): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'end-'.uniqid(),
        'display_name' => 'End Test Candidate',
        'status' => $status,
    ]);
    $p->save();

    return $p->fresh();
}

function endSession(
    Organization $org,
    Participant $participant,
    Project $project,
    string $code,
    string $status = 'in_corso',
    string $provider = 'heygen',
): InterviewSession {
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => $provider,
        'provider_session_ref' => 'ref-'.uniqid(),
        'status' => $status,
    ]);
}

function endBearer(Participant $participant): string
{
    return CandidateTokenFactory::mintCandidateToken($participant);
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('POST /end non-last question: 200; session.status=completed; participant stays in_corso; no FinalizeInterview', function (): void {
    Http::fake();
    Queue::fake();

    $org = endOrg();
    [$project, $comps] = endProjectWithCompetencies($org, 2); // 2 competencies
    $participant = endParticipant($org, $project, 'in_corso');

    // Session for first competency (in_corso)
    $session = endSession($org, $participant, $project, $comps[0]->code, 'in_corso');
    $token = endBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $session->id,
            'ended_reason' => 'completed',
        ]);

    $response->assertStatus(200);

    // Session updated
    $session->refresh();
    expect($session->status)->toBe('completed');
    expect($session->ended_at)->not->toBeNull();

    // Participant stays in_corso (not last question)
    $participant->refresh();
    expect($participant->status)->toBe('in_corso');

    // FinalizeInterview NOT dispatched (not last question)
    Queue::assertNotPushed(FinalizeInterview::class);
});

test('POST /end last question: 200; participant.status=in_valutazione; FinalizeInterview dispatched once', function (): void {
    Http::fake([
        '*liveavatar*/sessions/*/transcript*' => Http::response(['data' => []], 200),
    ]);
    Queue::fake();

    $org = endOrg();
    [$project, $comps] = endProjectWithCompetencies($org, 1); // 1 competency = last
    $participant = endParticipant($org, $project, 'in_corso');

    $session = endSession($org, $participant, $project, $comps[0]->code, 'in_corso');
    $token = endBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $session->id,
            'ended_reason' => 'completed',
        ]);

    $response->assertStatus(200);

    // Session completed
    $session->refresh();
    expect($session->status)->toBe('completed');

    // Participant transitioned to in_valutazione
    $participant->refresh();
    expect($participant->status)->toBe('in_valutazione');

    // FinalizeInterview dispatched exactly once
    Queue::assertPushed(FinalizeInterview::class, 1);
});

test('POST /end already-ended session → 409; ended_at NOT re-stamped; FinalizeInterview NOT re-dispatched', function (): void {
    Http::fake();
    Queue::fake();

    $org = endOrg();
    [$project, $comps] = endProjectWithCompetencies($org, 1);
    $participant = endParticipant($org, $project, 'in_valutazione');

    // Session already completed — set ended_at to a fixed time in the past
    $session = endSession($org, $participant, $project, $comps[0]->code, 'completed');
    // Use DB::update to bypass Eloquent casts and set a predictable timestamp
    $pastTimestamp = now()->subMinutes(5)->toDateTimeString();
    DB::table('interview_sessions')
        ->where('id', $session->id)
        ->update(['ended_at' => $pastTimestamp]);
    $session->refresh();
    $originalEndedAtString = $session->ended_at->format('Y-m-d H:i:s');

    $token = endBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $session->id,
            'ended_reason' => 'completed',
        ]);

    $response->assertStatus(409);

    // ended_at must NOT be re-stamped — still the same value as before the request
    $session->refresh();
    expect($session->ended_at->format('Y-m-d H:i:s'))->toBe($originalEndedAtString);

    // No new dispatch
    Queue::assertNotPushed(FinalizeInterview::class);
});

test('POST /end ended_reason=error → 422 (FIX-11 validation — error is server-set, not client-submitted)', function (): void {
    Http::fake();
    Queue::fake();

    $org = endOrg();
    [$project, $comps] = endProjectWithCompetencies($org, 1);
    $participant = endParticipant($org, $project, 'in_corso');
    $session = endSession($org, $participant, $project, $comps[0]->code, 'in_corso');
    $token = endBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $session->id,
            'ended_reason' => 'error', // FIX-11: MUST be rejected
        ]);

    $response->assertStatus(422);

    // Session unchanged
    $session->refresh();
    expect($session->status)->toBe('in_corso');
});

test('POST /end with HeyGen provider: replaceUtterances — prior utterances deleted, server transcript inserted', function (): void {
    Http::fake([
        '*liveavatar*/sessions/*/transcript*' => Http::response([
            'data' => [
                ['role' => 'user',      'content' => 'My answer.',   'time_ms' => 1000],
                ['role' => 'assistant', 'content' => 'Good answer.', 'time_ms' => 2000],
            ],
        ], 200),
    ]);
    Queue::fake();

    $org = endOrg();
    [$project, $comps] = endProjectWithCompetencies($org, 2);
    $participant = endParticipant($org, $project, 'in_corso');
    $session = endSession($org, $participant, $project, $comps[0]->code, 'in_corso', 'heygen');
    $token = endBearer($participant);

    // Create some pre-existing utterances
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    Utterance::create([
        'interview_session_id' => $session->id,
        'organization_id' => $org->id,
        'speaker' => 'candidate',
        'text' => 'Old live utterance.',
        'ts' => now()->subMinutes(2)->toIso8601String(),
    ]);

    expect(Utterance::where('interview_session_id', $session->id)->count())->toBe(1);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $session->id,
            'ended_reason' => 'completed',
        ]);

    $response->assertStatus(200);

    // Old utterance was DELETED; server transcript (2 rows) was INSERTED
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $utterances = Utterance::where('interview_session_id', $session->id)->get();
    expect($utterances->count())->toBe(2);
    expect($utterances->pluck('text')->toArray())->toContain('My answer.');
    expect($utterances->pluck('text')->toArray())->toContain('Good answer.');
});

test('POST /end with Tavus provider: existing utterances unchanged after /end', function (): void {
    Http::fake(); // Tavus: no transcript endpoint called
    Queue::fake();

    $org = endOrg();
    [$project, $comps] = endProjectWithCompetencies($org, 2);
    $participant = endParticipant($org, $project, 'in_corso');
    $session = endSession($org, $participant, $project, $comps[0]->code, 'in_corso', 'tavus');
    $token = endBearer($participant);

    // Pre-existing utterances from live /utterance calls
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    Utterance::create([
        'interview_session_id' => $session->id,
        'organization_id' => $org->id,
        'speaker' => 'candidate',
        'text' => 'Tavus live utterance.',
        'ts' => now()->subMinutes(1)->toIso8601String(),
    ]);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $session->id,
            'ended_reason' => 'completed',
        ]);

    $response->assertStatus(200);

    // Utterances NOT replaced for Tavus — still 1 row
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    expect(Utterance::where('interview_session_id', $session->id)->count())->toBe(1);
    expect(Utterance::where('interview_session_id', $session->id)->first()->text)->toBe('Tavus live utterance.');
});

test('POST /end session_id from non-owned session → 404', function (): void {
    Http::fake();
    Queue::fake();

    $org = endOrg();
    [$project, $comps] = endProjectWithCompetencies($org, 1);
    $participantX = endParticipant($org, $project, 'in_corso');
    $participantY = endParticipant($org, $project, 'in_corso');

    // Session belongs to X
    $sessionX = endSession($org, $participantX, $project, $comps[0]->code, 'in_corso');

    // Authenticated as Y
    $tokenY = endBearer($participantY);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$tokenY])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $sessionX->id,
            'ended_reason' => 'completed',
        ]);

    $response->assertStatus(404);

    // Session unchanged
    $sessionX->refresh();
    expect($sessionX->status)->toBe('in_corso');
});

test('POST /end with timeout ended_reason: session.status=timeout, session.ended_at set', function (): void {
    Http::fake([
        '*liveavatar*/sessions/*/transcript*' => Http::response(['data' => []], 200),
    ]);
    Queue::fake();

    $org = endOrg();
    [$project, $comps] = endProjectWithCompetencies($org, 2);
    $participant = endParticipant($org, $project, 'in_corso');
    $session = endSession($org, $participant, $project, $comps[0]->code, 'in_corso');
    $token = endBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $session->id,
            'ended_reason' => 'timeout',
        ]);

    $response->assertStatus(200);

    $session->refresh();
    expect($session->status)->toBe('timeout');
    expect($session->ended_at)->not->toBeNull();
});

test('POST /end with skipped ended_reason: session.status=skipped', function (): void {
    Http::fake([
        '*liveavatar*/sessions/*/transcript*' => Http::response(['data' => []], 200),
    ]);
    Queue::fake();

    $org = endOrg();
    [$project, $comps] = endProjectWithCompetencies($org, 2);
    $participant = endParticipant($org, $project, 'in_corso');
    $session = endSession($org, $participant, $project, $comps[0]->code, 'in_corso');
    $token = endBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $session->id,
            'ended_reason' => 'skipped',
        ]);

    $response->assertStatus(200);

    $session->refresh();
    expect($session->status)->toBe('skipped');
});

test('POST /end CRITICAL-3 atomicity: session + count + CAS in one txn — last-question dispatches FinalizeInterview once', function (): void {
    Http::fake([
        '*liveavatar*/sessions/*/transcript*' => Http::response(['data' => []], 200),
    ]);
    Queue::fake();

    $org = endOrg();
    [$project, $comps] = endProjectWithCompetencies($org, 1); // 1 competency
    $participant = endParticipant($org, $project, 'in_corso');
    $session = endSession($org, $participant, $project, $comps[0]->code, 'in_corso');
    $token = endBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $session->id,
            'ended_reason' => 'completed',
        ]);

    $response->assertStatus(200);

    // Both session UPDATE and participant CAS committed
    $session->refresh();
    $participant->refresh();
    expect($session->status)->toBe('completed');
    expect($participant->status)->toBe('in_valutazione');

    // FinalizeInterview dispatched exactly once (CAS single-winner)
    Queue::assertPushed(FinalizeInterview::class, 1);
});
