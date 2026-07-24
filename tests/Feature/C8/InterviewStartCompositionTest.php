<?php

declare(strict_types=1);

/**
 * RED — Tasks 5.1–5.4 + graceful-degradation (PR2): InterviewController::start() composition wiring (C8 Phase 5).
 *
 * Asserts:
 * (5.1) /start with valid standard competency → 201 + question_context.prompt_version non-null.
 * (5.2) Missing IT anchor translation → 422 anchor_translation_missing; no session; no provider call.
 * (5.3) Empty indicator set for the role → 422 composition_error; no provider call; session pending.
 * (5.4) Provider 5xx failure matrix unchanged after QuestionContext widening → 502 (C7a regression).
 * (5.6) RESUME in_corso + composition fails → 201 (not 422), fresh provider session issued, NO system_prompt in provider body (degraded), warning logged.
 * (5.7) RESUME in_corso + composition succeeds → 201, provider body CONTAINS system_prompt (adaptive resume).
 * (5.8) NEW session + composition fails (regression guard) → still 422, no InterviewSession created, no provider call.
 *
 * Uses Http::fake for all provider calls — no live provider.
 *
 * @group feature
 *
 * Spec: REQ QuestionContext Carries Composed Prompt · REQ i18n hard-fail · REQ Provider Payload Contract.
 * REQ: InterviewController::start() wiring (C8 Phase 5 — M-3)
 * REQ: graceful-degradation on resume in_corso (C8 PR2 resilience fix)
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Seed a standard scenario: Org → Project(role_code = role.code) → Competency → BarsIndicators.
 *
 * The project has ONE competency with 2 EN indicators so SystemPromptComposer can succeed.
 *
 * @return array{org: Organization, project: Project, participant: Participant, competency: Competency, role: Role}
 */
function c8SeedStandardScenario(string $locale = 'en'): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    // Create a Role with a deterministic code
    $roleCode = 'FLL_' . uniqid();
    $role = Role::factory()->create(['code' => $roleCode]);

    // Create competency + link to project via project_competencies
    $competency = Competency::factory()->create(['code' => 'COL_' . uniqid()]);

    // Project with role_code matching the role we created
    $project = Project::factory()->create([
        'status'      => 'active',
        'role_code'   => $roleCode,
        'language'    => $locale,
        'assessment_type' => 'standard',
    ]);

    // Attach competency to project
    DB::table('project_competencies')->insert([
        'project_id'    => $project->id,
        'competency_id' => $competency->id,
        'position'      => 1,
    ]);

    // Seed BARS indicators for this role + competency (EN translations)
    c8SeedIndicators($role->id, $competency->id, 'en', 2);

    // If locale is IT, also seed IT translations (for the positive IT path)
    if ($locale === 'it') {
        c8SeedIndicatorsLocale($role->id, $competency->id, 'it');
    }

    // Participant
    $participant = c8MakeParticipant($org, $project);

    return compact('org', 'project', 'participant', 'competency', 'role');
}

/**
 * Create BarsIndicator rows with EN translations.
 */
function c8SeedIndicators(int $roleId, int $competencyId, string $locale = 'en', int $count = 2): void
{
    for ($i = 0; $i < $count; $i++) {
        $ind = new BarsIndicator;
        $ind->forceFill([
            'role_id'       => $roleId,
            'competency_id' => $competencyId,
            'text'          => [$locale => "Indicator text {$i}"],
            'anchor_5'      => [$locale => "Excellent anchor {$i}"],
            'anchor_3'      => [$locale => "Adequate anchor {$i}"],
            'anchor_1'      => [$locale => "Insufficient anchor {$i}"],
            'position'      => $i,
        ]);
        $ind->save();
    }
}

/**
 * Add a second-locale translation to existing indicators for a role+competency.
 */
function c8SeedIndicatorsLocale(int $roleId, int $competencyId, string $locale): void
{
    $indicators = BarsIndicator::where('role_id', $roleId)
        ->where('competency_id', $competencyId)
        ->get();

    foreach ($indicators as $indicator) {
        $indicator->setTranslation('text', $locale, "Testo indicatore {$indicator->position}");
        $indicator->setTranslation('anchor_5', $locale, "Eccellente {$indicator->position}");
        $indicator->setTranslation('anchor_3', $locale, "Adeguato {$indicator->position}");
        $indicator->setTranslation('anchor_1', $locale, "Insufficiente {$indicator->position}");
        $indicator->save();
    }
}

