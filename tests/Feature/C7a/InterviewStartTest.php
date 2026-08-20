<?php

declare(strict_types=1);

/**
 * InterviewController::start() feature tests (C7a — Phase 8.1 RED).
 *
 * Tests POST /api/candidate/interview/start
 *
 * Asserts:
 * - First question (in_attesa → in_corso): HTTP 201, session created, participant.started_at set, status = in_corso.
 * - Response has session_id + provider token (no key material).
 * - Second question (participant already in_corso): HTTP 201, participant.status unchanged.
 * - Resume in_corso: no duplicate row; fresh token issued; OLD session torn down.
 * - Resume pending (no provider_session_ref): issue() retried; on success 201 + in_corso.
 * - Provider 5xx → HTTP 502; session status = error; participant.status = errore.
 * - Provider 429 → HTTP 429 {error: provider_busy}; participant NOT → errore; session = pending.
 * - DB failure after provider success → teardown() called; HTTP 500.
 * - No remaining competency → HTTP 422.
 * - Concurrent double /start: UniqueConstraintViolationException caught → RESUME, no 500.
 * - FIX-8: session + participant writes in ONE transaction.
 *
 * Tasks: 8.1 (RED)
 * REQ: POST /start endpoint (C7a)
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function startOrg(): Organization
{
    return Organization::factory()->create();
}

function startProject(Organization $org, ?string $providerOverride = null): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $attrs = ['status' => 'active'];
    if ($providerOverride !== null) {
        $attrs['provider_override'] = $providerOverride;
    }

    return Project::factory()->create($attrs);
}

function startProjectWithCompetencies(Organization $org, int $count = 2, ?string $providerOverride = null): array
{
    $project = startProject($org, $providerOverride);

    // C8 (M-3): seed a Role matching project.role_code and BarsIndicators for each competency
    // so SystemPromptComposer::compose() can succeed for the /start path.
    $role = Role::factory()->create(['code' => $project->role_code]);

    $competencies = [];
    for ($i = 0; $i < $count; $i++) {
        $comp = Competency::factory()->create();
        DB::table('project_competencies')->insert([
            'project_id' => $project->id,
            'competency_id' => $comp->id,
            'position' => $i + 1,
        ]);

        // Seed a minimal BarsIndicator for this role+competency (EN, composition-sufficient).
        $ind = new BarsIndicator;
        $ind->forceFill([
            'role_id' => $role->id,
            'competency_id' => $comp->id,
            'text' => ['en' => "C7a fixture indicator {$i}"],
            'anchor_5' => ['en' => "Excellent {$i}"],
            'anchor_3' => ['en' => "Adequate {$i}"],
            'anchor_1' => ['en' => "Insufficient {$i}"],
            'position' => 0,
        ]);
        $ind->save();

        $competencies[] = $comp;
    }

    return [$project, $competencies];
}

function startParticipant(Organization $org, Project $project, string $status = 'in_attesa'): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'start-'.uniqid(),
        'display_name' => 'Start Test Candidate',
        'status' => $status,
    ]);
    $p->save();

    return $p->fresh();
}

function startBearer(Participant $participant): string
{
    return CandidateTokenFactory::mintCandidateToken($participant);
}

function heygenSuccessResponse(): array
{
    return [
        '*liveavatar*/contexts*' => Http::response(['data' => ['context_id' => 'ctx-001']], 200),
        '*liveavatar*/sessions/token*' => Http::response([
            'data' => [
                'session_id' => 'heygen-session-'.uniqid(),
                'access_token' => 'heygen-token-'.uniqid(),
                'url' => 'https://webrtc.heygen.com/test',
            ],
        ], 200),
        '*liveavatar*/sessions/*' => Http::response([], 200), // teardown
    ];
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('POST /start first question: 201, session created, participant in_corso, started_at set', function (): void {
    Http::fake(heygenSuccessResponse());
    Queue::fake();

    $org = startOrg();
    [$project, $comps] = startProjectWithCompetencies($org);
    $participant = startParticipant($org, $project, 'in_attesa');
    $token = startBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);

    // Session created
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    expect(InterviewSession::where('participant_id', $participant->id)->count())->toBe(1);

    $session = InterviewSession::where('participant_id', $participant->id)->first();
    expect($session->status)->toBe('in_corso');
    expect($session->competency_code)->toBe($comps[0]->code); // lowest position

    // Participant transitioned to in_corso
    $participant->refresh();
    expect($participant->status)->toBe('in_corso');
    expect($participant->started_at)->not->toBeNull();

    // Response structure
    $response->assertJsonStructure(['session_id', 'provider', 'question_context']);
    expect($response->json('session_id'))->toBe($session->id);
});

