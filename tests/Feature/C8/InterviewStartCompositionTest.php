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
