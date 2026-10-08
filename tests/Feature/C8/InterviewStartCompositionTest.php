<?php

declare(strict_types=1);

/**
 * RED — Tasks 5.1–5.4: InterviewController::start() composition wiring (C8 Phase 5).
 *
 * Asserts:
 * (5.1) /start with valid standard competency → 201 + question_context.prompt_version non-null.
 * (5.2) Missing IT anchor translation → 422 anchor_translation_missing; no session; no provider call.
 * (5.3) Empty indicator set for the role → 422 composition_error; no provider call; session pending.
 * (5.4) Provider 5xx failure matrix unchanged after QuestionContext widening → 502 (C7a regression).
 * (5.6) RESUME in_corso + composition fails → 422, no fresh provider session, session ref untouched.
 * (5.7) RESUME in_corso + composition succeeds → 201, provider body CONTAINS system_prompt (adaptive resume).
 * (5.8) NEW session + composition fails (regression guard) → still 422, no InterviewSession created, no provider call.
 *
 * Uses Http::fake for all provider calls — no live provider.
 *
 * @group feature
 *
 * Spec: REQ QuestionContext Carries Composed Prompt · REQ i18n hard-fail · REQ Provider Payload Contract.
 * REQ: InterviewController::start() wiring (C8 Phase 5 — M-3)
 */

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
    $roleCode = 'FLL_'.uniqid();
    $role = Role::factory()->create(['code' => $roleCode]);

    // Create competency + link to project via project_competencies
    $competency = Competency::factory()->create(['code' => 'COL_'.uniqid()]);

    // Project with role_code matching the role we created
    $project = Project::factory()->create([
        'status' => 'active',
        'role_code' => $roleCode,
        'language' => $locale,
        'assessment_type' => 'standard',
    ]);

    // Attach competency to project
    DB::table('project_competencies')->insert([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'position' => 1,
    ]);

    // framework-catalogue-authoring PR6 (D5) — `/start` now refuses a
    // project where a selected competency has zero live `project_questions`
    // rows. NOT added here: several callers of this shared scenario add
    // their OWN `project_questions` rows afterward, at explicit positions,
    // to assert opening/ordering behaviour — a row auto-added here at
    // position 0 would silently become "the first authored question" and
    // break those assertions. Each caller that does NOT author its own is
    // responsible for calling `c8MakeInterviewable()` itself.
    //
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
 * Gives the scenario's competency ONE live `project_questions` row, so it
 * satisfies `ProjectInterviewability` (framework-catalogue-authoring PR6,
 * D5). Only for callers that do NOT author their own questions — see
 * `c8SeedStandardScenario()`'s own docblock note.
 *
 * @param  array{project: Project, competency: Competency}  $scenario
 */
function c8MakeInterviewable(array $scenario): void
{
    ProjectQuestion::create([
        'project_id' => $scenario['project']->id,
        'competency_id' => $scenario['competency']->id,
        'text' => ['en' => 'C8 fixture question', 'it' => 'Domanda fixture C8'],
        'position' => 0,
    ]);
}

/**
 * Create BarsIndicator rows with EN translations.
 */