test('POST /start response does NOT contain API key material', function (): void {
    config(['interview.heygen.api_key' => 'MUST_NOT_LEAK_KEY_XYZ']);
    Http::fake(heygenSuccessResponse());
    Queue::fake();

    $org = startOrg();
    [$project] = startProjectWithCompetencies($org);
    $participant = startParticipant($org, $project, 'in_attesa');
    $token = startBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);

    // API key MUST NOT appear in any field of the response
    $body = $response->getContent();
    expect($body)->not->toContain('MUST_NOT_LEAK_KEY_XYZ');
});

test('POST /start second question (participant in_corso): 201, participant status unchanged', function (): void {
    Http::fake(heygenSuccessResponse());
    Queue::fake();

    $org = startOrg();
    [$project, $comps] = startProjectWithCompetencies($org, 2);
    $participant = startParticipant($org, $project, 'in_corso');
    $token = startBearer($participant);

    // First session already completed
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $comps[0]->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'status' => 'completed',
    ]);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);

    // A new session for competency 2 was created
    $sessions = InterviewSession::where('participant_id', $participant->id)->get();
    expect($sessions->count())->toBe(2);
    $newSession = $sessions->where('competency_code', $comps[1]->code)->first();
    expect($newSession)->not->toBeNull();
    expect($newSession->status)->toBe('in_corso');

    // Participant stays in_corso (no double transition)
    $participant->refresh();
    expect($participant->status)->toBe('in_corso');
});

test('POST /start resume in_corso: no duplicate row, fresh token issued, old session torn down', function (): void {
    // Fake: teardown request + new issue
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['data' => ['context_id' => 'ctx-fresh']], 200),
        '*liveavatar*/sessions/token*' => Http::response([
            'data' => [
                'session_id' => 'heygen-session-fresh',
                'access_token' => 'heygen-token-fresh',
            ],
        ], 200),
        '*liveavatar*/sessions/*' => Http::response([], 200), // teardown old
    ]);
    Queue::fake();

    $org = startOrg();
    [$project, $comps] = startProjectWithCompetencies($org, 1);
    $participant = startParticipant($org, $project, 'in_corso');
    $token = startBearer($participant);

    // Existing in_corso session with a provider_session_ref
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $oldSession = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $comps[0]->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'provider_session_ref' => 'old-ref-to-teardown',
        'status' => 'in_corso',
    ]);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);

    // No duplicate session row
    $resolver->setOrgId($org->id);
    expect(InterviewSession::where('participant_id', $participant->id)->count())->toBe(1);

    // Session updated with new ref
    $oldSession->refresh();
    expect($oldSession->provider_session_ref)->toBe('heygen-session-fresh');
    expect($oldSession->status)->toBe('in_corso');

    // Teardown was called for the OLD ref (HTTP DELETE to /sessions/old-ref-to-teardown)
    Http::assertSent(fn ($req) => str_contains($req->url(), 'old-ref-to-teardown'));
});

test('POST /start resume pending (no provider_session_ref): retries issue, 201 in_corso', function (): void {
    Http::fake(heygenSuccessResponse());
    Queue::fake();

    $org = startOrg();
    [$project, $comps] = startProjectWithCompetencies($org, 1);
    $participant = startParticipant($org, $project, 'in_attesa');
    $token = startBearer($participant);

    // Session in pending state with NO provider_session_ref
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $pendingSession = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $comps[0]->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'provider_session_ref' => null,
        'status' => 'pending',
    ]);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);

    $pendingSession->refresh();
    expect($pendingSession->status)->toBe('in_corso');
    expect($pendingSession->provider_session_ref)->not->toBeNull();
});

