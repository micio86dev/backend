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

use App\Models\AvatarTemplate;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ProjectQuestion;
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
        // PIN a template of that provider, rather than setting
        // `provider_override`.
        //
        // `projects.avatar_template_id` is required and the pinned template
        // decides the provider, so `provider_override` sits below something
        // that is now always present and can never be reached. A test still
        // setting it would start a HeyGen interview against a Tavus fake — a
        // 500 that looks like a provider bug and is really a stale premise.
        //
        // The parameter name is kept: what these callers mean is "run this
        // project on that provider", and that intent is unchanged.
        $attrs['provider_override'] = $providerOverride;
        $attrs['avatar_template_id'] = AvatarTemplate::create([
            'name' => 'Start '.$providerOverride.' '.uniqid(),
            'provider' => $providerOverride,
            'config' => [],
        ])->id;
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
            // 0-based, matching every production writer (project_competencies.position
            // comes from a PHP array key). This fixture was 1-based, which cancelled
            // the `position - 1` defect exactly and is why an endpoint-driven test
            // passed against wrong code for months. A fixture that disagrees with
            // production does not simplify a test, it disarms it.
            'position' => $i,
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

        // framework-catalogue-authoring PR6 (D5) — `/start` now refuses a
        // project where a selected competency has zero live
        // `project_questions` rows.
        ProjectQuestion::create([
            'project_id' => $project->id,
            'competency_id' => $comp->id,
            'text' => ['en' => "C7a fixture question {$i}"],
            'position' => 0,
        ]);

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
        'email' => uniqid('cand-').'@example.test',
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
                'session_token' => 'heygen-token-'.uniqid(),
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
                'session_token' => 'heygen-token-fresh',
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

    // Teardown was called for the OLD ref: `POST /v1/sessions/stop` with the ref in the
    // JSON body `session_id` (the ref is NOT in the URL under the real wire contract).
    Http::assertSent(fn ($req) => $req->method() === 'POST'
        && parse_url($req->url(), PHP_URL_PATH) === '/v1/sessions/stop'
        && ($req->data()['session_id'] ?? null) === 'old-ref-to-teardown');
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

test('POST /start provider 429 on a SUBSEQUENT (in_corso-origin) competency → 429 provider_busy; participant stays in_corso (participant-error-recovery regression guard)', function (): void {
    // Extends the in_attesa-origin 429 test above to the in_corso origin —
    // the SAME ClientError/Throttle-never-marks-the-participant guarantee
    // must also hold for a candidate already mid-interview (already-shipped
    // liveavatar-contract-alignment behavior; pinned here against silent
    // regression per the participant-error-recovery task list).
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['error' => 'Too Many Requests'], 429),
    ]);
    Queue::fake();

    $org = startOrg();
    [$project, $comps] = startProjectWithCompetencies($org, 2);
    $participant = startParticipant($org, $project, 'in_corso');
    $token = startBearer($participant);

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

    $response->assertStatus(429);
    $response->assertJson(['error' => 'provider_busy']);

    $participant->refresh();
    expect($participant->status)->toBe('in_corso');
    expect($participant->status)->not->toBe('errore');

    $resolver->setOrgId($org->id);
    $session = InterviewSession::where('participant_id', $participant->id)
        ->where('competency_code', $comps[1]->code)
        ->first();
    expect($session)->not->toBeNull();
    expect($session->status)->toBe('pending');
});

test('POST /start provider 4xx on a SUBSEQUENT (in_corso-origin) competency → 500; session status = error; participant.status UNCHANGED (participant-error-recovery regression guard)', function (): void {
    // Extends the in_attesa-origin 4xx test below to the in_corso origin —
    // same D4 guarantee, already-shipped behavior, pinned against regression.
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['message' => 'prompt is required'], 422),
    ]);
    Queue::fake();

    $org = startOrg();
    [$project, $comps] = startProjectWithCompetencies($org, 2);
    $participant = startParticipant($org, $project, 'in_corso');
    $token = startBearer($participant);

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

    $response->assertStatus(500);
    $response->assertJson(['error' => 'provider_error']);

    $resolver->setOrgId($org->id);
    $session = InterviewSession::where('participant_id', $participant->id)
        ->where('competency_code', $comps[1]->code)
        ->first();
    expect($session)->not->toBeNull();
    expect($session->status)->toBe('error');

    $participant->refresh();
    expect($participant->status)->not->toBe('errore');
    expect($participant->status)->toBe('in_corso');
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