function c8MakeParticipant(Organization $org, Project $project, string $status = 'in_attesa'): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id'      => $project->id,
        'candidate_ref'   => 'c8-' . uniqid(),
        'display_name'    => 'C8 Test Candidate',
        'status'          => $status,
    ]);
    $p->save();
    return $p->fresh();
}

function c8HeygenFake(): array
{
    return [
        '*liveavatar*/contexts*'       => Http::response(['data' => ['context_id' => 'ctx-c8']], 200),
        '*liveavatar*/sessions/token*' => Http::response([
            'data' => [
                'session_id'   => 'heygen-session-c8',
                'access_token' => 'heygen-token-c8',
            ],
        ], 200),
        '*liveavatar*/sessions/*'      => Http::response([], 200), // teardown
    ];
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('5.1 /start with standard EN competency → 201 + question_context.prompt_version non-null non-empty', function (): void {
    Http::fake(c8HeygenFake());
    Queue::fake();

    $scenario = c8SeedStandardScenario('en');
    $bearer = CandidateTokenFactory::mintCandidateToken($scenario['participant']);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . $bearer])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);

    // prompt_version must be present, non-null, non-empty (M-3)
    $response->assertJsonPath('question_context.prompt_version', fn ($v) => is_string($v) && strlen($v) > 0);
});

test('5.5 /start response never leaks composed system_prompt; provider body carries it (anti-leak)', function (): void {
    Queue::fake();

    // Capture the outbound HeyGen /contexts body while faking the provider.
    $capturedContextBody = [];
    Http::fake(function ($request) use (&$capturedContextBody) {
        if (str_contains($request->url(), '/contexts')) {
            $capturedContextBody = $request->data();
            return Http::response(['data' => ['context_id' => 'ctx-c8']], 200);
        }
        if (str_contains($request->url(), '/sessions/token')) {
            return Http::response(['data' => ['session_id' => 'heygen-session-c8', 'access_token' => 'heygen-token-c8']], 200);
        }
        return Http::response([], 200);
    });

    $scenario = c8SeedStandardScenario('en');
    $bearer = CandidateTokenFactory::mintCandidateToken($scenario['participant']);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . $bearer])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);

    // (a) Response exposes prompt_version for traceability...
    $response->assertJsonPath('question_context.prompt_version', fn ($v) => is_string($v) && strlen($v) > 0);

    // (b) ...but must NEVER contain the composed system prompt or its BARS anchor content.
    //     Leaking the scoring rubric to the candidate being assessed is a data-exposure defect.
    $body = $response->getContent();
    expect($body)->not->toContain('Excellent anchor');
    expect($body)->not->toContain('Indicator text');
    expect($body)->not->toContain('Ask at most');

    // (c) The composed system_prompt MUST still reach the provider server-to-server.
    expect($capturedContextBody)->toHaveKey('system_prompt');
    expect($capturedContextBody['system_prompt'])->toContain('Excellent anchor');
});

test('5.2 /start missing IT anchor translation → 422 anchor_translation_missing; no session; no provider call', function (): void {
    // EN indicators only; project language = it → translation missing → 422
    Http::fake(c8HeygenFake());
    Queue::fake();

    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $roleCode = 'MLL_' . uniqid();
    $role = Role::factory()->create(['code' => $roleCode]);
    $competency = Competency::factory()->create(['code' => 'INN_' . uniqid()]);

    $project = Project::factory()->create([
        'status'          => 'active',
        'role_code'       => $roleCode,
        'language'        => 'it',   // IT locale
        'assessment_type' => 'standard',
    ]);

    DB::table('project_competencies')->insert([
        'project_id'    => $project->id,
        'competency_id' => $competency->id,
        'position'      => 1,
    ]);

    // Only EN indicators — NO Italian translation → AnchorTranslationMissingException
    c8SeedIndicators($role->id, $competency->id, 'en', 2);

    $participant = c8MakeParticipant($org, $project);
    $bearer = CandidateTokenFactory::mintCandidateToken($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . $bearer])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(422);
    $response->assertJsonPath('error', 'anchor_translation_missing');

    // No InterviewSession must be created
    $resolver->setOrgId($org->id);
    expect(InterviewSession::where('participant_id', $participant->id)->count())->toBe(0);

    // No provider call made (nothing sent to liveavatar or tavus)
    Http::assertNothingSent();
});