function c8SeedIndicators(?int $roleId, int $competencyId, string $locale = 'en', int $count = 2): void
{
    for ($i = 0; $i < $count; $i++) {
        $ind = new BarsIndicator;
        $ind->forceFill([
            'role_id' => $roleId,
            'competency_id' => $competencyId,
            'text' => [$locale => "Indicator text {$i}"],
            'anchor_5' => [$locale => "Excellent anchor {$i}"],
            'anchor_3' => [$locale => "Adequate anchor {$i}"],
            'anchor_1' => [$locale => "Insufficient anchor {$i}"],
            'position' => $i,
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
        'project_id' => $project->id,
        'candidate_ref' => 'c8-'.uniqid(),
        'display_name' => 'C8 Test Candidate',
        'email' => uniqid('cand-').'@example.test',
        'status' => $status,
    ]);
    $p->save();

    return $p->fresh();
}

function c8HeygenFake(): array
{
    return [
        '*liveavatar*/contexts*' => Http::response(['data' => ['context_id' => 'ctx-c8']], 200),
        '*liveavatar*/sessions/token*' => Http::response([
            'data' => [
                'session_id' => 'heygen-session-c8',
                'session_token' => 'heygen-token-c8',
            ],
        ], 200),
        '*liveavatar*/sessions/*' => Http::response([], 200), // teardown
    ];
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('5.1 /start with standard EN competency → 201 + question_context.prompt_version non-null non-empty', function (): void {
    Http::fake(c8HeygenFake());
    Queue::fake();

    $scenario = c8SeedStandardScenario('en');
    c8MakeInterviewable($scenario);
    $bearer = CandidateTokenFactory::mintCandidateToken($scenario['participant']);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);

    // prompt_version must be present, non-null, non-empty (M-3)
    $response->assertJsonPath('question_context.prompt_version', fn ($v) => is_string($v) && strlen($v) > 0);
});

test('5.1b /start stamps the configured conversation prompt version on the session', function (): void {
    Http::fake(c8HeygenFake());
    Queue::fake();

    $scenario = c8SeedStandardScenario('en');
    c8MakeInterviewable($scenario);
    $bearer = CandidateTokenFactory::mintCandidateToken($scenario['participant']);

    $this
        ->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    $session = InterviewSession::where('participant_id', $scenario['participant']->id)->sole();
    expect($session->conversation_prompt_version)
        ->toBe(config('conversation.prompt_version'))
        ->not->toBeEmpty();
});

test('5.5 /start response never leaks composed system_prompt; provider body carries it (anti-leak)', function (): void {
    Queue::fake();

    // Capture the outbound HeyGen /contexts body while faking the provider.
    $capturedContextBody = [];
    Http::fake(function ($request) use (&$capturedContextBody) {
        if (str_contains($request->url(), '/contexts')) {
            $capturedContextBody = $request->data();

            return Http::response(['data' => ['id' => 'ctx-c8']], 200);
        }
        if (str_contains($request->url(), '/sessions/token')) {
            return Http::response(['data' => ['session_id' => 'heygen-session-c8', 'session_token' => 'heygen-token-c8']], 200);
        }

        return Http::response([], 200);
    });

    $scenario = c8SeedStandardScenario('en');
    c8MakeInterviewable($scenario);
    $bearer = CandidateTokenFactory::mintCandidateToken($scenario['participant']);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$bearer])
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

    // (c) The composed system prompt MUST still reach the provider server-to-server,
    //     under the REAL wire field name `prompt` (PR2 — `system_prompt` never existed
    //     in the real LiveAvatar contract; @wire-source start.ts:255).
    expect($capturedContextBody)->toHaveKey('prompt');
    expect($capturedContextBody['prompt'])->toContain('Excellent anchor');
});