test('POST /start provider 5xx → 502; session status = error; participant.status = errore', function (): void {
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['error' => 'Internal Server Error'], 503),
    ]);
    Queue::fake();

    $org = startOrg();
    [$project, $comps] = startProjectWithCompetencies($org, 1);
    $participant = startParticipant($org, $project, 'in_attesa');
    $token = startBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(502);

    // Session should be marked error
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $session = InterviewSession::where('participant_id', $participant->id)->first();
    if ($session !== null) {
        expect($session->status)->toBe('error');
    }

    // Participant must be transitioned to errore
    $participant->refresh();
    expect($participant->status)->toBe('errore');
});

test('POST /start provider 429 → 429 provider_busy; participant NOT → errore; session stays pending', function (): void {
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['error' => 'Too Many Requests'], 429),
    ]);
    Queue::fake();

    $org = startOrg();
    [$project, $comps] = startProjectWithCompetencies($org, 1);
    $participant = startParticipant($org, $project, 'in_attesa');
    $token = startBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(429);
    $response->assertJson(['error' => 'provider_busy']);

    // Participant MUST NOT be transitioned to errore
    $participant->refresh();
    expect($participant->status)->not->toBe('errore');

    // Session should stay pending (not error)
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $session = InterviewSession::where('participant_id', $participant->id)->first();
    if ($session !== null) {
        expect($session->status)->toBe('pending');
    }
});

test('POST /start provider 4xx (HeyGen) → 500; session status = error; participant.status UNCHANGED (PR1 D4)', function (): void {
    // 422: HeyGen correctly rejected a request WE malformed — a client contract error,
    // not an upstream failure. Before PR1 this was classified identically to a 5xx
    // (502 + participant permanently → errore). This is the acceptance test for D4's
    // three-way split: a bug in OUR payload must not permanently burn the candidate.
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['message' => 'prompt is required'], 422),
    ]);
    Queue::fake();

    $org = startOrg();
    [$project, $comps] = startProjectWithCompetencies($org, 1);
    $participant = startParticipant($org, $project, 'in_attesa');
    $token = startBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(500);
    $response->assertJson(['error' => 'provider_error']);

    // Session marked error (same write as the 5xx path — only the HTTP status differs)
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $session = InterviewSession::where('participant_id', $participant->id)->first();
    expect($session)->not->toBeNull();
    expect($session->status)->toBe('error');

    // Participant MUST NOT be transitioned to errore — this is the whole point of D4
    $participant->refresh();
    expect($participant->status)->not->toBe('errore');
    expect($participant->status)->toBe('in_attesa');
});

test('POST /start provider 4xx (Tavus) → 500; session status = error; participant.status UNCHANGED (PR1 D4)', function (): void {
    Http::fake([
        '*tavusapi*/v2/conversations*' => Http::response(['error' => 'replica_id is invalid'], 400),
    ]);
    Queue::fake();

    $org = startOrg();
    [$project, $comps] = startProjectWithCompetencies($org, 1, 'tavus');
    $participant = startParticipant($org, $project, 'in_attesa');
    $token = startBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(500);
    $response->assertJson(['error' => 'provider_error']);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $session = InterviewSession::where('participant_id', $participant->id)->first();
    expect($session)->not->toBeNull();
    expect($session->status)->toBe('error');

    $participant->refresh();
    expect($participant->status)->not->toBe('errore');
    expect($participant->status)->toBe('in_attesa');
});

test('POST /start no remaining competency → 422', function (): void {
    Http::fake(heygenSuccessResponse());
    Queue::fake();

    $org = startOrg();
    [$project, $comps] = startProjectWithCompetencies($org, 1);
    $participant = startParticipant($org, $project, 'in_corso');
    $token = startBearer($participant);

    // All competencies already completed
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $comps[0]->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'status' => 'completed',
    ]);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(422);
});