test('5.3 /start empty indicator set → 422 composition_error; no provider call; session stays pending', function (): void {
    Http::fake(c8HeygenFake());
    Queue::fake();

    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $roleCode = 'BUL_' . uniqid();
    $role = Role::factory()->create(['code' => $roleCode]);
    $competency = Competency::factory()->create(['code' => 'STG_' . uniqid()]);

    $project = Project::factory()->create([
        'status'          => 'active',
        'role_code'       => $roleCode,
        'language'        => 'en',
        'assessment_type' => 'standard',
    ]);

    DB::table('project_competencies')->insert([
        'project_id'    => $project->id,
        'competency_id' => $competency->id,
        'position'      => 1,
    ]);

    // NO BarsIndicators for this role+competency → CompositionException
    // (role exists but has zero indicators for this competency)

    $participant = c8MakeParticipant($org, $project);
    $bearer = CandidateTokenFactory::mintCandidateToken($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . $bearer])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(422);
    $response->assertJsonPath('error', 'composition_error');

    // No provider call made
    Http::assertNothingSent();

    // No InterviewSession created
    $resolver->setOrgId($org->id);
    expect(InterviewSession::where('participant_id', $participant->id)->count())->toBe(0);
});

test('5.4 provider 5xx failure matrix unchanged after QuestionContext widening → 502 (C7a regression)', function (): void {
    // Provider returns 5xx — the failure matrix from C7a must still apply:
    // session → error, participant → errore, HTTP 502
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['error' => 'Internal Server Error'], 503),
    ]);
    Queue::fake();

    $scenario = c8SeedStandardScenario('en');
    $bearer = CandidateTokenFactory::mintCandidateToken($scenario['participant']);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . $bearer])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(502);

    // Participant must be errore (C7a failure matrix invariant)
    $scenario['participant']->refresh();
    expect($scenario['participant']->status)->toBe('errore');

    // Session created (since composition succeeded BEFORE provider call) but marked error
    $scenario['org'];
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($scenario['org']->id);
    $resolver->setBypass(false);
    $session = InterviewSession::where('participant_id', $scenario['participant']->id)->first();
    expect($session)->not->toBeNull();
    expect($session->status)->toBe('error');
});

// ─── PR2 Resilience: Graceful Degradation on RESUME in_corso ─────────────────

/**
 * Seed a RESUME in_corso scenario: project with IT locale and NO IT anchor translations
 * so composition fails, but the participant already has an in_corso session for the competency.
 *
 * @return array{org: Organization, project: Project, participant: Participant, session: InterviewSession}
 */
function c8SeedResumeInCorsoScenario(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $roleCode = 'FLL_resume_' . uniqid();
    $role = Role::factory()->create(['code' => $roleCode]);

    $competency = Competency::factory()->create(['code' => 'COL_resume_' . uniqid()]);

    // Project with IT locale — composition will fail because only EN indicators exist
    $project = Project::factory()->create([
        'status'          => 'active',
        'role_code'       => $roleCode,
        'language'        => 'it',
        'assessment_type' => 'standard',
    ]);

    DB::table('project_competencies')->insert([
        'project_id'    => $project->id,
        'competency_id' => $competency->id,
        'position'      => 1,
    ]);

    // Only EN indicators — no IT translations → AnchorTranslationMissingException on compose
    c8SeedIndicators($role->id, $competency->id, 'en', 2);

    // Participant already in_corso (resume scenario)
    $participant = c8MakeParticipant($org, $project, 'in_corso');

    // Pre-existing in_corso session (this is the session we are resuming)
    $session = InterviewSession::create([
        'participant_id'       => $participant->id,
        'project_id'           => $project->id,
        'question_index'       => 0,
        'competency_code'      => $competency->code,
        'framework_version_id' => $project->framework_version_id,
        'provider'             => 'heygen',
        'provider_session_ref' => 'old-resume-ref-' . uniqid(),
        'status'               => 'in_corso',
    ]);

    return compact('org', 'project', 'participant', 'session');
}