// With the gate off, /start must proceed on a zero-question competency
// exactly as it did before the gate existed — never crash on the empty
// `primary_questions` array `composePromptForCompetency()` then composes.
test('POST /start with the interviewability gate OFF succeeds on a question-less competency, matching pre-gate behaviour', function (): void {
    config(['interview.interviewability_gate' => false]);
    Http::fake(heygenSuccessResponse());
    Queue::fake();

    $org = startOrg();
    $project = startProject($org);
    $role = Role::factory()->create(['code' => $project->role_code]);
    $comp = Competency::factory()->create();
    DB::table('project_competencies')->insert(['project_id' => $project->id, 'competency_id' => $comp->id, 'position' => 0]);

    $ind = new BarsIndicator;
    $ind->forceFill([
        'role_id' => $role->id,
        'competency_id' => $comp->id,
        'text' => ['en' => 'gate-off fixture indicator'],
        'anchor_5' => ['en' => 'Excellent'],
        'anchor_3' => ['en' => 'Adequate'],
        'anchor_1' => ['en' => 'Insufficient'],
        'position' => 0,
    ]);
    $ind->save();
    // Deliberately NO ProjectQuestion row — the gate-off scenario.

    $participant = startParticipant($org, $project, 'in_attesa');
    $token = startBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);
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

    // The avatar context was composed with the "next" opening greeting —
    // NOT the "first" one ("Hi, and welcome!"). Asserted via the AUTHORED
    // question text now (framework-catalogue-authoring PR6, D5 — every
    // fixture competency carries one live `project_questions` row, which
    // `OpeningTextComposer` returns verbatim for `first`/`next` rather than
    // the generic "Great, let's move on..." template): the "next" variant
    // still shows through by the ABSENCE of the "first" variant's own text.
    Http::assertSent(function ($req) {
        if (! str_contains($req->url(), '/contexts')) {
            return false;
        }

        $body = $req->data();

        return str_contains($body['opening_text'] ?? '', 'C7a fixture question 0')
            && ! str_contains($body['opening_text'] ?? '', 'Hi, and welcome');
    });
});

// ─── audio_only (voice-only templates) ───────────────────────────────────────

/**
 * The candidate app cannot render a voice-only interview it is never told about.
 *
 * `audioOnly` is a template knob that reaches the PROVIDER (TemplatePayload maps
 * it to Tavus's `audio_only`) and stopped there. The browser kept mounting the
 * avatar onto a `<video>` element that receives a stream with no video track,
 * and painted an undecoded frame — the green-and-black vertical banding a
 * candidate actually saw where a face should be.
 *
 * Machine-facing, so the field name and the boolean are literal in every
 * locale. It carries no vendor identity, which is why it is safe to hand a
 * candidate at all (`tests/unit/provider-anonymity.spec.ts` on the client side
 * holds the rest of that line).
 */
function tavusSuccessResponse(): array
{
    return [
        '*tavusapi*/v2/conversations*' => Http::response([
            'conversation_id' => 'conv-audio-only',
            'conversation_url' => 'https://tavus.io/conv-audio-only',
        ], 200),
        '*tavusapi*/v2/conversations/*' => Http::response([], 200), // teardown
    ];
}

function startProjectWithAudioOnly(Organization $org, bool $audioOnly): array
{
    [$project, $comps] = startProjectWithCompetencies($org, 2, 'tavus');

    AvatarTemplate::whereKey($project->avatar_template_id)
        ->update(['config' => json_encode(['audioOnly' => $audioOnly])]);

    return [$project, $comps];
}

test('POST /start reports audio_only=true when the pinned template is voice-only', function (): void {
    Http::fake(tavusSuccessResponse());
    Queue::fake();

    $org = startOrg();
    [$project] = startProjectWithAudioOnly($org, true);
    $participant = startParticipant($org, $project, 'in_attesa');

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.startBearer($participant)])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);
    $response->assertJsonPath('audio_only', true);
});

test('POST /start reports audio_only=false for a normal template', function (): void {
    Http::fake(tavusSuccessResponse());
    Queue::fake();

    $org = startOrg();
    [$project] = startProjectWithAudioOnly($org, false);
    $participant = startParticipant($org, $project, 'in_attesa');

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.startBearer($participant)])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);
    $response->assertJsonPath('audio_only', false);
});

test('POST /start reports audio_only=false when the template says nothing about it', function (): void {
    // An explicit boolean, never an absent key. A client branching on
    // `undefined` treats "not configured" and "not audio-only" alike today and
    // starts hiding the avatar the day the field is renamed.
    Http::fake(tavusSuccessResponse());
    Queue::fake();

    $org = startOrg();
    [$project] = startProjectWithCompetencies($org, 2, 'tavus');
    $participant = startParticipant($org, $project, 'in_attesa');

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.startBearer($participant)])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);
    $response->assertJsonPath('audio_only', false);
});