test('5.2 /start missing IT anchor translation → 422 anchor_translation_missing; no session; no provider call', function (): void {
    // EN indicators only; project language = it → translation missing → 422
    Http::fake(c8HeygenFake());
    Queue::fake();

    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $roleCode = 'MLL_'.uniqid();
    $role = Role::factory()->create(['code' => $roleCode]);
    $competency = Competency::factory()->create(['code' => 'INN_'.uniqid()]);

    $project = Project::factory()->create([
        'status' => 'active',
        'role_code' => $roleCode,
        'language' => 'it',   // IT locale
        'assessment_type' => 'standard',
    ]);

    DB::table('project_competencies')->insert([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'position' => 1,
    ]);

    // framework-catalogue-authoring PR6 (D5) — interviewability is a
    // precondition here; this scenario's own subject is the anchor
    // translation gap.
    ProjectQuestion::create([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'text' => ['en' => 'C8 fixture question', 'it' => 'Domanda fixture C8'],
        'position' => 0,
    ]);

    // Only EN indicators — NO Italian translation → AnchorTranslationMissingException
    c8SeedIndicators($role->id, $competency->id, 'en', 2);

    $participant = c8MakeParticipant($org, $project);
    $bearer = CandidateTokenFactory::mintCandidateToken($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$bearer])
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

    $roleCode = 'BUL_'.uniqid();
    $role = Role::factory()->create(['code' => $roleCode]);
    $competency = Competency::factory()->create(['code' => 'STG_'.uniqid()]);

    $project = Project::factory()->create([
        'status' => 'active',
        'role_code' => $roleCode,
        'language' => 'en',
        'assessment_type' => 'standard',
    ]);

    DB::table('project_competencies')->insert([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'position' => 1,
    ]);

    // framework-catalogue-authoring PR6 (D5) — interviewability is a
    // precondition here; this scenario's own subject is the missing
    // indicator set.
    ProjectQuestion::create([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'text' => ['en' => 'C8 fixture question', 'it' => 'Domanda fixture C8'],
        'position' => 0,
    ]);

    // NO BarsIndicators for this role+competency → CompositionException
    // (role exists but has zero indicators for this competency)

    $participant = c8MakeParticipant($org, $project);
    $bearer = CandidateTokenFactory::mintCandidateToken($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$bearer])
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
    c8MakeInterviewable($scenario);
    $bearer = CandidateTokenFactory::mintCandidateToken($scenario['participant']);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$bearer])
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

    $roleCode = 'FLL_resume_'.uniqid();
    $role = Role::factory()->create(['code' => $roleCode]);

    $competency = Competency::factory()->create(['code' => 'COL_resume_'.uniqid()]);

    // Project with IT locale — composition will fail because only EN indicators exist
    $project = Project::factory()->create([
        'status' => 'active',
        'role_code' => $roleCode,
        'language' => 'it',
        'assessment_type' => 'standard',
    ]);

    DB::table('project_competencies')->insert([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'position' => 1,
    ]);

    // Only EN indicators — no IT translations → AnchorTranslationMissingException on compose
    c8SeedIndicators($role->id, $competency->id, 'en', 2);

    // Participant already in_corso (resume scenario)
    $participant = c8MakeParticipant($org, $project, 'in_corso');

    // Pre-existing in_corso session (this is the session we are resuming)
    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $competency->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'provider_session_ref' => 'old-resume-ref-'.uniqid(),
        'status' => 'in_corso',
    ]);

    return compact('org', 'project', 'participant', 'session');
}

test('5.6 RESUME in_corso + composition fails → 422, no fresh provider session, the outgoing one torn down', function (): void {
    // A resumed provider session without a system prompt would run the
    // vendor's default persona, asking questions nobody authored — so a
    // resume fails exactly like a fresh start.
    $tokenCalls = 0;
    $teardownRefs = [];
    Http::fake(function ($request) use (&$tokenCalls, &$teardownRefs) {
        $url = $request->url();

        if (str_contains($url, '/sessions/token')) {
            $tokenCalls++;
        }

        if ($request->method() === 'POST' && str_ends_with($url, '/sessions/stop')) {
            $teardownRefs[] = (string) ($request->data()['session_id'] ?? '');
        }

        return Http::response([], 200);
    });
    Queue::fake();

    $data = c8SeedResumeInCorsoScenario();
    $oldRef = $data['session']->provider_session_ref;
    $bearer = CandidateTokenFactory::mintCandidateToken($data['participant']);

    $this
        ->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(422)
        ->assertJsonPath('error', 'anchor_translation_missing');

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($data['org']->id);
    $resolver->setBypass(false);

    $data['session']->refresh();
    expect($tokenCalls)->toBe(0)
        // The candidate is refused, so nothing will ever speak to the outgoing
        // provider session again — and HeyGen bills it until its own ceiling.
        // The 422 ends it and forgets its ref, leaving the row in the state
        // /suspend leaves: `in_corso`, resumable, pointing at nothing.
        ->and($teardownRefs)->toBe([$oldRef])
        ->and($data['session']->provider_session_ref)->toBeNull()
        ->and($data['session']->status)->toBe('in_corso')
        ->and(InterviewSession::where('participant_id', $data['participant']->id)->count())->toBe(1);
});

