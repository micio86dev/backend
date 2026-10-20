<?php

declare(strict_types=1);

/**
 * The organization route's `template_in_use` 409 carries `organization_count`, like the platform route's
 * (hr-avatar-adapter A12). An organization template is pinned by its own organization only, so the count is 1:
 * one body shape for both routes, and the published 409 documents it once.
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the organization delete refusal names the project and the organization count', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');
    app(TenantResolver::class)->setOrgId($org->id);

    $template = TenantContextScope::runFor($org->id, fn () => AvatarTemplate::create([
        'name' => 'Pinned',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
    ]));
    Project::factory()->count(2)->create(['avatar_template_id' => $template->id]);

    $this->withToken($token)->deleteJson("/api/avatar-templates/{$template->id}")
        ->assertConflict()
        ->assertExactJson([
            'error' => 'template_in_use',
            'message' => 'template_in_use',
            'project_count' => 2,
            'organization_count' => 1,
        ]);
});

test('the published delete conflict of both routes carries the same counts', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    $properties = fn (string $path): array => array_keys($spec['paths'][$path]['delete']['responses']['409']['content']['application/json']['schema']['properties']);

    expect($properties('/avatar-templates/{id}'))->toContain('project_count', 'organization_count')
        ->and($properties('/avatar-templates/{id}'))->toEqualCanonicalizing($properties('/admin/avatar-templates/{id}'));
});