// ─── gga finding 1/8 (public-api step 6): InterviewEventRecorder must not abort the caller's transaction ──

test('POST /start still transitions the participant to in_corso even when the InterviewEvent write fails (Postgres transaction abort, not just a swallowed exception)', function (): void {
    Http::fake(heygenSuccessResponse());
    Queue::fake();

    $org = startOrg();
    [$project] = startProjectWithCompetencies($org);
    $participant = startParticipant($org, $project, 'in_attesa');
    $token = startBearer($participant);

    // `InterviewEventRecorder::sessionStarted()` is the LAST statement in
    // `handleIssuePending()`'s own `DB::transaction()` closure — nothing
    // runs after it to surface an aborted connection as a 500. Instead,
    // Postgres silently treats COMMIT on an aborted transaction as a
    // ROLLBACK: without a savepoint, the response still reports 201, but
    // NONE of the writes in that transaction (participant.status,
    // participant.started_at, the InterviewSession row) were actually
    // persisted — the exact "silently commits nothing" defect.
    DB::statement('ALTER TABLE interview_events RENAME TO interview_events_hidden_for_test');

    try {
        $response = $this
            ->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/candidate/interview/start');

        $response->assertStatus(201);

        $participant->refresh();
        expect($participant->status)->toBe('in_corso');
        expect($participant->started_at)->not->toBeNull();

        $resolver = app(TenantResolver::class);
        $resolver->setOrgId($org->id);
        $resolver->setBypass(false);
        expect(InterviewSession::where('participant_id', $participant->id)->count())->toBe(1);
    } finally {
        DB::statement('ALTER TABLE interview_events_hidden_for_test RENAME TO interview_events');
    }
});

// ─── Pre-flight (provider configuration validated BEFORE any provider call) ──

/**
 * A project pinned to a template of `$provider` carrying `$config`, written
 * straight to the row so that even a config the save endpoint would refuse
 * (legacy data, an inventory that changed since) can be exercised.
 *
 * @param  array<string, mixed>  $config
 * @return array{0: Organization, 1: Participant, 2: string, 3: AvatarTemplate}
 */
function preflightScenario(string $provider, array $config): array
{
    $org = startOrg();
    [$project] = startProjectWithCompetencies($org, 1, $provider);

    $template = AvatarTemplate::findOrFail($project->avatar_template_id);
    $template->config = $config;
    $template->save();

    $participant = startParticipant($org, $project, 'in_attesa');

    return [$org, $participant, startBearer($participant), $template];
}

function tavusInventoryFake(array $pals = ['p_ok'], array $faces = ['f_ok']): array
{
    return [
        'tavusapi.com/v2/faces*' => Http::response(['data' => array_map(fn (string $id): array => ['face_id' => $id, 'face_name' => $id, 'status' => 'completed'], $faces), 'total_count' => count($faces)], 200),
        'tavusapi.com/v2/pals*' => Http::response(['data' => array_map(fn (string $id): array => ['pal_id' => $id, 'pal_name' => $id], $pals), 'total_count' => count($pals)], 200),
        'tavusapi.com/v2/conversations' => Http::response(['conversation_id' => 'c1', 'conversation_url' => 'https://tavus.daily.co/c1'], 200),
    ];
}

test('pre-flight: a Tavus palId that is not a PAL is refused with 422 and NO provider call — the former 500 provider_error', function (): void {
    config(['interview.preflight.verify_references' => true]);
    Http::fake(tavusInventoryFake());
    Queue::fake();

    // A VOICE id in palId: what the old voice-backed picker stored.
    [$org, $participant, $token] = preflightScenario('tavus', ['faceId' => 'f_ok', 'palId' => 'v_a_voice_id']);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/candidate/interview/start');

    $response->assertStatus(422)
        ->assertExactJson([
            'error' => 'interview_configuration_invalid',
            'reasons' => [['key' => 'palId', 'code' => 'pal_not_found']],
        ]);

    Http::assertNotSent(fn ($r): bool => str_contains($r->url(), '/v2/conversations'));

    // Nothing was written: no session, the participant is untouched and can retry.
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    expect(InterviewSession::where('participant_id', $participant->id)->count())->toBe(0);
    expect($participant->fresh()->status)->toBe('in_attesa');
});