test('5.7 RESUME in_corso + composition succeeds → 201, provider body contains prompt (adaptive resume)', function (): void {
    $capturedContextBody = null;
    Http::fake(function ($request) use (&$capturedContextBody) {
        if (str_contains($request->url(), '/contexts')) {
            $capturedContextBody = $request->data();

            return Http::response(['data' => ['id' => 'ctx-resume-adaptive']], 200);
        }
        if (str_contains($request->url(), '/sessions/token')) {
            return Http::response(['data' => ['session_id' => 'heygen-session-adaptive', 'session_token' => 'heygen-token-adaptive']], 200);
        }

        return Http::response([], 200);
    });
    Queue::fake();

    // Use an IT project WITH IT anchor translations so composition succeeds
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $roleCode = 'FLL_adaptive_'.uniqid();
    $role = Role::factory()->create(['code' => $roleCode]);
    $competency = Competency::factory()->create(['code' => 'COL_adaptive_'.uniqid()]);

    $project = Project::factory()->create([
        'status' => 'active',
        'role_code' => $roleCode,
        'language' => 'it',
        'assessment_type' => 'standard',
    ]);

    DB::table('project_competencies')->insert([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'position' => 1,
    ]);

    // Seed both EN and IT indicators → composition succeeds
    c8SeedIndicators($role->id, $competency->id, 'en', 2);
    c8SeedIndicatorsLocale($role->id, $competency->id, 'it');

    $participant = c8MakeParticipant($org, $project, 'in_corso');

    // Pre-existing in_corso session
    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $competency->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'provider_session_ref' => 'old-adaptive-ref-'.uniqid(),
        'status' => 'in_corso',
    ]);

    $bearer = CandidateTokenFactory::mintCandidateToken($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);

    // Only one session row (reused)
    expect(InterviewSession::where('participant_id', $participant->id)->count())->toBe(1);

    // Session ref updated to fresh token
    $session->refresh();
    expect($session->provider_session_ref)->toBe('heygen-session-adaptive');

    // Provider contexts call MUST carry `prompt` (adaptive path; real wire field, not `system_prompt`)
    expect($capturedContextBody)->toHaveKey('prompt');
    expect($capturedContextBody['prompt'])->not->toBeEmpty();

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

    $roleCode = 'MLL_reg_'.uniqid();
    $role = Role::factory()->create(['code' => $roleCode]);
    $competency = Competency::factory()->create(['code' => 'INN_reg_'.uniqid()]);

    $project = Project::factory()->create([
        'status' => 'active',
        'role_code' => $roleCode,
        'language' => 'it',
        'assessment_type' => 'standard',
    ]);

    DB::table('project_competencies')->insert([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'position' => 1,
    ]);

    // Only EN indicators — missing IT translations → composition fails
    c8SeedIndicators($role->id, $competency->id, 'en', 2);

    // Fresh participant with NO prior sessions
    $participant = c8MakeParticipant($org, $project, 'in_attesa');
    $bearer = CandidateTokenFactory::mintCandidateToken($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start');

    // Must still fail fast (no orphan session, no provider call)
    $response->assertStatus(422);

    $resolver->setOrgId($org->id);
    expect(InterviewSession::where('participant_id', $participant->id)->count())->toBe(0);

    Http::assertNothingSent();
});

// ─── Potential assessments start through the same adaptive engine ────────────

/**
 * Seed a `potential` scenario: Org → Project(role_code null) → competencies with
 * ROLE-LESS BARS rows (`role_id` null) and one authored primary each.
 *
 * @param  list<string>  $codes  Competency code prefixes, in interview order.
 * @return array{org: Organization, project: Project, participant: Participant, competencies: list<Competency>}
 */
