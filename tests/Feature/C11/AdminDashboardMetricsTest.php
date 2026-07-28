<?php

declare(strict_types=1);

/**
 * RED — Admin dashboard metrics endpoint (C11 PR A3, task 8.3, D7).
 *
 * GET /api/dashboard/metrics: participants by status, evaluations by status,
 * completion rate, and — from ai_requests — summed input_tokens/output_tokens
 * and p50/p95 latency_ms, ALL org-scoped. Correction to the proposal
 * preserved here: no cost/currency column exists
 * (2026_07_22_000004_create_ai_requests_table.php:54-61) — this is token
 * usage, not monetary cost, and no such field is ever emitted.
 *
 * REQ: Admin Read Endpoint Surface (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md)
 */

use App\Models\AiRequest;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function dashboardTestAdminUser(Organization $org, string $role = 'admin'): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $spatieRole = SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return auth('api')->login($user);
}

function dashboardTestProjectIn(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    return Project::factory()->create(['framework_version_id' => $fv->id]);
}

test('GET /api/dashboard/metrics aggregates participants/evaluations/completion-rate/ai-usage, org-scoped', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $token = dashboardTestAdminUser($orgA);

    $projectA = dashboardTestProjectIn($orgA);
    $firstCompleted = Participant::factory()->forProject($projectA)->withStatus('completato')->create();
    $secondCompleted = Participant::factory()->forProject($projectA)->withStatus('completato')->create();
    Participant::factory()->forProject($projectA)->withStatus('in_corso')->create();

    $completedEvaluation = Evaluation::factory()->completed()->create(['participant_id' => $firstCompleted->id]);
    Evaluation::factory()->pending()->create(['participant_id' => $secondCompleted->id]);

    AiRequest::factory()->create([
        'evaluation_id' => $completedEvaluation->id,
        'input_tokens' => 100,
        'output_tokens' => 50,
        'latency_ms' => 200,
    ]);
    AiRequest::factory()->create([
        'evaluation_id' => $completedEvaluation->id,
        'input_tokens' => 300,
        'output_tokens' => 150,
        'latency_ms' => 800,
    ]);

    // Org B data must never leak into org A's metrics.
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgB->id);
    $projectB = dashboardTestProjectIn($orgB);
    Participant::factory()->forProject($projectB)->withStatus('completato')->create();
    $resolver->setOrgId($orgA->id);

    $response = $this->withToken($token)->getJson('/api/dashboard/metrics');

    $response->assertOk();
    $body = $response->json('data');

    expect($body['participants_by_status']['completato'])->toBe(2);
    expect($body['participants_by_status']['in_corso'])->toBe(1);
    expect(array_sum($body['participants_by_status']))->toBe(3);

    expect($body['evaluations_by_status']['completed'])->toBe(1);
    expect($body['evaluations_by_status']['pending'])->toBe(1);

    expect($body['completion_rate'])->toBe(round(2 / 3, 4));

    expect($body['ai_usage']['input_tokens'])->toBe(400);
    expect($body['ai_usage']['output_tokens'])->toBe(200);
    expect($body['ai_usage']['latency_ms_p50'])->toBeInt();
    expect($body['ai_usage']['latency_ms_p95'])->toBeInt();

    expect($body)->not->toHaveKey('cost');
    expect($body['ai_usage'])->not->toHaveKey('cost');
    expect($body['ai_usage'])->not->toHaveKey('currency');
});

test('GET /api/dashboard/metrics with no data returns zeroed metrics, never division-by-zero errors', function (): void {
    $org = Organization::factory()->create();
    $token = dashboardTestAdminUser($org);

    $response = $this->withToken($token)->getJson('/api/dashboard/metrics');

    $response->assertOk();
    $body = $response->json('data');

    expect($body['completion_rate'])->toBe(0.0);
    expect($body['ai_usage']['latency_ms_p50'])->toBeNull();
    expect($body['ai_usage']['latency_ms_p95'])->toBeNull();
});

test('GET /api/dashboard/metrics is accessible to viewer and operator roles (RBAC only, no lifecycle threshold)', function (): void {
    $org = Organization::factory()->create();

    foreach (['viewer', 'operator', 'admin'] as $role) {
        $token = dashboardTestAdminUser($org, $role);
        $this->withToken($token)->getJson('/api/dashboard/metrics')->assertOk();
    }
});
