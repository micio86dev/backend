<?php

declare(strict_types=1);

/**
 * RED — Contract-truth tests (D1/D8, generated-client-truth-and-session-safety).
 *
 * Ten resources currently rely on a local `/** @var X $y *\/` PHPDoc hint that
 * Scramble's static export does not honour on its own. These tests assert the
 * ACTUAL RUNTIME wire shape — not the exported schema — of every field this
 * change's design.md D1 says must change type or nullability.
 *
 * Task 2.1 is a falsifiability gate: if the translatable-field assertions
 * (name/definition/responsibilities/text/anchor_*) are ALREADY strings today,
 * design.md's "translatable fields are strings, not JSON objects" correction
 * is validated by the runtime, and the union/object question does not reopen.
 * If any of them come back as an array/object, D1 is wrong and apply must
 * stop before annotating a single resource.
 */

use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Database\Seeders\FrameworkCatalogSeeder;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function truthTestAdminUser(Organization $org, string $role = 'admin'): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $spatieRole = SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return auth('api')->login($user);
}

function truthTestProjectIn(Organization $org, array $overrides = []): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    return Project::factory()->create(array_merge(['framework_version_id' => $fv->id], $overrides));
}

// ─────────────────────────────────────────────────────────────────────────
// 2.1 — Falsifiability gate: translatable fields are strings, not objects.
// ─────────────────────────────────────────────────────────────────────────

describe('2.1 falsifiability gate — translatable fields are strings', function (): void {
    beforeEach(function (): void {
        (new FrameworkCatalogSeeder)->run();
    });

    test('RoleResource.name and .responsibilities are strings', function (): void {
        $org = Organization::factory()->create();
        $token = truthTestAdminUser($org);

        $response = $this->withToken($token)->getJson('/api/framework/roles');
        $response->assertOk();

        $row = $response->json('data.0');
        expect($row['name'])->toBeString();
        expect($row['responsibilities'])->toBeString();
    });

    test('CompetencyResource.name and .definition are strings', function (): void {
        $org = Organization::factory()->create();
        $token = truthTestAdminUser($org);

        $response = $this->withToken($token)->getJson('/api/framework/roles/ICO/competencies');
        $response->assertOk();

        $row = $response->json('data.0');
        expect($row['name'])->toBeString();
        expect($row['definition'])->toBeString();
    });

    test('BarsIndicatorResource text/anchor_5/anchor_3/anchor_1 are strings', function (): void {
        $org = Organization::factory()->create();
        $token = truthTestAdminUser($org);

        $response = $this->withToken($token)
            ->getJson('/api/framework/roles/ICO/competencies/PRS/indicators');
        $response->assertOk();

        $row = $response->json('data.0');
        expect($row['text'])->toBeString();
        expect($row['anchor_5'])->toBeString();
        expect($row['anchor_3'])->toBeString();
        expect($row['anchor_1'])->toBeString();
    });
});

// ─────────────────────────────────────────────────────────────────────────
// 2.2 — Ids/FKs are integers at runtime, not just in the docblock.
// ─────────────────────────────────────────────────────────────────────────

describe('2.2 ids and foreign keys are integers', function (): void {
    beforeEach(function (): void {
        (new FrameworkCatalogSeeder)->run();
    });

    test('Admin ParticipantResource.id and .project_id are integers', function (): void {
        $org = Organization::factory()->create();
        $token = truthTestAdminUser($org);
        $project = truthTestProjectIn($org);
        Participant::factory()->forProject($project)->create();

        $response = $this->withToken($token)->getJson('/api/participants');
        $response->assertOk();

        $row = $response->json('data.0');
        expect($row['id'])->toBeInt();
        expect($row['project_id'])->toBeInt();
    });

    test('Admin ParticipantDetailResource.timeline.session_count is an integer', function (): void {
        $org = Organization::factory()->create();
        $token = truthTestAdminUser($org);
        $project = truthTestProjectIn($org);
        $participant = Participant::factory()->forProject($project)->create();

        $response = $this->withToken($token)->getJson('/api/participants/'.$participant->id);
        $response->assertOk();

        expect($response->json('data.id'))->toBeInt();
        expect($response->json('data.timeline.session_count'))->toBeInt();
    });

    test('Candidate ParticipantResource.id and nested project.id are integers', function (): void {
        $org = Organization::factory()->create();
        $project = truthTestProjectIn($org);
        $participant = Participant::factory()->forProject($project)->create();

        $token = CandidateTokenFactory::mintCandidateToken($participant);

        $response = $this->withToken($token)->getJson('/api/candidate/session');
        $response->assertOk();

        expect($response->json('data.id'))->toBeInt();
        expect($response->json('data.project.id'))->toBeInt();
    });

    test('ProjectResource ids, pin_context.id and competencies.*.position are integers', function (): void {
        $org = Organization::factory()->create();
        $token = truthTestAdminUser($org);
        $resolver = app(TenantResolver::class);
        $resolver->setOrgId($org->id);
        $resolver->setBypass(false);
        $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create(['framework_version_id' => $fv->id]);
        $competency = Competency::query()->where('code', 'PRS')->firstOrFail();
        $project->competencies()->attach($competency->id, ['position' => 0]);

        $response = $this->withToken($token)->getJson('/api/projects/'.$project->id);
        $response->assertOk();

        expect($response->json('data.id'))->toBeInt();
        expect($response->json('data.organization_id'))->toBeInt();
        expect($response->json('data.framework_version_id'))->toBeInt();
        expect($response->json('data.pin_context.id'))->toBeInt();
        expect($response->json('data.competencies.0.position'))->toBeInt();
    });

    test('FrameworkVersionResource.id and .organization_id are integers', function (): void {
        $org = Organization::factory()->create();
        $token = truthTestAdminUser($org);
        $resolver = app(TenantResolver::class);
        $resolver->setOrgId($org->id);
        $resolver->setBypass(false);
        FrameworkVersion::factory()->create(['organization_id' => $org->id]);

        $response = $this->withToken($token)->getJson('/api/framework/versions');
        $response->assertOk();

        $row = $response->json('data.0');
        expect($row['id'])->toBeInt();
        expect($row['organization_id'])->toBeInt();
    });

    test('CompetencyResource.id is an integer', function (): void {
        $org = Organization::factory()->create();
        $token = truthTestAdminUser($org);

        $response = $this->withToken($token)->getJson('/api/framework/roles/ICO/competencies');
        $response->assertOk();

        expect($response->json('data.0.id'))->toBeInt();
    });

    test('Admin OrganizationResource.id is an integer', function (): void {
        $org = Organization::factory()->create();
        $token = truthTestAdminUser($org);

        $response = $this->withToken($token)->getJson('/api/organization');
        $response->assertOk();

        expect($response->json('data.id'))->toBeInt();
    });

    test('Admin UserResource.id is an integer', function (): void {
        $org = Organization::factory()->create();
        $token = truthTestAdminUser($org);

        $response = $this->withToken($token)->getJson('/api/users');
        $response->assertOk();

        expect($response->json('data.0.id'))->toBeInt();
    });
});