function c8SeedPotentialScenario(array $codes = ['MTG'], string $participantStatus = 'in_attesa'): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create([
        'status' => 'active',
        'role_code' => null,
        'language' => 'en',
        'assessment_type' => 'potential',
    ]);

    $competencies = [];

    foreach ($codes as $position => $code) {
        $competency = Competency::factory()->create(['code' => $code.'_'.uniqid()]);

        DB::table('project_competencies')->insert([
            'project_id' => $project->id,
            'competency_id' => $competency->id,
            'position' => $position + 1,
        ]);

        c8SeedIndicators(null, $competency->id, 'en', 3);

        ProjectQuestion::create([
            'project_id' => $project->id,
            'competency_id' => $competency->id,
            'text' => ['en' => "Authored potential question for {$code}", 'it' => "Domanda potenziale {$code}"],
            'position' => 0,
        ]);

        $competencies[] = $competency;
    }

    $participant = c8MakeParticipant($org, $project, $participantStatus);

    return compact('org', 'project', 'participant', 'competencies');
}

/**
 * Fake the provider and capture the outbound context body, the live session
 * teardowns and the number of session tokens issued.
 *
 * @return ArrayObject<string, mixed> Filled while requests run: `body`, `teardowns`, `tokens`.
 */
function c8CaptureProvider(): ArrayObject
{
    $captured = new ArrayObject(['body' => [], 'teardowns' => [], 'tokens' => 0]);

    Http::fake(function ($request) use ($captured) {
        $url = $request->url();

        if (str_contains($url, '/contexts')) {
            $captured['body'] = $request->data();

            return Http::response(['data' => ['id' => 'ctx-potential']], 200);
        }

        if (str_contains($url, '/sessions/token')) {
            $captured['tokens']++;

            return Http::response(['data' => ['session_id' => 'heygen-potential', 'session_token' => 'tok-potential']], 200);
        }

        if ($request->method() === 'POST' && str_ends_with($url, '/sessions/stop')) {
            $captured['teardowns'][] = (string) ($request->data()['session_id'] ?? '');
        }

        return Http::response([], 200);
    });

    return $captured;
}

test('potential starts: 201, role-less indicators and the authored primary reach the provider prompt', function (): void {
    Queue::fake();
    $captured = c8CaptureProvider();

    $scenario = c8SeedPotentialScenario(['MTG']);
    $bearer = CandidateTokenFactory::mintCandidateToken($scenario['participant']);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);
    $response->assertJsonPath('question_context.prompt_version', config('conversation.prompt_version'));

    expect(config('conversation.prompt_version'))->toBeString()->not->toBe('');
    expect(InterviewSession::where('participant_id', $scenario['participant']->id)->count())->toBe(1);

    expect($captured['body'])->toHaveKey('prompt');
    expect($captured['body']['prompt'])
        ->toContain('Indicator text 0')
        ->toContain('Excellent anchor 2')
        ->toContain('Authored potential question for MTG');
});

test('potential resumes: the outgoing session is released and a fresh one carries the composed prompt', function (): void {
    Queue::fake();
    $captured = c8CaptureProvider();

    $scenario = c8SeedPotentialScenario(['LAT'], 'in_corso');
    $oldRef = 'old-potential-ref-'.uniqid();

    $session = InterviewSession::create([
        'participant_id' => $scenario['participant']->id,
        'project_id' => $scenario['project']->id,
        'question_index' => 0,
        'competency_code' => $scenario['competencies'][0]->code,
        'framework_version_id' => $scenario['project']->framework_version_id,
        'provider' => 'heygen',
        'provider_session_ref' => $oldRef,
        'status' => 'in_corso',
    ]);

    $bearer = CandidateTokenFactory::mintCandidateToken($scenario['participant']);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);
    expect($response->json('error'))->toBeNull();

    $session->refresh();
    expect($captured['teardowns'])->toBe([$oldRef])
        ->and($captured['tokens'])->toBe(1)
        ->and($session->provider_session_ref)->toBe('heygen-potential')
        ->and(InterviewSession::where('participant_id', $scenario['participant']->id)->count())->toBe(1);

    expect($captured['body']['prompt'])
        ->toContain('Indicator text 0')
        ->toContain('Authored potential question for LAT');
});

