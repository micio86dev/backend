<?php

declare(strict_types=1);

/**
 * `GET /v1/projects` and `GET /v1/projects/{id}` — BEAI Public API
 * (public-api step 4). T-PRJ-001..005 — SPEC.md §3.3, §3.4.
 */

use App\Enums\ApiKeyMode;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\Competency;
use App\Models\Organization;
use App\Models\Project;
use App\Services\ApiKeyGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;

/**
 * @return array{org: Organization, key: string}
 */
function prjOrgWithScopedKey(array $abilities = ['projects:read']): array
{
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'abilities' => $abilities,
    ]);

    return ['org' => $org, 'key' => $rawKey];
}

function prjAvatarTemplateFor(Organization $org): AvatarTemplate
{
    $existing = AvatarTemplate::query()->where('organization_id', $org->id)->first();

    return $existing ?? AvatarTemplate::create([
        'name' => 'Ada',
        'provider' => 'heygen',
        'config' => [],
    ]);
}

function prjCreateProject(Organization $org, array $attributes = []): Project
{
    return TenantContextScope::runFor($org->id, function () use ($org, $attributes): Project {
        $avatarTemplate = prjAvatarTemplateFor($org);

        return Project::factory()->create(array_merge([
            'organization_id' => $org->id,
            'avatar_template_id' => $avatarTemplate->id,
        ], $attributes));
    });
}

// ─── T-PRJ-001: list — pagination envelope, ordering, has_more, filters ──────

test('T-PRJ-001: GET /v1/projects returns a paginated envelope matching the contract', function (): void {
    ['org' => $org, 'key' => $rawKey] = prjOrgWithScopedKey();

    TenantContextScope::runFor($org->id, function () use ($org): void {
        $avatarTemplate = AvatarTemplate::create(['name' => 'Ada', 'provider' => 'heygen', 'config' => []]);
        Project::factory()->count(3)->create(['organization_id' => $org->id, 'avatar_template_id' => $avatarTemplate->id]);
    });

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/projects');

    $response->assertOk()->assertJsonStructure(['data', 'next_cursor', 'has_more']);
    expect($response->json('data'))->toHaveCount(3);
    expect($response->json('has_more'))->toBeFalse();
    $this->assertMatchesContract($response, 'GET', '/projects');
});