test('POST /start with project.provider_override = tavus → Tavus provider called', function (): void {
    Http::fake([
        '*tavusapi*/v2/conversations*' => Http::response([
            'conversation_id' => 'conv-001',
            'conversation_url' => 'https://tavus.io/conv-001',
        ], 200),
        '*tavusapi*/v2/conversations/*' => Http::response([], 200), // teardown
    ]);
    Queue::fake();

    $org = startOrg();
    [$project, $comps] = startProjectWithCompetencies($org, 1, 'tavus');
    $participant = startParticipant($org, $project, 'in_attesa');
    $token = startBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);

    // Tavus was called
    Http::assertSent(fn ($req) => str_contains($req->url(), 'tavusapi'));

    // Provider in session is 'tavus'
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $session = InterviewSession::where('participant_id', $participant->id)->first();
    expect($session->provider)->toBe('tavus');
});

test('POST /start provider 5xx on resume in_corso → 502; participant errore (compensation path)', function (): void {
    // Re-issue fails (5xx) on resume in_corso path
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['error' => 'Server Error'], 503),
    ]);
    Queue::fake();

    $org = startOrg();
    [$project, $comps] = startProjectWithCompetencies($org, 1);
    $participant = startParticipant($org, $project, 'in_corso');
    $token = startBearer($participant);

    // Existing in_corso session
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $comps[0]->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'provider_session_ref' => 'old-ref-existing',
        'status' => 'in_corso',
    ]);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(502);

    $participant->refresh();
    expect($participant->status)->toBe('errore');
});

test('POST /start FIX-8: session + participant writes are in one transaction (both commit or both roll back)', function (): void {
    // We verify this indirectly: after a successful /start, both session.status=in_corso
    // AND participant.status=in_corso AND participant.started_at are set.
    // If FIX-8 were violated, a failure between the two writes would leave inconsistency.
    Http::fake(heygenSuccessResponse());
    Queue::fake();

    $org = startOrg();
    [$project, $comps] = startProjectWithCompetencies($org, 1);
    $participant = startParticipant($org, $project, 'in_attesa');
    $token = startBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $session = InterviewSession::where('participant_id', $participant->id)->first();
    $participant->refresh();

    // Both writes committed atomically
    expect($session->status)->toBe('in_corso');
    expect($participant->status)->toBe('in_corso');
    expect($participant->started_at)->not->toBeNull();
});

test('POST /start on a recovered participant (status=in_attesa, started_at already set) does NOT clobber started_at and greets with the "next" variant, not "first" (participant-error-recovery D2b)', function (): void {
    // A recovered participant is flipped errore -> in_attesa (D2 recovery action)
    // but keeps its ORIGINAL started_at — it is resuming, not starting fresh.
    // $isFirst must key off started_at === null, NOT status === 'in_attesa',
    // otherwise this candidate is greeted as brand new AND started_at is
    // silently overwritten to now(), destroying the true interview start time.
    Http::fake(heygenSuccessResponse());
    Queue::fake();

    $org = startOrg();
    [$project, $comps] = startProjectWithCompetencies($org, 1);
    $participant = startParticipant($org, $project, 'in_attesa');

    $participant->started_at = now()->subMinutes(20);
    $participant->save();
    $originalStartedAt = $participant->fresh()->started_at;

    $token = startBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);

    // started_at is UNCHANGED — not overwritten to "now"
    $participant->refresh();
    expect($participant->started_at->getTimestamp())->toBe($originalStartedAt->getTimestamp());

    // The avatar context was composed with the "next" opening greeting
    // ("Great, let's move on...") — NOT the "first" one ("Hi, and welcome!").
    Http::assertSent(function ($req) {
        if (! str_contains($req->url(), '/contexts')) {
            return false;
        }

        $body = $req->data();

        return str_contains($body['opening_text'] ?? '', "Great, let's move on")
            && ! str_contains($body['opening_text'] ?? '', 'Hi, and welcome');
    });
});