test('potential: a configured minimum above what one primary plus the budget permits is clamped to 5', function (): void {
    Queue::fake();
    $captured = c8CaptureProvider();
    config(['conversation.min_questions' => 6, 'conversation.followup_budget' => 4]);

    $scenario = c8SeedPotentialScenario(['MTG']);
    $bearer = CandidateTokenFactory::mintCandidateToken($scenario['participant']);

    $this
        ->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    // min(6, 1 primary + 4 follow-ups) = 5.
    expect($captured['body']['prompt'])
        ->toContain('at least 5 questions')
        ->not->toContain('at least 6 questions');
});

// ─── Assessment type default-deny ────────────────────────────────────────────

test('a stored assessment type outside the enum is refused before any gate, write or provider call', function (string $path): void {
    Http::fake(c8HeygenFake());
    Queue::fake();

    // Deliberately NOT made interviewable on the fresh path: were the guard to
    // run after the interviewability gate, this project would answer a
    // different code. The resume path carries an existing session instead.
    $scenario = $path === 'resume'
        ? c8SeedResumeInCorsoScenario()
        : c8SeedStandardScenario('en');

    $before = InterviewSession::where('participant_id', $scenario['participant']->id)->count();
    $statusBefore = $scenario['participant']->status;

    // Raw write: the model's own validation would refuse the bogus value.
    DB::table('projects')->where('id', $scenario['project']->id)->update(['assessment_type' => 'exotic']);

    $bearer = CandidateTokenFactory::mintCandidateToken($scenario['participant']);

    $this
        ->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(422)
        ->assertJsonPath('error', 'assessment_type_not_supported');

    expect(InterviewSession::where('participant_id', $scenario['participant']->id)->count())->toBe($before)
        ->and($scenario['participant']->fresh()->status)->toBe($statusBefore);

    Http::assertNothingSent();
})->with(['fresh', 'resume']);

// ─── Composition failures stay explicit ──────────────────────────────────────

test('standard whose role is not in the pinned revision → 422 composition_error, nothing written or sent', function (): void {
    Http::fake(c8HeygenFake());
    Queue::fake();

    $scenario = c8SeedStandardScenario('en');
    c8MakeInterviewable($scenario);

    DB::table('projects')->where('id', $scenario['project']->id)->update(['role_code' => 'NO_SUCH_ROLE']);

    $bearer = CandidateTokenFactory::mintCandidateToken($scenario['participant']);

    $this
        ->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(422)
        ->assertJsonPath('error', 'composition_error');

    expect(InterviewSession::where('participant_id', $scenario['participant']->id)->count())->toBe(0);
    Http::assertNothingSent();
});

test('potential whose competency has only a role-scoped decoy row → 422 composition_error, nothing written or sent', function (): void {
    Http::fake(c8HeygenFake());
    Queue::fake();

    $scenario = c8SeedPotentialScenario(['MTG']);
    $competency = $scenario['competencies'][0];

    // Replace the role-less rows with a decoy bound to a role: `role_id IS NULL`
    // must exclude it.
    BarsIndicator::where('competency_id', $competency->id)->delete();
    $decoyRole = Role::factory()->create(['code' => 'DECOY_'.uniqid()]);
    c8SeedIndicators($decoyRole->id, $competency->id, 'en', 3);

    $bearer = CandidateTokenFactory::mintCandidateToken($scenario['participant']);

    $this
        ->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(422)
        ->assertJsonPath('error', 'composition_error');

    expect(InterviewSession::where('participant_id', $scenario['participant']->id)->count())->toBe(0);
    Http::assertNothingSent();
});

