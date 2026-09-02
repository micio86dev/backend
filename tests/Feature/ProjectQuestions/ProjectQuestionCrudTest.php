<?php

declare(strict_types=1);

/**
 * Predefined questions per project
 * (potential-competencies-and-authored-questions).
 *
 * They were never stored anywhere: the prompt composer derived everything from
 * BARS indicators at runtime, so an operator could not see, edit or reorder a
 * single question — while the binding domain doc has always specified that the
 * first question per competency MAY be predefined for `standard`, and that
 * `potential` asks FOUR predefined ones.
 */

use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Models\User;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/** @return array{user: User, token: string} */
function pqAdmin(Organization $org): array
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return ['user' => $user, 'token' => auth('api')->login($user)];
}

function pqProject(Organization $org): Project
{
    // Both inside the scope: FrameworkVersion is tenant-scoped too, and
    // TenantScoped fails closed rather than guessing an organization.
    return TenantContextScope::runFor($org->id, function () use ($org): Project {
        $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

        return Project::factory()->create([
            'organization_id' => $org->id,
            'framework_version_id' => $fv->id,
        ]);
    });
}

function pqCompetency(): Competency
{
    return Competency::firstOrCreate(
        ['code' => 'PRS'],
        ['name' => ['en' => 'Problem Solving'], 'definition' => ['en' => 'x'], 'type' => 'standard'],
    );
}

test('an admin can author a question for a project competency', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = pqAdmin($org);
    $project = pqProject($org);
    $competency = pqCompetency();

    $response = $this->withToken($token)->postJson("/api/projects/{$project->id}/questions", [
        'competency_id' => $competency->id,
        'text' => ['en' => 'Tell me about a problem you untangled.', 'it' => 'Raccontami un problema che hai districato.'],
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.text.it', 'Raccontami un problema che hai districato.');
    $response->assertJsonPath('data.position', 0);
});

test('questions come back ordered by position', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = pqAdmin($org);
    $project = pqProject($org);
    $competency = pqCompetency();

    foreach (['third', 'first', 'second'] as $i => $label) {
        TenantContextScope::runFor($org->id, fn () => ProjectQuestion::create([
            'project_id' => $project->id,
            'competency_id' => $competency->id,
            'text' => ['en' => $label],
            'position' => [2, 0, 1][$i],
        ]));
    }

    $response = $this->withToken($token)->getJson("/api/projects/{$project->id}/questions");

    $response->assertOk();
    expect(array_column($response->json('data'), 'position'))->toBe([0, 1, 2]);
    expect($response->json('data.0.text.en'))->toBe('first');
});

test('reordering rewrites every position in one call', function (): void {
    // Drag-and-drop sends the whole ordered list. Rewriting the lot in one
    // transaction is what keeps the partial unique index satisfiable — moving
    // one row at a time would collide with the position it is moving into.
    $org = Organization::factory()->create();
    ['token' => $token] = pqAdmin($org);
    $project = pqProject($org);
    $competency = pqCompetency();

    $ids = [];
    foreach ([0, 1, 2] as $p) {
        $ids[] = TenantContextScope::runFor($org->id, fn (): int => ProjectQuestion::create([
            'project_id' => $project->id,
            'competency_id' => $competency->id,
            'text' => ['en' => "q{$p}"],
            'position' => $p,
        ])->id);
    }

    $reversed = array_reverse($ids);

    $this->withToken($token)
        ->putJson("/api/projects/{$project->id}/questions/order", ['ids' => $reversed])
        ->assertOk();

    $response = $this->withToken($token)->getJson("/api/projects/{$project->id}/questions");

    expect(array_column($response->json('data'), 'id'))->toBe($reversed);
});

test('deleting is a SOFT delete, so a conducted interview stays explainable', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = pqAdmin($org);
    $project = pqProject($org);
    $competency = pqCompetency();

    $id = TenantContextScope::runFor($org->id, fn (): int => ProjectQuestion::create([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'text' => ['en' => 'gone'],
        'position' => 0,
    ])->id);

    $this->withToken($token)
        ->deleteJson("/api/projects/{$project->id}/questions/{$id}")
        ->assertNoContent();

    // NOT `withoutGlobalScopes()` — that strips SoftDeletingScope along with
    // the tenant one, so a trashed row comes back and the assertion passes on
    // a HARD delete too, which is the opposite of what this test is for.
    $stillThere = TenantContextScope::runFor($org->id, fn () => [
        'live' => ProjectQuestion::find($id),
        'trashed' => ProjectQuestion::withTrashed()->find($id),
    ]);

    expect($stillThere['live'])->toBeNull()
        ->and($stillThere['trashed'])->not->toBeNull()
        ->and($stillThere['trashed']->deleted_at)->not->toBeNull();
});

test("another organization's project is invisible, not forbidden", function (): void {
    // 404 rather than 403: a 403 would confirm the project exists, which is an
    // existence oracle across tenants. Same doctrine the rest of the API uses.
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    ['token' => $token] = pqAdmin($mine);
    $foreign = pqProject($theirs);

    $this->withToken($token)
        ->getJson("/api/projects/{$foreign->id}/questions")
        ->assertNotFound();
});