test('pre-flight: a valid Tavus configuration reaches the provider with the chosen face and persona', function (): void {
    config(['interview.preflight.verify_references' => true]);
    Http::fake(tavusInventoryFake());
    Queue::fake();

    [, , $token] = preflightScenario('tavus', ['faceId' => 'f_ok', 'palId' => 'p_ok']);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/candidate/interview/start')->assertStatus(201);

    Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/v2/conversations')
        && $r['replica_id'] === 'f_ok'
        && $r['persona_id'] === 'p_ok');
});

test('pre-flight: refuses each invalid configuration with its own code', function (string $provider, array $config, array $reasons, array $settings): void {
    config(['interview.preflight.verify_references' => true] + $settings);
    Http::fake(tavusInventoryFake() + [
        'api.liveavatar.com/v1/avatars*' => Http::response(['data' => ['next' => null, 'results' => [['id' => 'av_ok', 'name' => 'A', 'status' => 'ACTIVE']]]], 200),
        'api.liveavatar.com/v1/voices*' => Http::response(['data' => ['next' => null, 'results' => [['id' => 'vo_ok', 'name' => 'V', 'language' => 'it']]]], 200),
        'api.cartesia.ai/voices*' => Http::response(['data' => [['id' => 'c_ok', 'name' => 'C', 'language' => 'it']], 'has_more' => false], 200),
    ]);
    Queue::fake();

    [, , $token] = preflightScenario($provider, $config);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(422)
        ->assertJsonPath('error', 'interview_configuration_invalid')
        ->assertJsonPath('reasons', $reasons);
})->with([
    'tavus: unknown face' => ['tavus', ['faceId' => 'f_x', 'palId' => 'p_ok'], [['key' => 'faceId', 'code' => 'avatar_not_found']], []],
    'tavus: engine without a voice' => ['tavus', ['faceId' => 'f_ok', 'palId' => 'p_ok', 'ttsEngine' => 'cartesia'], [['key' => 'ttsExternalVoiceId', 'code' => 'tts_voice_required']], ['services.cartesia.api_key' => 'k']],
    'tavus: voice without an engine' => ['tavus', ['faceId' => 'f_ok', 'palId' => 'p_ok', 'ttsExternalVoiceId' => 'c_ok'], [['key' => 'ttsEngine', 'code' => 'tts_engine_required']], []],
    'tavus: voice unknown at the vendor' => ['tavus', ['faceId' => 'f_ok', 'palId' => 'p_ok', 'ttsEngine' => 'cartesia', 'ttsExternalVoiceId' => 'c_x'], [['key' => 'ttsExternalVoiceId', 'code' => 'tts_voice_not_found']], ['services.cartesia.api_key' => 'k']],
    'tavus: platform key missing' => ['tavus', ['faceId' => 'f_ok', 'palId' => 'p_ok'], [['key' => 'credentials', 'code' => 'provider_key_missing']], ['interview.tavus.api_key' => '']],
    'heygen: unknown avatar' => ['heygen', ['avatarId' => 'av_x', 'voiceId' => 'vo_ok'], [['key' => 'avatarId', 'code' => 'avatar_not_found']], []],
    'heygen: unknown voice' => ['heygen', ['avatarId' => 'av_ok', 'voiceId' => 'vo_x'], [['key' => 'voiceId', 'code' => 'voice_not_found']], []],
    'heygen: platform key missing' => ['heygen', ['avatarId' => 'av_ok', 'voiceId' => 'vo_ok'], [['key' => 'credentials', 'code' => 'provider_key_missing']], ['interview.heygen.api_key' => '']],
]);

test('pre-flight: a valid HeyGen configuration passes to the provider', function (): void {
    config(['interview.preflight.verify_references' => true]);
    Http::fake([
        'api.liveavatar.com/v1/avatars*' => Http::response(['data' => ['next' => null, 'results' => [['id' => 'av_ok', 'name' => 'A', 'status' => 'ACTIVE']]]], 200),
        'api.liveavatar.com/v1/voices*' => Http::response(['data' => ['next' => null, 'results' => [['id' => 'vo_ok', 'name' => 'V', 'language' => 'it']]]], 200),
    ] + heygenSuccessResponse());
    Queue::fake();

    [, , $token] = preflightScenario('heygen', ['avatarId' => 'av_ok', 'voiceId' => 'vo_ok']);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/candidate/interview/start')->assertStatus(201);

    Http::assertSent(fn ($r): bool => str_contains($r->url(), '/sessions/token')
        && $r['avatar_id'] === 'av_ok'
        && $r['avatar_persona']['voice_id'] === 'vo_ok');
});

