<?php

declare(strict_types=1);

/**
 * Org-level webhook defaults are copy-on-create, never a runtime fallback
 * (backoffice-missing-pages D3).
 *
 * `ProjectWebhookDefaults` fills a key ONLY when the request payload does not
 * contain it (`$request->exists('webhook_url')`, never `filled()`): an
 * operator who explicitly sends `"webhook_url": null` means "no webhook" and
 * must not have the org default silently reinstated.
 *
 * REQ: Organization Webhook Defaults Are Copy-On-Create
 *      (openspec/changes/backoffice-missing-pages/specs/organization-settings/spec.md)
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
});

function webhookDefaultsProjectPayload(int $fvId): array
{
    return [
        'framework_version_id' => $fvId,
        'slug' => 'wh-test-'.uniqid(),
        'name' => 'Webhook Defaults Test Project',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'avatar_template_id' => templateIdForCurrentOrg(),
    ];
}

test('a new project without explicit webhook fields copies the org defaults at creation', function (): void {
    $org = Organization::factory()->create([
        'default_webhook_url' => 'https://org-default.example.test/hook',
        'default_webhook_events' => ['progress', 'evaluation'],
    ]);
    $token = authTokenForRole($org, 'admin');

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $response = $this->withToken($token)->postJson('/api/projects', webhookDefaultsProjectPayload($fv->id));

    $response->assertCreated();
    $project = Project::find($response->json('data.id'));
    expect($project->webhook_url)->toBe('https://org-default.example.test/hook');
    expect($project->webhook_events)->toBe(['progress', 'evaluation']);
});

test('an explicit null webhook_url does not inherit the org default', function (): void {
    $org = Organization::factory()->create([
        'default_webhook_url' => 'https://org-default.example.test/hook',
    ]);
    $token = authTokenForRole($org, 'admin');

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $payload = webhookDefaultsProjectPayload($fv->id);
    $payload['webhook_url'] = null;

    $response = $this->withToken($token)->postJson('/api/projects', $payload);

    $response->assertCreated();
    $project = Project::find($response->json('data.id'));
    expect($project->webhook_url)->toBeNull();
});

test('changing the org default after a project was created leaves the project unchanged', function (): void {
    $org = Organization::factory()->create(['default_webhook_url' => 'https://org-default-x.example.test/hook']);
    $token = authTokenForRole($org, 'admin');

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $response = $this->withToken($token)->postJson('/api/projects', webhookDefaultsProjectPayload($fv->id));
    $response->assertCreated();
    $projectId = $response->json('data.id');

    // Change the org default to Y.
    $this->withToken($token)->patchJson('/api/organization', [
        'default_webhook_url' => 'https://org-default-y.example.test/hook',
    ])->assertOk();

    $project = Project::find($projectId);
    expect($project->webhook_url)->toBe('https://org-default-x.example.test/hook');
});