test('5.6 RESUME in_corso + composition fails → 201 (not 422), fresh provider session issued, NO system_prompt in body (degraded), warning logged', function (): void {
    // Capture the outbound HeyGen /contexts body to verify system_prompt is absent (degraded path)
    $capturedContextBody = null;
    Http::fake(function ($request) use (&$capturedContextBody) {
        if (str_contains($request->url(), '/contexts')) {
            // On degraded path the contexts call should NOT happen (no system_prompt → no context)
            // OR if called: body must NOT have system_prompt
            $capturedContextBody = $request->data();
            return Http::response(['data' => ['context_id' => 'ctx-resume-degrade']], 200);
        }
        if (str_contains($request->url(), '/sessions/token')) {
            return Http::response(['data' => ['session_id' => 'heygen-session-resume', 'access_token' => 'heygen-token-resume']], 200);
        }
        // teardown old session
        return Http::response([], 200);
    });
    Queue::fake();

    Log::spy();

    $data = c8SeedResumeInCorsoScenario();
    $bearer = CandidateTokenFactory::mintCandidateToken($data['participant']);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . $bearer])
        ->postJson('/api/candidate/interview/start');

    // Must NOT lock out the candidate (graceful degradation)
    $response->assertStatus(201);

    // Session must still exist and be reused (not a new row)
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($data['org']->id);
    $resolver->setBypass(false);
    expect(InterviewSession::where('participant_id', $data['participant']->id)->count())->toBe(1);

    // Fresh provider session must have been issued (session ref updated)
    $data['session']->refresh();
    expect($data['session']->provider_session_ref)->toBe('heygen-session-resume');
    expect($data['session']->status)->toBe('in_corso');

    // Provider body for /contexts must NOT contain system_prompt (degraded = null systemPrompt → no contexts call, OR contexts called without system_prompt)
    // On degraded path: QuestionContext.systemPrompt=null → HeyGen provider skips the contexts call entirely
    // So either $capturedContextBody is null (no contexts call) OR it has no system_prompt key
    if ($capturedContextBody !== null) {
        expect($capturedContextBody)->not->toHaveKey('system_prompt');
    }

    // A warning must have been logged about the composition failure
    Log::shouldHaveReceived('warning')->once();

    // prompt_version in response must be non-null non-empty (FIX C1 — degraded resume path
    // returns config('conversation.prompt_version') instead of null for C9 traceability).
    $response->assertJsonPath('question_context.prompt_version', fn ($v) => is_string($v) && strlen($v) > 0);
    expect($response->json('question_context.prompt_version'))->toBe(config('conversation.prompt_version'));
});

test('5.7 RESUME in_corso + composition succeeds → 201, provider body contains system_prompt (adaptive resume)', function (): void {
    $capturedContextBody = null;
    Http::fake(function ($request) use (&$capturedContextBody) {
        if (str_contains($request->url(), '/contexts')) {
            $capturedContextBody = $request->data();
            return Http::response(['data' => ['context_id' => 'ctx-resume-adaptive']], 200);
        }
        if (str_contains($request->url(), '/sessions/token')) {
            return Http::response(['data' => ['session_id' => 'heygen-session-adaptive', 'access_token' => 'heygen-token-adaptive']], 200);
        }
        return Http::response([], 200);
    });
    Queue::fake();

    // Use an IT project WITH IT anchor translations so composition succeeds
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $roleCode = 'FLL_adaptive_' . uniqid();
    $role = Role::factory()->create(['code' => $roleCode]);
    $competency = Competency::factory()->create(['code' => 'COL_adaptive_' . uniqid()]);

    $project = Project::factory()->create([
        'status'          => 'active',
        'role_code'       => $roleCode,
        'language'        => 'it',
        'assessment_type' => 'standard',
    ]);

    DB::table('project_competencies')->insert([
        'project_id'    => $project->id,
        'competency_id' => $competency->id,
        'position'      => 1,
    ]);

    // Seed both EN and IT indicators → composition succeeds
    c8SeedIndicators($role->id, $competency->id, 'en', 2);
    c8SeedIndicatorsLocale($role->id, $competency->id, 'it');

    $participant = c8MakeParticipant($org, $project, 'in_corso');

    // Pre-existing in_corso session
    $session = InterviewSession::create([
        'participant_id'       => $participant->id,
        'project_id'           => $project->id,
        'question_index'       => 0,
        'competency_code'      => $competency->code,
        'framework_version_id' => $project->framework_version_id,
        'provider'             => 'heygen',
        'provider_session_ref' => 'old-adaptive-ref-' . uniqid(),
        'status'               => 'in_corso',
    ]);

    $bearer = CandidateTokenFactory::mintCandidateToken($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . $bearer])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);

    // Only one session row (reused)
    expect(InterviewSession::where('participant_id', $participant->id)->count())->toBe(1);

    // Session ref updated to fresh token
    $session->refresh();
    expect($session->provider_session_ref)->toBe('heygen-session-adaptive');

    // Provider contexts call MUST carry system_prompt (adaptive path)
    expect($capturedContextBody)->toHaveKey('system_prompt');
    expect($capturedContextBody['system_prompt'])->not->toBeEmpty();

    // prompt_version in response must be non-null (composed)
    $response->assertJsonPath('question_context.prompt_version', fn ($v) => is_string($v) && strlen($v) > 0);
});

