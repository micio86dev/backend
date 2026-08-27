<?php

declare(strict_types=1);

/**
 * `SessionReviewResource`/`SessionSummaryResource` — the LLM cost read
 * surface (pluggable-conversation-llm PR P6b, design D10).
 *
 * Two labelled lines, never one combined total (the refusal already ratified
 * for avatar-vs-LLM spend at `SessionCostEstimator.php:20-22`). `actual_*`
 * renders ONLY when non-null — in `managed` mode it never is, so every
 * assertion here exercises the `estimated_*` branch, but the read surface
 * must not hardcode that assumption away.
 *
 * REQ: conversation-llm "Cost is visible per session and per template,
 *      always labelled an estimate, and the avatar/LLM lines are never
 *      summed into one figure"
 */

use App\Enums\LlmBindingStatus;
use App\Models\FrameworkVersion;
use App\Models\InterviewSession;
use App\Models\InterviewSessionLlmUsage;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function llmCostReviewUser(Organization $org, string $role = 'admin'): string
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $spatieRole = SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return (string) auth('api')->login($user);
}

function llmCostReviewSession(Organization $org, string $llmBindingStatus): InterviewSession
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['organization_id' => $org->id, 'framework_version_id' => $fv->id]);
    $participant = Participant::factory()->create(['organization_id' => $org->id, 'project_id' => $project->id]);

    $session = InterviewSession::factory()->ended(600)->create([
        'organization_id' => $org->id,
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'framework_version_id' => $fv->id,
        'provider' => 'heygen',
    ]);
    $session->forceFill(['llm_binding_status' => $llmBindingStatus])->save();

    return $session->fresh();
}

beforeEach(function (): void {
    Storage::fake();
});

test('a session with a usage row exposes an estimated LLM cost line, separate from the avatar line', function (): void {
    $org = Organization::factory()->create();
    $token = llmCostReviewUser($org);
    $session = llmCostReviewSession($org, LlmBindingStatus::Applied->value);

    InterviewSessionLlmUsage::create([
        'interview_session_id' => $session->id,
        'turn_count' => 3,
        'system_prompt_chars' => 400,
        'participant_chars' => 200,
        'avatar_chars' => 600,
        'live_seconds' => 600,
        'estimated_input_tokens' => 780,
        'estimated_output_tokens' => 240,
        'estimated_cost_usd' => 0.001234,
        'estimation_method' => 'chars4_context_resend_v1',
        'rate_card' => ['model_key' => 'gemini-3-flash-preview'],
    ]);

    $response = $this->withToken($token)->getJson("/api/interview-sessions/{$session->id}/review");

    $response->assertOk()->assertJsonStructure([
        'data' => ['cost' => ['avatar', 'llm', 'is_estimate']],
    ]);

    $llm = $response->json('data.cost.llm');

    expect((float) $llm['estimated_usd'])->toBe(0.001234)
        ->and($llm['actual_usd'])->toBeNull()
        // Two SEPARATE labelled lines — no combined field anywhere in cost.
        ->and($response->json('data.cost'))->not->toHaveKey('total')
        ->and($response->json('data.cost'))->not->toHaveKey('total_usd');
});

test('a session with no usage row (unbound/degraded) exposes a null LLM cost line, not zero', function (): void {
    $org = Organization::factory()->create();
    $token = llmCostReviewUser($org);
    $session = llmCostReviewSession($org, LlmBindingStatus::Unbound->value);

    $response = $this->withToken($token)->getJson("/api/interview-sessions/{$session->id}/review");

    $response->assertOk();
    expect($response->json('data.cost.llm'))->toBeNull();
});

test('the participant sessions list surfaces an llm_cost_usd figure per row, actual_* preferred when present', function (): void {
    $org = Organization::factory()->create();
    $token = llmCostReviewUser($org);
    $session = llmCostReviewSession($org, LlmBindingStatus::Applied->value);

    InterviewSessionLlmUsage::create([
        'interview_session_id' => $session->id,
        'turn_count' => 1,
        'system_prompt_chars' => 100,
        'participant_chars' => 20,
        'avatar_chars' => 80,
        'live_seconds' => 60,
        'estimated_input_tokens' => 120,
        'estimated_output_tokens' => 80,
        'estimated_cost_usd' => 0.000500,
        'estimation_method' => 'chars4_context_resend_v1',
        'rate_card' => [],
    ]);

    $response = $this->withToken($token)
        ->getJson("/api/participants/{$session->participant_id}/sessions");

    $response->assertOk();
    $row = $response->json('data.0');

    expect((float) $row['llm_cost_usd'])->toBe(0.0005);
});

test('a summary row for a session with no usage row exposes llm_cost_usd null', function (): void {
    $org = Organization::factory()->create();
    $token = llmCostReviewUser($org);
    $session = llmCostReviewSession($org, LlmBindingStatus::Degraded->value);

    $response = $this->withToken($token)
        ->getJson("/api/participants/{$session->participant_id}/sessions");

    $response->assertOk();
    expect($response->json('data.0.llm_cost_usd'))->toBeNull();
});