test('pre-flight: the kill switch skips reference verification', function (): void {
    config(['interview.preflight.verify_references' => false]);
    Http::fake(tavusInventoryFake());
    Queue::fake();

    [, , $token] = preflightScenario('tavus', ['faceId' => 'f_x', 'palId' => 'p_x']);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/candidate/interview/start')->assertStatus(201);
});

test('pre-flight: the kill switch never skips the credential check', function (): void {
    config(['interview.preflight.verify_references' => false, 'interview.tavus.api_key' => '']);
    Http::fake(tavusInventoryFake());
    Queue::fake();

    [, , $token] = preflightScenario('tavus', ['faceId' => 'f_x', 'palId' => 'p_x']);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/candidate/interview/start')
        ->assertStatus(422)->assertJsonPath('reasons.0.code', 'provider_key_missing');
});

test('pre-flight: a provider outage does not block the start (references fail open)', function (): void {
    config(['interview.preflight.verify_references' => true]);
    Http::fake([
        'tavusapi.com/v2/faces*' => Http::response(['message' => 'down'], 503),
        'tavusapi.com/v2/pals*' => Http::response(['message' => 'down'], 503),
        'tavusapi.com/v2/conversations' => Http::response(['conversation_id' => 'c1', 'conversation_url' => 'https://tavus.daily.co/c1'], 200),
    ]);
    Queue::fake();

    [, , $token] = preflightScenario('tavus', ['faceId' => 'f_ok', 'palId' => 'p_ok']);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/candidate/interview/start')->assertStatus(201);
});

test('pre-flight responses carry no secret and no trace', function (): void {
    config(['interview.preflight.verify_references' => true, 'interview.tavus.api_key' => 'MUST_NOT_LEAK_PREFLIGHT']);
    Http::fake(tavusInventoryFake());
    Queue::fake();

    [, , $token] = preflightScenario('tavus', ['faceId' => 'f_x', 'palId' => 'p_x']);

    $body = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/candidate/interview/start')->assertStatus(422)->getContent();

    expect($body)->not->toContain('MUST_NOT_LEAK_PREFLIGHT')->not->toContain('trace')->not->toContain('exception');
});

// ─── HeyGen context cleanup (heygen-context-cleanup) ─────────────────────────

/**
 * @return array<string, mixed> a HeyGen fake whose /contexts answers with a real `data.id`
 */
function heygenContextFake(string $contextId, string $sessionRef): array
{
    return [
        '*liveavatar*/contexts*' => Http::response(['data' => ['id' => $contextId]], 200),
        '*liveavatar*/sessions/token*' => Http::response([
            'data' => ['session_id' => $sessionRef, 'session_token' => 'tok-'.uniqid()],
        ], 200),
        '*liveavatar*/sessions/stop*' => Http::response([], 200),
    ];
}

test('POST /start persists the HeyGen context id next to the session ref', function (): void {
    Http::fake(heygenContextFake('ctx-persisted', 'sess-persisted'));
    Queue::fake();

    $org = startOrg();
    [$project, $comps] = startProjectWithCompetencies($org, 1);
    $participant = startParticipant($org, $project, 'in_attesa');
    $token = startBearer($participant);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $pending = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $comps[0]->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'status' => 'pending',
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    $pending->refresh();
    expect($pending->provider_session_ref)->toBe('sess-persisted')
        ->and($pending->provider_context_ref)->toBe('ctx-persisted');
});

test('POST /start resume in_corso: stops the old session, THEN deletes its context, and persists the fresh context', function (): void {
    Http::fake(heygenContextFake('ctx-fresh', 'sess-fresh'));
    Queue::fake();

    $org = startOrg();
    [$project, $comps] = startProjectWithCompetencies($org, 1);
    $participant = startParticipant($org, $project, 'in_corso');
    $token = startBearer($participant);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $comps[0]->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'provider_session_ref' => 'old-ref',
        'provider_context_ref' => 'old-ctx',
        'status' => 'in_corso',
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    $calls = Http::recorded()->map(fn (array $pair): string => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH))->values()->all();
    $stop = array_search('POST /v1/sessions/stop', $calls, true);
    $delete = array_search('DELETE /v1/contexts/old-ctx', $calls, true);

    expect($stop)->not->toBeFalse()
        ->and($delete)->not->toBeFalse()
        ->and($stop)->toBeLessThan($delete);

    $session->refresh();
    expect($session->provider_session_ref)->toBe('sess-fresh')
        ->and($session->provider_context_ref)->toBe('ctx-fresh');
});