test('5.8 NEW session + composition fails (regression guard) → still 422, no InterviewSession created, no provider call', function (): void {
    Http::fake(c8HeygenFake());
    Queue::fake();

    // New participant — no existing session. Project with IT locale and NO IT translations.
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $roleCode = 'MLL_reg_' . uniqid();
    $role = Role::factory()->create(['code' => $roleCode]);
    $competency = Competency::factory()->create(['code' => 'INN_reg_' . uniqid()]);

    $project = Project::factory()->create([
        'status'          => 'active',
        'role_code'       => $roleCode,
        'language'        => 'it',
        'assessment_type' => 'standard',
    ]);

    DB::table('project_competencies')->insert([
        'project_id'    => $project->id,
        'competency_id' => $competency->id,
        'position'      => 1,
    ]);

    // Only EN indicators — missing IT translations → composition fails
    c8SeedIndicators($role->id, $competency->id, 'en', 2);

    // Fresh participant with NO prior sessions
    $participant = c8MakeParticipant($org, $project, 'in_attesa');
    $bearer = CandidateTokenFactory::mintCandidateToken($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . $bearer])
        ->postJson('/api/candidate/interview/start');

    // Must still fail fast (no orphan session, no provider call)
    $response->assertStatus(422);

    $resolver->setOrgId($org->id);
    expect(InterviewSession::where('participant_id', $participant->id)->count())->toBe(0);

    Http::assertNothingSent();
});

// ─── FIX W1: assessment_type guard ───────────────────────────────────────────

test('W1 potential-type project → 422 assessment_type_not_supported, no session created, no provider call', function (): void {
    Http::fake(c8HeygenFake());
    Queue::fake();

    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    // potential type: role_code is null (domain constraint); assessment_type = 'potential'
    $competency = Competency::factory()->create(['code' => 'MTG_' . uniqid()]);

    $project = Project::factory()->create([
        'status'          => 'active',
        'role_code'       => null,
        'language'        => 'en',
        'assessment_type' => 'potential',
    ]);

    DB::table('project_competencies')->insert([
        'project_id'    => $project->id,
        'competency_id' => $competency->id,
        'position'      => 1,
    ]);

    $participant = c8MakeParticipant($org, $project, 'in_attesa');
    $bearer = CandidateTokenFactory::mintCandidateToken($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . $bearer])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(422);
    $response->assertJsonPath('error', 'assessment_type_not_supported');

    // No InterviewSession row created
    $resolver->setOrgId($org->id);
    expect(InterviewSession::where('participant_id', $participant->id)->count())->toBe(0);

    // No provider HTTP call made
    Http::assertNothingSent();
});

test('W1 potential-type project on RESUME in_corso path → 422 assessment_type_not_supported, no provider call', function (): void {
    Http::fake(c8HeygenFake());
    Queue::fake();

    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $competency = Competency::factory()->create(['code' => 'LAT_' . uniqid()]);

    $project = Project::factory()->create([
        'status'          => 'active',
        'role_code'       => null,
        'language'        => 'en',
        'assessment_type' => 'potential',
    ]);

    DB::table('project_competencies')->insert([
        'project_id'    => $project->id,
        'competency_id' => $competency->id,
        'position'      => 1,
    ]);

    // Participant already in_corso with a pre-existing in_corso session (resume scenario)
    $participant = c8MakeParticipant($org, $project, 'in_corso');

    InterviewSession::create([
        'participant_id'       => $participant->id,
        'project_id'           => $project->id,
        'question_index'       => 0,
        'competency_code'      => $competency->code,
        'framework_version_id' => $project->framework_version_id,
        'provider'             => 'heygen',
        'provider_session_ref' => 'old-potential-ref-' . uniqid(),
        'status'               => 'in_corso',
    ]);

    $bearer = CandidateTokenFactory::mintCandidateToken($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . $bearer])
        ->postJson('/api/candidate/interview/start');

    // Must hard-fail even on resume path — non-standard type never reaches degraded bypass
    $response->assertStatus(422);
    $response->assertJsonPath('error', 'assessment_type_not_supported');

    // No provider HTTP call made
    Http::assertNothingSent();
});
