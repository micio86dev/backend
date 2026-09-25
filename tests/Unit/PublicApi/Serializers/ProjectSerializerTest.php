<?php

declare(strict_types=1);

/**
 * `App\PublicApi\Serializers\ProjectSerializer` — public-api step 4,
 * SPEC.md §3.4 "Projects specifically: expose `id, name, slug, role_code,
 * assessment_type, language, status, framework_version {version, label},
 * competencies[] {code, name, type}, pause_every_n_competencies,
 * nudge_min_chars, exit_redirect_url, avatar_display_name, created_at,
 * updated_at`" and `openapi.yaml`'s `Project` schema.
 */

use App\Models\AvatarTemplate;
use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\PublicApi\Serializers\ProjectSerializer;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;

function psrBuildProject(array $attributes = [], array $competencyNames = ['en' => 'Communication', 'it' => 'Comunicazione']): Project
{
    $org = Organization::factory()->create();

    return TenantContextScope::runFor($org->id, function () use ($org, $attributes, $competencyNames): Project {
        $frameworkVersion = FrameworkVersion::factory()->create(['version' => '2026.1', 'label' => 'Autumn 2026']);
        $avatarTemplate = AvatarTemplate::create([
            'name' => 'Ada — HeyGen',
            'provider' => 'heygen',
            'config' => [],
        ]);

        $project = Project::factory()->create(array_merge([
            'organization_id' => $org->id,
            'framework_version_id' => $frameworkVersion->id,
            'avatar_template_id' => $avatarTemplate->id,
            'language' => 'en',
        ], $attributes));

        $competency = Competency::factory()->create([
            'code' => 'COM',
            'type' => 'standard',
            'name' => $competencyNames,
        ]);

        $project->competencies()->attach($competency->id, ['position' => 1]);

        return $project->fresh(['frameworkVersion', 'avatarTemplate', 'competencies']);
    });
}

test('serializes exactly the §3.4 field set', function (): void {
    $project = psrBuildProject();

    $array = ProjectSerializer::toArray($project);

    expect(array_keys($array))->toEqualCanonicalizing([
        'id', 'name', 'slug', 'role_code', 'assessment_type', 'language', 'status',
        'framework_version', 'competencies', 'pause_every_n_competencies', 'nudge_min_chars',
        'exit_redirect_url', 'avatar_display_name', 'created_at', 'updated_at',
    ]);
});

test('id is prefixed prj_, framework_version is nested version/label, competencies carry the project-language name', function (): void {
    $project = psrBuildProject(['language' => 'it']);

    $array = ProjectSerializer::toArray($project);

    expect($array['id'])->toBe(PublicId::encode($project));
    expect($array['framework_version'])->toBe(['version' => '2026.1', 'label' => 'Autumn 2026']);
    expect($array['competencies'])->toBe([
        ['code' => 'COM', 'name' => 'Comunicazione', 'type' => 'standard'],
    ]);
});

test('a standard project exposes its role_code', function (): void {
    $project = psrBuildProject(['assessment_type' => 'standard', 'role_code' => 'ICO']);

    $array = ProjectSerializer::toArray($project);

    expect($array['role_code'])->toBe('ICO');
    expect($array['assessment_type'])->toBe('standard');
});

test('a potential project has role_code null', function (): void {
    $project = psrBuildProject(['assessment_type' => 'potential', 'role_code' => null]);

    $array = ProjectSerializer::toArray($project);

    expect($array['role_code'])->toBeNull();
    expect($array['assessment_type'])->toBe('potential');
});

test('avatar_display_name is the template name only', function (): void {
    $project = psrBuildProject();

    $array = ProjectSerializer::toArray($project);

    expect($array['avatar_display_name'])->toBe('Ada — HeyGen');
});

test('avatar_display_name is null when the avatar template relation is not loaded/unavailable', function (): void {
    $project = psrBuildProject();
    $project->unsetRelation('avatarTemplate');

    $array = ProjectSerializer::toArray($project);

    expect($array['avatar_display_name'])->toBeNull();
});

test('created_at and updated_at are ISO 8601 with Z', function (): void {
    $project = psrBuildProject();

    $array = ProjectSerializer::toArray($project);

    expect($array['created_at'])->toMatch('/Z$/');
    expect($array['updated_at'])->toMatch('/Z$/');
});

test('never exposes provider, model, prompts, keys, error_redirect_url, webhook configuration, deadline_at, goes_live_at, pin_context, can, or organization_id', function (): void {
    $project = psrBuildProject();

    $array = ProjectSerializer::toArray($project);
    $flat = json_encode($array);

    foreach (['provider', 'error_redirect_url', 'webhook_url', 'webhook_secret', 'webhook_events', 'deadline_at', 'goes_live_at', 'pin_context', 'can', 'organization_id', 'framework_version_id'] as $forbidden) {
        expect(array_key_exists($forbidden, $array))->toBeFalse("expected {$forbidden} to be absent from the top level");
    }

    expect($flat)->not->toContain('"provider"');
});