// ─────────────────────────────────────────────────────────────────────────
// 2.3 — Union RED: status/assessment_type/role are the real value.
// ─────────────────────────────────────────────────────────────────────────

describe('2.3 union fields carry a real allowed value', function (): void {
    test('Participant.status is one of the allowed transition states', function (): void {
        $org = Organization::factory()->create();
        $token = truthTestAdminUser($org);
        $project = truthTestProjectIn($org);
        Participant::factory()->forProject($project)->create(['status' => 'in_corso']);

        $response = $this->withToken($token)->getJson('/api/participants');
        $response->assertOk();

        expect($response->json('data.0.status'))
            ->toBeIn(['in_attesa', 'in_corso', 'in_valutazione', 'completato', 'errore']);
    });

    test('Project.status is one of the allowed lifecycle states', function (): void {
        $org = Organization::factory()->create();
        $token = truthTestAdminUser($org);
        $project = truthTestProjectIn($org);

        $response = $this->withToken($token)->getJson('/api/projects/'.$project->id);
        $response->assertOk();

        expect($response->json('data.status'))->toBeIn(['draft', 'active', 'archived']);
    });

    test('Project.assessment_type is standard or potential (top-level and nested)', function (): void {
        $org = Organization::factory()->create();
        $token = truthTestAdminUser($org);
        $project = truthTestProjectIn($org);

        $response = $this->withToken($token)->getJson('/api/projects/'.$project->id);
        $response->assertOk();
        expect($response->json('data.assessment_type'))->toBeIn(['standard', 'potential']);

        $participant = Participant::factory()->forProject($project)->create();
        $ptoken = CandidateTokenFactory::mintCandidateToken($participant);
        $nested = $this->withToken($ptoken)->getJson('/api/candidate/session');
        $nested->assertOk();
        expect($nested->json('data.project.assessment_type'))->toBeIn(['standard', 'potential']);
    });

    test('Admin UserResource.role is admin, operator, viewer or null', function (): void {
        $org = Organization::factory()->create();
        $token = truthTestAdminUser($org);

        $response = $this->withToken($token)->getJson('/api/users');
        $response->assertOk();

        $role = $response->json('data.0.role');
        expect($role === null || in_array($role, ['admin', 'operator', 'viewer'], true))->toBeTrue();
    });
});

// ─────────────────────────────────────────────────────────────────────────
// 2.4 — Nullability RED: absent/optional fields are present-and-null.
// ─────────────────────────────────────────────────────────────────────────

describe('2.4 nullable fields are present and null, not empty-string/zero', function (): void {
    test('Project role_code/exit_redirect_url/webhook_url/pause_every_n_competencies null when unset', function (): void {
        $org = Organization::factory()->create();
        $token = truthTestAdminUser($org);
        $project = truthTestProjectIn($org, [
            'assessment_type' => 'potential',
            'role_code' => null,
            'exit_redirect_url' => null,
            'webhook_url' => null,
            'pause_every_n_competencies' => null,
        ]);

        $response = $this->withToken($token)->getJson('/api/projects/'.$project->id);
        $response->assertOk();

        $data = $response->json('data');
        expect($data)->toHaveKey('role_code');
        expect($data['role_code'])->toBeNull();
        expect($data)->toHaveKey('exit_redirect_url');
        expect($data['exit_redirect_url'])->toBeNull();
        expect($data)->toHaveKey('webhook_url');
        expect($data['webhook_url'])->toBeNull();
        expect($data)->toHaveKey('pause_every_n_competencies');
        expect($data['pause_every_n_competencies'])->toBeNull();
    });

    test('Organization default_webhook_url and default_webhook_events are null when unset', function (): void {
        $org = Organization::factory()->create([
            'default_webhook_url' => null,
            'default_webhook_events' => null,
        ]);
        $token = truthTestAdminUser($org);

        $response = $this->withToken($token)->getJson('/api/organization');
        $response->assertOk();

        $data = $response->json('data');
        expect($data)->toHaveKey('default_webhook_url');
        expect($data['default_webhook_url'])->toBeNull();
        expect($data)->toHaveKey('default_webhook_events');
        expect($data['default_webhook_events'])->toBeNull();
    });
});
