<?php

declare(strict_types=1);

/**
 * RED — 4.3/4.4/4.5: webhook_events at the Store/UpdateProjectRequest + ProjectResource
 * layer (C10, design.md D10).
 *
 * - Unknown webhook_events value → HTTP 422 (POST and PATCH).
 * - ProjectResource exposes webhook_events; webhook_secret stays excluded.
 * - A fresh POST with no webhook_events defaults to both event types (full-stack
 *   confirmation of the PR1 schema default, now that the field is actually exposed).
 * - PATCH narrows the enabled event set.
 *
 * Refs spec: openspec/changes/webhooks-integration/specs/project-config/spec.md
 * "webhook_events — enabled event types per project (D10, C10 addendum)".
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Database\Seeders\FrameworkCatalogSeeder;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{user: User, token: string}
 */
function c10ProjectAdminUser(Organization $org): array
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);
    $token = auth('api')->login($user);

    return ['user' => $user, 'token' => $token];
}

/**
 * @return array<string, mixed>
 */
function c10StandardProjectPayload(int $fvId): array
{
    return [
        'framework_version_id' => $fvId,
        'slug' => 'c10-webhook-proj-'.uniqid(),
        'name' => 'C10 Webhook Test Project',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'avatar_template_id' => templateIdForCurrentOrg(),
    ];
}

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
});

test('POST /api/projects with an unknown webhook_events value → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = c10ProjectAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $payload = c10StandardProjectPayload($fv->id);
    $payload['webhook_events'] = ['progress', 'unknown_event'];

    $response = $this->withToken($token)->postJson('/api/projects', $payload);

    $response->assertStatus(422);
    expect(Project::count())->toBe(0);
});

test('PATCH /api/projects/{id} with an unknown webhook_events value → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = c10ProjectAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);

    $response = $this->withToken($token)->patchJson("/api/projects/{$project->id}", [
        'webhook_events' => ['unknown_event'],
    ]);

    $response->assertStatus(422);
    expect($project->refresh()->webhook_events)->toBe(['progress', 'evaluation']);
});

test('a fresh POST with no webhook_events in the payload defaults to both event types', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = c10ProjectAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $response = $this->withToken($token)->postJson('/api/projects', c10StandardProjectPayload($fv->id));

    $response->assertCreated();
    expect($response->json('data.webhook_events'))->toEqualCanonicalizing(['progress', 'evaluation']);
});

test('webhook_events exposed in API response, webhook_secret still excluded', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = c10ProjectAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $payload = c10StandardProjectPayload($fv->id);
    $payload['webhook_events'] = ['progress'];
    $payload['webhook_secret'] = 'super-secret-value';

    $response = $this->withToken($token)->postJson('/api/projects', $payload);

    $response->assertCreated();
    $data = $response->json('data');

    expect($data['webhook_events'])->toBe(['progress']);
    expect(array_key_exists('webhook_secret', $data))->toBeFalse();
    expect(json_encode($data))->not->toContain('super-secret-value');
});

test('PATCH narrows the enabled event set', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = c10ProjectAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'webhook_events' => ['progress', 'evaluation'],
    ]);

    $response = $this->withToken($token)->patchJson("/api/projects/{$project->id}", [
        'webhook_events' => ['evaluation'],
    ]);

    $response->assertOk();
    expect($response->json('data.webhook_events'))->toBe(['evaluation']);
    expect($project->refresh()->webhook_events)->toBe(['evaluation']);
});