test('potential reaching a LAT competency without role-less rows → 422 composition_error, no provider call', function (): void {
    Http::fake(c8HeygenFake());
    Queue::fake();

    $scenario = c8SeedPotentialScenario(['MTG', 'LAT'], 'in_corso');
    [$mtg, $lat] = $scenario['competencies'];

    BarsIndicator::where('competency_id', $lat->id)->delete();

    // MTG is already done, so the next competency is LAT.
    InterviewSession::create([
        'participant_id' => $scenario['participant']->id,
        'project_id' => $scenario['project']->id,
        'question_index' => 0,
        'competency_code' => $mtg->code,
        'framework_version_id' => $scenario['project']->framework_version_id,
        'provider' => 'heygen',
        'provider_session_ref' => null,
        'status' => 'completed',
    ]);

    $bearer = CandidateTokenFactory::mintCandidateToken($scenario['participant']);

    $this
        ->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(422)
        ->assertJsonPath('error', 'composition_error');

    expect(InterviewSession::where('participant_id', $scenario['participant']->id)->count())->toBe(1);
    Http::assertNothingSent();
});

// ─── Operator-authored questions reach the interviewer ───────────────────────

test('an operator-authored question reaches the provider inside the system prompt', function (): void {
    /**
     * The end-to-end proof for a feature that was write-only.
     *
     * The backoffice has offered a per-competency question editor since C4.
     * `ProjectQuestion` rows saved, listed and edited correctly — and no part
     * of the interview ever read them. An operator could author the exact
     * question they needed asked, watch it persist, open the interview, and
     * hear the avatar improvise something else entirely.
     *
     * Asserted on the PROVIDER's outbound body rather than on the /start
     * response, for the same reason 5.5 above does: the composed prompt must
     * reach the provider server-to-server and must never be visible to the
     * candidate being assessed.
     */
    Queue::fake();

    $capturedContextBody = [];
    Http::fake(function ($request) use (&$capturedContextBody) {
        if (str_contains($request->url(), '/contexts')) {
            $capturedContextBody = $request->data();

            return Http::response(['data' => ['id' => 'ctx-authored']], 200);
        }
        if (str_contains($request->url(), '/sessions/token')) {
            return Http::response(['data' => ['session_id' => 'heygen-authored', 'session_token' => 'tok-authored']], 200);
        }

        return Http::response([], 200);
    });

    $scenario = c8SeedStandardScenario('en');

    // Two questions, authored out of order, to prove `position` is what
    // decides the order rather than insertion.
    ProjectQuestion::create([
        'project_id' => $scenario['project']->id,
        'competency_id' => $scenario['competency']->id,
        'text' => ['en' => 'Second authored question.'],
        'position' => 2,
    ]);
    ProjectQuestion::create([
        'project_id' => $scenario['project']->id,
        'competency_id' => $scenario['competency']->id,
        'text' => ['en' => 'First authored question.'],
        'position' => 1,
    ]);

    $bearer = CandidateTokenFactory::mintCandidateToken($scenario['participant']);

    $this->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    expect($capturedContextBody)->toHaveKey('prompt');
    expect($capturedContextBody['prompt'])->toContain('First authored question.');
    expect($capturedContextBody['prompt'])->toContain('Second authored question.');

    $first = strpos($capturedContextBody['prompt'], 'First authored question.');
    $second = strpos($capturedContextBody['prompt'], 'Second authored question.');

    expect($first)->toBeLessThan($second);
});