test('T-PRJ-001: filters status, role_code, assessment_type narrow the list', function (): void {
    ['org' => $org, 'key' => $rawKey] = prjOrgWithScopedKey();

    prjCreateProject($org, ['status' => 'draft', 'assessment_type' => 'standard', 'role_code' => 'ICO']);
    $active = prjCreateProject($org, ['status' => 'active', 'assessment_type' => 'standard', 'role_code' => 'FLL']);
    prjCreateProject($org, ['status' => 'archived', 'assessment_type' => 'potential', 'role_code' => null]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/projects?status=active&role_code=FLL&assessment_type=standard');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe(PublicId::encode($active));
});

test('T-PRJ-001: an invalid filter value → 400 validation_failed, not 422', function (): void {
    ['key' => $rawKey] = prjOrgWithScopedKey();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/projects?status=not-a-real-status');

    $response->assertStatus(400)->assertJsonPath('code', 'validation_failed');
    $this->assertProblemMatchesContract($response, 400);
});

// ─── T-PRJ-002: detail — exact field set ─────────────────────────────────────

test('T-PRJ-002: GET /v1/projects/{id} returns exactly the §3.4 field set', function (): void {
    ['org' => $org, 'key' => $rawKey] = prjOrgWithScopedKey();
    $project = prjCreateProject($org);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/projects/'.PublicId::encode($project));

    $response->assertOk();

    $expected = [
        'id', 'name', 'slug', 'role_code', 'assessment_type', 'language', 'status',
        'framework_version', 'competencies', 'pause_every_n_competencies', 'nudge_min_chars',
        'exit_redirect_url', 'avatar_display_name', 'created_at', 'updated_at',
    ];

    $actual = array_keys($response->json());
    sort($expected);
    sort($actual);
    expect($actual)->toBe($expected);

    $this->assertMatchesContract($response, 'GET', '/projects/{id}');
});

// ─── T-PRJ-003: hidden fields never leak ─────────────────────────────────────

test('T-PRJ-003: hidden fields never appear in list or detail responses', function (): void {
    ['org' => $org, 'key' => $rawKey] = prjOrgWithScopedKey();
    $project = prjCreateProject($org, [
        'webhook_url' => 'https://example.test/hook',
        'webhook_secret' => 'super-secret',
        'error_redirect_url' => 'https://example.test/error',
        'deadline_at' => now(),
        'goes_live_at' => now(),
    ]);

    $forbidden = [
        'provider', 'config', 'persona', 'llm_model', 'llm_credential_id', 'llm_sync_status',
        'webhook_url', 'webhook_secret', 'webhook_events', 'has_webhook_secret',
        'error_redirect_url', 'deadline_at', 'goes_live_at', 'pin_context', 'can',
        'framework_version_id', 'organization_id', 'avatar_template_id', 'avatar_template',
    ];

    $detail = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/projects/'.PublicId::encode($project));
    $detail->assertOk();

    $body = json_encode($detail->json());
    foreach ($forbidden as $field) {
        expect($body)->not->toContain('"'.$field.'"', "detail response must not expose {$field}");
    }

    expect($detail->json('id'))->toBeString();
    expect(is_int($detail->json('id')))->toBeFalse();

    $list = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/projects');
    $listBody = json_encode($list->json());
    foreach ($forbidden as $field) {
        expect($listBody)->not->toContain('"'.$field.'"', "list response must not expose {$field}");
    }
});

// ─── T-PRJ-004: cross-org / soft-deleted / mismatched prefix → 404 ───────────

test('T-PRJ-004: a project belonging to another organization → 404', function (): void {
    ['org' => $orgA, 'key' => $keyA] = prjOrgWithScopedKey();
    $orgB = Organization::factory()->create();
    $projectOfB = prjCreateProject($orgB);

    $this->withHeaders(['Authorization' => 'Bearer '.$keyA])
        ->getJson('/api/v1/projects/'.PublicId::encode($projectOfB))
        ->assertNotFound();
});

test('T-PRJ-004: a soft-deleted project → 404', function (): void {
    ['org' => $org, 'key' => $rawKey] = prjOrgWithScopedKey();
    $project = prjCreateProject($org);
    TenantContextScope::runFor($org->id, fn () => $project->delete());

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/projects/'.PublicId::encode($project))
        ->assertNotFound();
});

test('T-PRJ-004: a mismatched-prefix id → 404, never 400', function (): void {
    ['org' => $org, 'key' => $rawKey] = prjOrgWithScopedKey();
    $project = prjCreateProject($org);
    $bareUlid = substr(PublicId::encode($project), strlen('prj_'));

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/projects/int_'.$bareUlid);

    $response->assertNotFound();
    $this->assertProblemMatchesContract($response, 404);
});

test('T-PRJ-004: an unknown but well-formed id → 404', function (): void {
    ['key' => $rawKey] = prjOrgWithScopedKey();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/projects/prj_01ARZ3NDEKTSV4RRFFQ69G5FAV');

    $response->assertNotFound();
});

// ─── T-PRJ-005: potential type, role_code null, missing scope → 403 ─────────

test('T-PRJ-005: a potential-type project has role_code null and only MTG/LAT competencies', function (): void {
    ['org' => $org, 'key' => $rawKey] = prjOrgWithScopedKey();

    $project = TenantContextScope::runFor($org->id, function () use ($org): Project {
        $avatarTemplate = AvatarTemplate::create(['name' => 'Ada', 'provider' => 'heygen', 'config' => []]);
        $project = Project::factory()->potential()->create([
            'organization_id' => $org->id,
            'avatar_template_id' => $avatarTemplate->id,
        ]);

        $mtg = Competency::query()->where('code', 'MTG')->first()
            ?? Competency::factory()->potential()->create(['code' => 'MTG']);
        $lat = Competency::query()->where('code', 'LAT')->first()
            ?? Competency::factory()->potential()->create(['code' => 'LAT']);
        $project->competencies()->attach([$mtg->id => ['position' => 1], $lat->id => ['position' => 2]]);

        return $project;
    });

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/projects/'.PublicId::encode($project));

    $response->assertOk()
        ->assertJsonPath('role_code', null)
        ->assertJsonPath('assessment_type', 'potential');

    $codes = array_column($response->json('competencies'), 'code');
    expect($codes)->toEqualCanonicalizing(['MTG', 'LAT']);
});

test('T-PRJ-005: missing projects:read scope → 403 insufficient_scope', function (): void {
    ['org' => $org] = prjOrgWithScopedKey();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'abilities' => ['interviews:read'],
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/projects');

    $response->assertStatus(403)->assertJsonPath('code', 'insufficient_scope');
    $this->assertProblemMatchesContract($response, 403);
});