test('an authored question written only in another locale is still asked', function (): void {
    // A question authored in Italian on an English project is still the
    // question the operator wants asked. Dropping it for a locale mismatch
    // would restore the write-only behaviour for exactly the operators most
    // likely to hit it.
    Queue::fake();

    $capturedContextBody = [];
    Http::fake(function ($request) use (&$capturedContextBody) {
        if (str_contains($request->url(), '/contexts')) {
            $capturedContextBody = $request->data();

            return Http::response(['data' => ['id' => 'ctx-locale']], 200);
        }
        if (str_contains($request->url(), '/sessions/token')) {
            return Http::response(['data' => ['session_id' => 'heygen-locale', 'session_token' => 'tok-locale']], 200);
        }

        return Http::response([], 200);
    });

    $scenario = c8SeedStandardScenario('en');

    ProjectQuestion::create([
        'project_id' => $scenario['project']->id,
        'competency_id' => $scenario['competency']->id,
        'text' => ['it' => 'Raccontami di una volta in cui hai gestito un conflitto.'],
        'position' => 1,
    ]);

    $bearer = CandidateTokenFactory::mintCandidateToken($scenario['participant']);

    $this->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    expect($capturedContextBody['prompt'])->toContain('Raccontami di una volta in cui hai gestito un conflitto.');
});

test('the SPOKEN opening is the operator\'s first authored question, not a template', function (): void {
    /**
     * RED — reported from production 2026-09-08.
     *
     * An operator authored their questions, opened the interview, and heard
     * "Parliamo di problem solving… raccontami un episodio" — a sentence they
     * had never written. Their questions were reaching the system prompt as
     * mandatory the whole time; the trouble was ORDER. The template already
     * asked a generic question, so the first thing any candidate ever heard
     * was never the operator's own.
     *
     * Asserted on `opening_text` — the field HeygenProvider sends as the
     * avatar's first spoken line — because that IS the thing the candidate
     * hears. Asserting on the prompt would pass on the broken behaviour.
     */
    Queue::fake();

    $capturedContextBody = [];
    Http::fake(function ($request) use (&$capturedContextBody) {
        if (str_contains($request->url(), '/contexts')) {
            $capturedContextBody = $request->data();

            return Http::response(['data' => ['id' => 'ctx-opening']], 200);
        }
        if (str_contains($request->url(), '/sessions/token')) {
            return Http::response(['data' => ['session_id' => 'heygen-opening', 'session_token' => 'tok-opening']], 200);
        }

        return Http::response([], 200);
    });

    $scenario = c8SeedStandardScenario('en');

    ProjectQuestion::create([
        'project_id' => $scenario['project']->id,
        'competency_id' => $scenario['competency']->id,
        'text' => ['en' => 'Second authored question.'],
        'position' => 2,
    ]);
    ProjectQuestion::create([
        'project_id' => $scenario['project']->id,
        'competency_id' => $scenario['competency']->id,
        'text' => ['en' => 'Walk me through a time you handled a hostile client.'],
        'position' => 1,
    ]);

    $bearer = CandidateTokenFactory::mintCandidateToken($scenario['participant']);

    $this->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    expect($capturedContextBody['opening_text'])
        ->toBe('Walk me through a time you handled a hostile client.');

    // The remaining authored questions are still mandatory in the prompt —
    // opening on the first one must not consume the rest.
    expect($capturedContextBody['prompt'])->toContain('Second authored question.');
});

test('a competency with NO authored question never reaches /start at all (framework-catalogue-authoring PR6)', function (): void {
    // SUPERSEDED by the interviewability predicate (project-config spec,
    // "A Zero-Primary Competency Never Reaches Interview"): a selected
    // competency with zero LIVE `project_questions` rows now makes the
    // WHOLE project non-interviewable, refused before composition is ever
    // attempted — this is the exact scenario the old assertion below
    // exercised, and it can no longer be reached through this endpoint.
    //
    // The welcome-template fallback this test used to protect is not dead:
    // `OpeningTextComposer` still produces it, and its own behaviour for a
    // zero-question competency is proven directly and independently of HTTP
    // reachability by `tests/Unit/Services/Conversation/OpeningTextComposerTest.php`
    // ("the version is stamped identically whether or not a question was
    // authored").
    Queue::fake();
    Http::fake();

    $scenario = c8SeedStandardScenario('en');
    // Deliberately NO `project_questions` row for the competency.
    $bearer = CandidateTokenFactory::mintCandidateToken($scenario['participant']);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(422);
    $response->assertJsonPath('error', 'project_not_interviewable');
    Http::assertNothingSent();
});
